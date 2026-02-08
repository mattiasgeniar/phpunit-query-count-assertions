<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions;

use Closure;
use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\QueryDriverInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\SupportsQueryTimingInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\LaravelDriver;
use Mattiasgeniar\PhpunitQueryCountAssertions\Enums\Severity;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\MySQLAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryIssue;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\SQLiteAnalyser;
use RuntimeException;

trait AssertsQueryCounts
{
    /**
     * The query driver implementation.
     */
    private static ?QueryDriverInterface $driver = null;

    /** @var array<int, array{model: string, relation: string}> */
    private static array $lazyLoadingViolations = [];

    /** @var array<int, array{query: string, bindings: array, issues: array, explain: array}> */
    private static array $indexAnalysisResults = [];

    /** @var array<string, array{count: int, query: string, bindings: array, locations: array<int, array{file: string, line: int}>}> */
    private static array $duplicateQueries = [];

    /**
     * Stack traces for executed queries, keyed by query signature.
     *
     * @var array<string, array<int, array{file: string, line: int}>>
     */
    private static array $queryStackTraces = [];

    /**
     * Current tracking session ID to prevent cross-test pollution.
     */
    private static ?string $currentTrackingSession = null;

    /**
     * Tracked queries across all or specified connections.
     *
     * @var array<int, array{query: string, bindings: array, time: float, connection: string}>
     */
    private static array $trackedQueries = [];

    /**
     * Registered query analysers.
     *
     * @var array<int, QueryAnalyser>
     */
    private static array $queryAnalysers = [];

    /**
     * Set the query driver implementation.
     *
     * Use this to switch between Laravel, Doctrine, Phalcon, or custom drivers.
     */
    public static function useDriver(QueryDriverInterface $driver): void
    {
        self::$driver?->stopListening();
        self::$driver = $driver;
    }

    /**
     * Get the current query driver, auto-detecting Laravel if none is set.
     */
    private static function getDriver(): QueryDriverInterface
    {
        if (self::$driver === null) {
            // Auto-detect Laravel for backwards compatibility
            if (class_exists(DB::class) && DB::getFacadeRoot() !== null) {
                self::$driver = new LaravelDriver;
            } else {
                throw new RuntimeException(
                    'No query driver configured. Call useDriver() with a driver implementation, '
                    . 'or install Laravel for auto-detection.'
                );
            }
        }

        return self::$driver;
    }

    public function assertNoQueriesExecuted(?Closure $closure = null): void
    {
        $this->assertQueryCountMatches(0, $closure);
    }

    public function assertQueryCountMatches(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertEquals($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountLessThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThan($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected fewer than {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountLessThanOrEqual(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThanOrEqual($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected at most {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountGreaterThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThan($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected more than {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountGreaterThanOrEqual(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThanOrEqual($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected at least {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountBetween(int $min, int $max, ?Closure $closure = null): void
    {
        $this->withQueryTracking($closure, function () use ($min, $max) {
            $count = self::getQueryCount();
            $message = $this->formatFailureMessage(
                "Expected between {$min} and {$max} queries, got {$count}."
            );

            $this->assertGreaterThanOrEqual($min, $count, $message);
            $this->assertLessThanOrEqual($max, $count, $message);
        });
    }

    /**
     * Assert that no lazy loading occurs within the closure.
     *
     * This leverages the driver's lazy loading detection mechanism.
     * For Laravel, this uses Model::preventLazyLoading().
     * For drivers that don't support lazy loading detection, the test is marked as skipped.
     *
     * @see https://laravel.com/docs/eloquent-relationships#preventing-lazy-loading
     */
    public function assertNoLazyLoading(Closure $closure): void
    {
        $violations = $this->requireLazyLoadingViolations($closure);

        $this->assertEmpty(
            $violations,
            $this->formatLazyLoadingFailureMessage($violations)
        );
    }

    public function assertLazyLoadingCount(int $expectedCount, Closure $closure): void
    {
        $violations = $this->requireLazyLoadingViolations($closure);

        $this->assertCount(
            $expectedCount,
            $violations,
            $this->formatLazyLoadingFailureMessage(
                $violations,
                "Expected {$expectedCount} lazy loading violations, got " . count($violations) . '.'
            )
        );
    }

    /**
     * Get the lazy loading violations that were collected.
     *
     * @return array<int, array{model: string, relation: string}>
     */
    public static function getLazyLoadingViolations(): array
    {
        return self::$lazyLoadingViolations;
    }

    /**
     * Assert that all queries use indexes (no full table scans).
     *
     * Runs EXPLAIN on each query and checks for full table scans.
     * Supports MySQL and SQLite drivers. Add custom analysers via registerQueryAnalyser().
     */
    public function assertAllQueriesUseIndexes(?Closure $closure = null): void
    {
        $this->withQueryTracking($closure, function () {
            $queries = self::getQueriesExecuted();
            $issues = $this->analyzeQueriesForIndexUsage($queries);

            $this->assertEmpty(
                $issues,
                $this->formatIndexFailureMessage($issues)
            );
        });
    }

    /**
     * Get the index analysis results from the last assertion.
     *
     * @return array<int, array{query: string, issues: array}>
     */
    public static function getIndexAnalysisResults(): array
    {
        return self::$indexAnalysisResults;
    }

    public function assertMaxRowsExamined(int $maxRows, ?Closure $closure = null): void
    {
        $this->withQueryTracking($closure, function () use ($maxRows) {
            $queries = self::getQueriesExecuted();
            $issues = $this->analyzeQueriesForRowCount($queries, $maxRows);

            $this->assertEmpty(
                $issues,
                $this->formatRowCountFailureMessage($issues, $maxRows)
            );
        });
    }

    public function assertNoDuplicateQueries(?Closure $closure = null): void
    {
        $this->withQueryTracking($closure, function () {
            $queries = self::getQueriesExecuted();
            $duplicates = $this->findDuplicateQueries($queries);

            $this->assertEmpty(
                $duplicates,
                $this->formatDuplicateQueryFailureMessage($duplicates)
            );
        });
    }

    public function assertMaxQueryTime(float $maxMilliseconds, ?Closure $closure = null): void
    {
        $this->requireQueryTimingSupport();

        $this->withQueryTracking($closure, function () use ($maxMilliseconds) {
            $queries = self::getQueriesExecuted();
            $slowQueries = $this->findSlowQueries($queries, $maxMilliseconds);

            $this->assertEmpty(
                $slowQueries,
                $this->formatSlowQueryFailureMessage($slowQueries, $maxMilliseconds)
            );
        });
    }

    public function assertTotalQueryTime(float $maxMilliseconds, ?Closure $closure = null): void
    {
        $this->requireQueryTimingSupport();

        $this->withQueryTracking($closure, function () use ($maxMilliseconds) {
            $queries = self::getQueriesExecuted();
            $totalTime = self::getTotalQueryTime();

            $this->assertLessThanOrEqual(
                $maxMilliseconds,
                $totalTime,
                $this->formatTotalTimeFailureMessage($queries, $totalTime, $maxMilliseconds)
            );
        });
    }

    /**
     * @deprecated Use trackQueries() instead. This method will be removed in the next major version.
     */
    public function trackQueriesForEfficiency(): void
    {
        $this->trackQueries();
    }

    private static function resetTrackingState(): void
    {
        self::$driver?->stopListening();

        self::$lazyLoadingViolations = [];
        self::$duplicateQueries = [];
        self::$indexAnalysisResults = [];
        self::$queryStackTraces = [];
        self::$trackedQueries = [];
        self::$currentTrackingSession = null;
    }

    /**
     * Pause tracking temporarily (for internal EXPLAIN queries).
     */
    private static function pauseTracking(): ?string
    {
        $session = self::$currentTrackingSession;
        self::$currentTrackingSession = null;

        return $session;
    }

    /**
     * Resume tracking after pause.
     */
    private static function resumeTracking(?string $session): void
    {
        self::$currentTrackingSession = $session;
    }

    public function assertQueriesAreEfficient(?Closure $closure = null): void
    {
        try {
            if ($closure !== null) {
                $this->trackQueries();
                $closure();
            }

            $queries = self::getQueriesExecuted();

            if (empty($queries) && empty(self::$lazyLoadingViolations)) {
                $this->fail(
                    "No queries were tracked when assertQueriesAreEfficient() was called.\n"
                    . 'Ensure you\'re calling trackQueries() in your test to start query tracking.'
                );
            }

            $issues = [];

            if (! empty(self::$lazyLoadingViolations)) {
                $issues[] = $this->formatLazyLoadingFailureMessage(self::$lazyLoadingViolations);
            }

            $duplicates = $this->findDuplicateQueries($queries);
            if (! empty($duplicates)) {
                $issues[] = $this->formatDuplicateQueryFailureMessage($duplicates);
            }

            $analyser = $this->getQueryAnalyser();
            if ($analyser !== null) {
                $indexIssues = $this->analyzeQueriesForIndexUsage($queries);
                if (! empty($indexIssues)) {
                    $issues[] = $this->formatIndexFailureMessage($indexIssues);
                }
            }

            $this->assertEmpty(
                $issues,
                "Query efficiency issues detected:\n\n" . implode("\n\n---\n\n", $issues)
            );
        } finally {
            self::getDriver()->disableLazyLoadingDetection();
        }
    }

    public static function getTotalQueryTime(): float
    {
        return array_sum(array_column(self::getQueriesExecuted(), 'time'));
    }

    /**
     * Get duplicate queries from the last check.
     *
     * @return array<string, array{count: int, query: string, bindings: array, locations: array<int, array{file: string, line: int}>}>
     */
    public static function getDuplicateQueries(): array
    {
        return self::$duplicateQueries;
    }

    public static function registerQueryAnalyser(QueryAnalyser $analyser): void
    {
        self::$queryAnalysers[] = $analyser;
    }

    private function getDriverName(?string $connectionName = null): string
    {
        return self::getDriver()->getConnection($connectionName)->getDriverName();
    }

    private function getQueryAnalyser(): ?QueryAnalyser
    {
        return $this->getQueryAnalyserForConnection(null);
    }

    private function getQueryAnalyserForConnection(?string $connectionName): ?QueryAnalyser
    {
        $driver = $this->getDriverName($connectionName);

        $analysers = [
            ...self::$queryAnalysers,
            new MySQLAnalyser,
            new SQLiteAnalyser,
        ];

        foreach ($analysers as $analyser) {
            if ($analyser->supports($driver)) {
                return $analyser;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{query: string, bindings?: array, time?: float}>  $queries
     * @return array<string, array{count: int, query: string, bindings: array, locations: array<int, array{file: string, line: int}>}>
     */
    private function findDuplicateQueries(array $queries): array
    {
        $seen = [];

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];
            $key = self::buildQuerySignature($sql, $bindings);

            $seen[$key] ??= ['count' => 0, 'query' => $sql, 'bindings' => $bindings];
            $seen[$key]['count']++;
        }

        self::$duplicateQueries = [];

        foreach ($seen as $key => $data) {
            if ($data['count'] > 1) {
                self::$duplicateQueries[$key] = [...$data, 'locations' => self::$queryStackTraces[$key] ?? []];
            }
        }

        return self::$duplicateQueries;
    }

    private function formatDuplicateQueryFailureMessage(array $duplicates): string
    {
        if (empty($duplicates)) {
            return 'No duplicate queries detected.';
        }

        $message = 'Duplicate queries detected:';

        $index = 0;
        foreach ($duplicates as $data) {
            $index++;
            $message .= "\n\n  {$index}. Executed {$data['count']} times: {$data['query']}";
            $message .= $this->formatQueryDetails(
                $data['bindings'] ?? [],
                $data['locations'] ?? []
            );
        }

        return $message;
    }

    private static function buildQuerySignature(string $sql, array $bindings): string
    {
        return $sql . '|' . json_encode($bindings);
    }

    /**
     * @return array<int, array{file: string, line: int}>
     */
    private function getAllQueryLocations(string $sql, array $bindings): array
    {
        $key = self::buildQuerySignature($sql, $bindings);

        return self::$queryStackTraces[$key] ?? [];
    }

    /**
     * @param  array<string, int>  $offsets
     * @return array<int, array{file: string, line: int}>
     */
    private function takeNextQueryLocation(string $sql, array $bindings, array &$offsets): array
    {
        $key = self::buildQuerySignature($sql, $bindings);
        $offset = $offsets[$key] ?? 0;
        $offsets[$key] = $offset + 1;

        $location = self::$queryStackTraces[$key][$offset] ?? null;

        return $location ? [$location] : [];
    }

    /**
     * @param  array<int, array{file: string, line: int}>  $locations
     * @param  array<int, string>  $extraLines
     */
    private function formatQueryDetails(
        array $bindings,
        array $locations,
        array $extraLines = [],
        int $indent = 5
    ): string {
        $lines = [];
        $indentation = str_repeat(' ', $indent);
        $childIndentation = str_repeat(' ', $indent + 2);

        if (! empty($bindings)) {
            $bindingsText = json_encode($bindings);
            $lines[] = $indentation . "Bindings: {$bindingsText}";
        }

        foreach ($extraLines as $extraLine) {
            $lines[] = $indentation . $extraLine;
        }

        if (! empty($locations)) {
            $lines[] = $indentation . 'Locations:';
            foreach ($locations as $locationIndex => $location) {
                $occurrenceNumber = $locationIndex + 1;
                $file = $this->formatFilePath($location['file']);
                $lines[] = $childIndentation . "#{$occurrenceNumber}: {$file}:{$location['line']}";
            }
        }

        if (empty($lines)) {
            return '';
        }

        return "\n" . implode("\n", $lines);
    }

    /**
     * Format a file path for display, making it relative to the project root if possible.
     */
    private function formatFilePath(string $filePath): string
    {
        // Try to make path relative to common project roots
        $basePaths = [
            self::getDriver()->getBasePath() . DIRECTORY_SEPARATOR,
            getcwd() . DIRECTORY_SEPARATOR,
        ];

        foreach ($basePaths as $basePath) {
            if (str_starts_with($filePath, $basePath)) {
                return substr($filePath, strlen($basePath));
            }
        }

        return $filePath;
    }

    /**
     * @return array<int, array{query: string, bindings: array, time: float, locations: array<int, array{file: string, line: int}>}>
     */
    private function findSlowQueries(array $queries, float $maxMilliseconds): array
    {
        $slowQueries = [];
        $locationOffsets = [];

        foreach ($queries as $query) {
            $time = $query['time'] ?? 0;
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];
            $locations = $this->takeNextQueryLocation($sql, $bindings, $locationOffsets);

            if ($time > $maxMilliseconds) {
                $slowQueries[] = [
                    'query' => $sql,
                    'bindings' => $bindings,
                    'time' => $time,
                    'locations' => $locations,
                ];
            }
        }

        return $slowQueries;
    }

    private function formatSlowQueryFailureMessage(array $slowQueries, float $maxMilliseconds): string
    {
        if (empty($slowQueries)) {
            return "All queries completed within {$maxMilliseconds}ms.";
        }

        $message = "Queries exceeding {$maxMilliseconds}ms:";

        foreach ($slowQueries as $index => $query) {
            $number = $index + 1;
            $time = round($query['time'], 2);
            $message .= "\n\n  {$number}. [{$time}ms] {$query['query']}";
            $message .= $this->formatQueryDetails(
                $query['bindings'] ?? [],
                $query['locations'] ?? []
            );
        }

        return $message;
    }

    private function formatTotalTimeFailureMessage(array $queries, float $totalTime, float $maxMilliseconds): string
    {
        $roundedTotalTime = round($totalTime, 2);
        $message = "Total query time {$roundedTotalTime}ms exceeds budget of {$maxMilliseconds}ms.";
        $message .= "\nQueries executed:";
        $locationOffsets = [];

        foreach ($queries as $index => $query) {
            $number = $index + 1;
            $time = round($query['time'] ?? 0, 2);
            $sql = $query['query'];
            $message .= "\n  {$number}. [{$time}ms] {$sql}";
            $bindings = $query['bindings'] ?? [];
            $message .= $this->formatQueryDetails(
                $bindings,
                $this->takeNextQueryLocation($sql, $bindings, $locationOffsets),
                [],
                6
            );
        }

        return $message;
    }

    private function requireQueryTimingSupport(): void
    {
        $driver = self::getDriver();

        if ($driver instanceof SupportsQueryTimingInterface && ! $driver->supportsQueryTiming()) {
            $this->markTestSkipped(
                'Query timing assertions are not supported by the current driver.'
            );
        }
    }

    private function analyzeQueriesForRowCount(array $queries, int $maxRows): array
    {
        $defaultAnalyser = $this->getQueryAnalyser();

        if ($defaultAnalyser === null || ! $defaultAnalyser->supportsRowCounting()) {
            $driver = $this->getDriverName();
            $this->markTestSkipped("Row count analysis not supported for driver: {$driver}");

            return [];
        }

        $issues = [];
        $locationOffsets = [];

        // Pause tracking to avoid counting internal EXPLAIN queries
        $session = self::pauseTracking();

        try {
            foreach ($queries as $query) {
                $sql = $query['query'];
                $bindings = $query['bindings'] ?? [];
                $connectionName = $query['connection'] ?? null;
                $connection = self::getDriver()->getConnection($connectionName);
                $analyser = $this->getQueryAnalyserForConnection($connectionName) ?? $defaultAnalyser;
                $locations = $this->takeNextQueryLocation($sql, $bindings, $locationOffsets);

                if (! $analyser->canExplain($sql)) {
                    continue;
                }

                $explainResults = $analyser->explain($connection, $sql, $bindings);
                $totalRows = $analyser->getRowsExamined($explainResults);

                if ($totalRows > $maxRows) {
                    $issues[] = [
                        'query' => $sql,
                        'bindings' => $bindings,
                        'rows' => $totalRows,
                        'locations' => $locations,
                    ];
                }
            }
        } finally {
            self::resumeTracking($session);
        }

        return $issues;
    }

    private function formatRowCountFailureMessage(array $issues, int $maxRows): string
    {
        if (empty($issues)) {
            return "All queries examine <= {$maxRows} rows.";
        }

        $message = "Queries examining more than {$maxRows} rows:";

        foreach ($issues as $index => $issue) {
            $number = $index + 1;
            $message .= "\n\n  {$number}. {$issue['query']}";
            $bindings = $issue['bindings'] ?? [];
            $locations = $issue['locations'] ?? $this->getAllQueryLocations($issue['query'], $bindings);
            $message .= $this->formatQueryDetails(
                $bindings,
                $locations,
                ["Rows examined: {$issue['rows']}"]
            );
        }

        return $message;
    }

    /**
     * Analyze queries for index usage.
     *
     * @param  Severity  $minSeverity  Minimum severity level to report
     * @return array<int, array{query: string, bindings: array, issues: array<int, QueryIssue>, locations: array<int, array{file: string, line: int}>}>
     */
    private function analyzeQueriesForIndexUsage(array $queries, Severity $minSeverity = Severity::Warning): array
    {
        $defaultAnalyser = $this->getQueryAnalyser();

        if ($defaultAnalyser === null) {
            $driver = $this->getDriverName();
            $this->markTestSkipped("Index analysis not supported for driver: {$driver}. See registerQueryAnalyser() to add support.");

            return [];
        }

        self::$indexAnalysisResults = [];
        $issues = [];
        $infoIssues = [];
        $locationOffsets = [];

        // Pause tracking to avoid counting internal EXPLAIN queries
        $session = self::pauseTracking();

        try {
            foreach ($queries as $query) {
                $sql = $query['query'];
                $bindings = $query['bindings'] ?? [];
                $connectionName = $query['connection'] ?? null;
                $connection = self::getDriver()->getConnection($connectionName);
                $analyser = $this->getQueryAnalyserForConnection($connectionName) ?? $defaultAnalyser;
                $locations = $this->takeNextQueryLocation($sql, $bindings, $locationOffsets);

                if (! $analyser->canExplain($sql)) {
                    continue;
                }

                $explainResults = $analyser->explain($connection, $sql, $bindings);
                $queryIssues = $analyser->analyzeIndexUsage($explainResults, $sql, $connection);

                $informationalIssues = array_filter(
                    $queryIssues,
                    fn (QueryIssue $issue) => $issue->severity === Severity::Info
                );

                if (! empty($informationalIssues)) {
                    $infoIssues[] = [
                        'query' => $sql,
                        'bindings' => $bindings,
                        'issues' => $informationalIssues,
                        'locations' => $locations,
                    ];
                }

                // Filter by severity
                $filteredIssues = array_filter(
                    $queryIssues,
                    fn (QueryIssue $issue) => $issue->meetsThreshold($minSeverity)
                );

                self::$indexAnalysisResults[] = [
                    'query' => $sql,
                    'bindings' => $bindings,
                    'issues' => $queryIssues,
                    'explain' => $explainResults,
                ];

                if (! empty($filteredIssues)) {
                    $issues[] = [
                        'query' => $sql,
                        'bindings' => $bindings,
                        'issues' => $filteredIssues,
                        'locations' => $locations,
                    ];
                }
            }
        } finally {
            self::resumeTracking($session);
        }

        $this->reportIndexInfoIssues($infoIssues);

        return $issues;
    }

    /**
     * @param  array<int, array{query: string, bindings: array, issues: array<int, QueryIssue>, locations?: array<int, array{file: string, line: int}>}>  $issues
     */
    private function formatIndexIssuesMessage(array $issues, string $header, string $emptyMessage): string
    {
        if (empty($issues)) {
            return $emptyMessage;
        }

        $message = $header;

        foreach ($issues as $index => $issue) {
            $number = $index + 1;
            $sql = $issue['query'];
            $message .= "\n\n  {$number}. {$sql}";
            $bindings = $issue['bindings'] ?? [];
            $locations = $issue['locations'] ?? $this->getAllQueryLocations($sql, $bindings);
            $extraLines = ['Issues:'];

            foreach ($issue['issues'] as $queryIssue) {
                $severityLabel = strtoupper($queryIssue->severity->value);
                $extraLines[] = "  - [{$severityLabel}] {$queryIssue}";
            }

            $message .= $this->formatQueryDetails(
                $bindings,
                $locations,
                $extraLines
            );
        }

        return $message;
    }

    /**
     * @param  array<int, array{query: string, bindings: array, issues: array<int, QueryIssue>, locations?: array<int, array{file: string, line: int}>}>  $issues
     */
    private function reportIndexInfoIssues(array $issues): void
    {
        if (empty($issues)) {
            return;
        }

        $message = $this->formatIndexIssuesMessage(
            $issues,
            'Query index info (non-failing):',
            ''
        );

        $this->emitInfoOutput($message);
    }

    private function emitInfoOutput(string $message): void
    {
        $output = PHP_EOL . '[INFO] ' . $message . PHP_EOL;

        if (defined('STDERR')) {
            fwrite(STDERR, $output);
        } else {
            echo $output;
        }
    }

    /**
     * @param  array<int, array{query: string, bindings: array, issues: array<int, QueryIssue>, locations?: array<int, array{file: string, line: int}>}>  $issues
     */
    private function formatIndexFailureMessage(array $issues): string
    {
        return $this->formatIndexIssuesMessage(
            $issues,
            'Queries with index issues detected:',
            'All queries use indexes.'
        );
    }

    /**
     * Collect lazy loading violations, skipping the test if the driver doesn't support it.
     *
     * @return array<int, array{model: string, relation: string}>
     */
    private function requireLazyLoadingViolations(Closure $closure): array
    {
        $violations = $this->collectLazyLoadingViolations($closure);

        if ($violations === null) {
            $this->markTestSkipped(
                'Lazy loading detection is not supported by the current driver. '
                . 'This feature requires Laravel with Eloquent ORM.'
            );

            return [];
        }

        return $violations;
    }

    /**
     * Collect lazy loading violations.
     *
     * @return array<int, array{model: string, relation: string}>|null Returns null if not supported
     */
    private function collectLazyLoadingViolations(Closure $closure): ?array
    {
        self::$lazyLoadingViolations = [];

        $supported = self::getDriver()->enableLazyLoadingDetection(function (array $violation) {
            self::$lazyLoadingViolations[] = $violation;
        });

        if (! $supported) {
            return null;
        }

        try {
            $closure();
        } finally {
            self::getDriver()->disableLazyLoadingDetection();
        }

        return self::$lazyLoadingViolations;
    }

    private function formatLazyLoadingFailureMessage(array $violations, ?string $prefix = null): string
    {
        if (empty($violations)) {
            return $prefix ?? 'No lazy loading violations detected.';
        }

        $message = $prefix ?? 'Lazy loading violations detected:';
        $message .= "\nViolations:";

        foreach ($violations as $index => $violation) {
            $number = $index + 1;
            $message .= "\n  {$number}. {$violation['model']}::\${$violation['relation']}";
        }

        return $message;
    }

    private function withQueryTracking(?Closure $closure, callable $assertion): void
    {
        if ($closure === null) {
            $assertion();

            return;
        }

        $this->trackQueries();
        try {
            $closure();
            $assertion();
        } finally {
            self::$currentTrackingSession = null;
            self::getDriver()->disableLazyLoadingDetection();
        }
    }

    private function formatFailureMessage(string $message): string
    {
        $queries = self::getQueriesExecuted();

        if (empty($queries)) {
            return $message . "\nNo queries were executed.";
        }

        $formatted = $message . "\nQueries executed:";

        $locationOffsets = [];

        foreach ($queries as $index => $query) {
            $number = $index + 1;
            $sql = $query['query'];
            $time = round($query['time'], 2);

            $formatted .= "\n  {$number}. [{$time}ms] {$sql}";
            $bindings = $query['bindings'] ?? [];
            $formatted .= $this->formatQueryDetails(
                $bindings,
                $this->takeNextQueryLocation($sql, $bindings, $locationOffsets),
                [],
                6
            );
        }

        return $formatted;
    }

    /**
     * Start tracking database queries across one or more connections.
     *
     * @param  array<string>|string|null  $connections  Connection name(s) to track, or null to track all connections
     */
    public function trackQueries(array|string|null $connections = null): void
    {
        self::resetTrackingState();

        $connectionsArray = is_string($connections) ? [$connections] : $connections;
        self::$currentTrackingSession = uniqid('tracking_', true);

        $driver = self::getDriver();
        $skipPatterns = $driver->getStackTraceSkipPatterns();

        $driver->startListening(function (array $query) use ($skipPatterns) {
            if (self::$currentTrackingSession === null) {
                return;
            }

            self::$trackedQueries[] = $query;

            $key = self::buildQuerySignature($query['query'], $query['bindings']);
            $trace = self::captureRelevantStackTrace($skipPatterns);

            self::$queryStackTraces[$key] ??= [];
            self::$queryStackTraces[$key][] = $trace;
        }, $connectionsArray);

        $driver->enableLazyLoadingDetection(function (array $violation) {
            self::$lazyLoadingViolations[] = $violation;
        });
    }

    /**
     * Capture a stack trace filtered to show only relevant application code.
     *
     * @param  array<int, string>  $skipPatterns  Regex patterns to skip
     * @return array{file: string, line: int}
     */
    private static function captureRelevantStackTrace(array $skipPatterns): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
        $fallback = ['file' => 'unknown', 'line' => 0];

        foreach ($trace as $frame) {
            if (! isset($frame['file'], $frame['line'])) {
                continue;
            }

            // Capture first valid frame as fallback in case all frames are internal
            if ($fallback['file'] === 'unknown') {
                $fallback = ['file' => $frame['file'], 'line' => $frame['line']];
            }

            $file = $frame['file'];
            $isInternal = false;

            foreach ($skipPatterns as $pattern) {
                if (preg_match($pattern, $file)) {
                    $isInternal = true;
                    break;
                }
            }

            if (! $isInternal) {
                return ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return $fallback;
    }

    /**
     * @return array<int, array{query: string, bindings: array, time: float, connection: string}>
     */
    public static function getQueriesExecuted(): array
    {
        return self::$trackedQueries;
    }

    public static function getQueryCount(): int
    {
        return count(self::getQueriesExecuted());
    }
}
