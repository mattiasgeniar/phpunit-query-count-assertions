<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\Enums\Severity;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\MySQLAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryIssue;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\SQLiteAnalyser;
use PDO;
use ReflectionProperty;

trait AssertsQueryCounts
{
    private static array $lazyLoadingViolations = [];

    /**
     * Snapshot of lazy loading state to restore after efficiency tracking.
     *
     * @var array{prevention: bool, callback: callable|null}|null
     */
    private static ?array $lazyLoadingState = null;

    private static array $indexAnalysisResults = [];

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
     * Connection ID where the stack trace listener was registered.
     */
    private static ?int $stackTraceListenerConnectionId = null;

    /**
     * Connection name being tracked, to avoid cross-connection noise.
     */
    private static ?string $trackingConnectionName = null;

    /**
     * Registered query analysers.
     *
     * @var array<int, QueryAnalyser>
     */
    private static array $queryAnalysers = [];

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
     * This leverages Laravel's built-in lazy loading prevention to detect N+1 queries.
     *
     * @see https://laravel.com/docs/eloquent-relationships#preventing-lazy-loading
     */
    public function assertNoLazyLoading(Closure $closure): void
    {
        $violations = $this->collectLazyLoadingViolations($closure);

        $this->assertEmpty(
            $violations,
            $this->formatLazyLoadingFailureMessage($violations)
        );
    }

    public function assertLazyLoadingCount(int $expectedCount, Closure $closure): void
    {
        $violations = $this->collectLazyLoadingViolations($closure);

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
        $this->withQueryTracking($closure, function () use ($maxMilliseconds) {
            $queries = self::getQueriesExecuted();
            $totalTime = $this->calculateTotalQueryTime($queries);

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

    private static function resetEfficiencyTracking(): void
    {
        self::$lazyLoadingViolations = [];
        self::$duplicateQueries = [];
        self::$indexAnalysisResults = [];
        self::$queryStackTraces = [];
        self::$trackingConnectionName = null;
        self::$currentTrackingSession = null;
    }

    private static function enableLazyLoadingTracking(): void
    {
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(self::lazyLoadingViolationHandler());
    }

    private static function captureLazyLoadingState(): void
    {
        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        self::$lazyLoadingState = [
            'prevention' => $preventionProperty->getValue(null),
            'callback' => $callbackProperty->getValue(null),
        ];
    }

    private static function restoreLazyLoadingState(): void
    {
        if (self::$lazyLoadingState === null) {
            return;
        }

        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        $preventionProperty->setValue(null, self::$lazyLoadingState['prevention']);
        $callbackProperty->setValue(null, self::$lazyLoadingState['callback']);
        self::$lazyLoadingState = null;
    }

    private static function lazyLoadingViolationHandler(): Closure
    {
        return function (Model $model, string $relation): void {
            self::$lazyLoadingViolations[] = [
                'model' => $model::class,
                'relation' => $relation,
            ];
        };
    }

    public function assertQueriesAreEfficient(?Closure $closure = null): void
    {
        try {
            if ($closure !== null) {
                $this->trackQueries();
                $closure();
            }

            $queries = self::getQueriesExecuted();
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

            DB::flushQueryLog();

            $this->assertEmpty(
                $issues,
                "Query efficiency issues detected:\n\n" . implode("\n\n---\n\n", $issues)
            );
        } finally {
            self::restoreLazyLoadingState();
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

    private function getDriverName(): string
    {
        return DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function getQueryAnalyser(): ?QueryAnalyser
    {
        $driver = $this->getDriverName();

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

    private function findDuplicateQueries(array $queries): array
    {
        $seen = [];
        self::$duplicateQueries = [];

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];
            $key = self::buildQuerySignature($sql, $bindings);

            if (! isset($seen[$key])) {
                $seen[$key] = [
                    'count' => 0,
                    'query' => $sql,
                    'bindings' => $bindings,
                ];
            }
            $seen[$key]['count']++;
        }

        foreach ($seen as $key => $data) {
            if ($data['count'] > 1) {
                $data['locations'] = self::$queryStackTraces[$key] ?? [];
                self::$duplicateQueries[$key] = $data;
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
            base_path() . DIRECTORY_SEPARATOR,
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

    private function calculateTotalQueryTime(array $queries): float
    {
        return array_sum(array_column($queries, 'time'));
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

    private function analyzeQueriesForRowCount(array $queries, int $maxRows): array
    {
        $analyser = $this->getQueryAnalyser();

        if ($analyser === null || ! $analyser->supportsRowCounting()) {
            $driver = $this->getDriverName();
            $this->markTestSkipped("Row count analysis not supported for driver: {$driver}");

            return [];
        }

        $issues = [];
        $connection = DB::connection();
        $locationOffsets = [];

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];
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
        $analyser = $this->getQueryAnalyser();

        if ($analyser === null) {
            $driver = $this->getDriverName();
            $this->markTestSkipped("Index analysis not supported for driver: {$driver}. See registerQueryAnalyser() to add support.");

            return [];
        }

        self::$indexAnalysisResults = [];
        $issues = [];
        $connection = DB::connection();
        $locationOffsets = [];

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];
            $locations = $this->takeNextQueryLocation($sql, $bindings, $locationOffsets);

            if (! $analyser->canExplain($sql)) {
                continue;
            }

            $explainResults = $analyser->explain($connection, $sql, $bindings);
            $queryIssues = $analyser->analyzeIndexUsage($explainResults, $sql, $connection);

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

        return $issues;
    }

    /**
     * @param  array<int, array{query: string, bindings: array, issues: array<int, QueryIssue>, locations?: array<int, array{file: string, line: int}>}>  $issues
     */
    private function formatIndexFailureMessage(array $issues): string
    {
        if (empty($issues)) {
            return 'All queries use indexes.';
        }

        $message = 'Queries with index issues detected:';

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

    private function collectLazyLoadingViolations(Closure $closure): array
    {
        self::$lazyLoadingViolations = [];

        $this->withLazyLoadingTracking($closure);

        return self::$lazyLoadingViolations;
    }

    private function withLazyLoadingTracking(Closure $closure): void
    {
        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        $originalPrevention = $preventionProperty->getValue(null);
        $originalCallback = $callbackProperty->getValue(null);

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(self::lazyLoadingViolationHandler());

        try {
            $closure();
        } finally {
            $preventionProperty->setValue(null, $originalPrevention);
            $callbackProperty->setValue(null, $originalCallback);
        }
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
        $closure();
        $assertion();
        DB::flushQueryLog();
        self::$currentTrackingSession = null;
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

    public function trackQueries(): void
    {
        self::resetEfficiencyTracking();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $connection = DB::connection();
        self::$trackingConnectionName = $connection->getName();
        self::$currentTrackingSession = uniqid('tracking_', true);
        self::enableStackTraceCapture();
        self::captureLazyLoadingState();
        self::enableLazyLoadingTracking();
    }

    private static function enableStackTraceCapture(): void
    {
        $connection = DB::connection();
        $connectionId = spl_object_id($connection);

        if (self::$stackTraceListenerConnectionId === $connectionId) {
            return;
        }

        self::$stackTraceListenerConnectionId = $connectionId;

        $connection->listen(function ($query) {
            if (self::$currentTrackingSession === null) {
                return;
            }

            $connectionName = $query->connectionName ?? null;
            if (self::$trackingConnectionName !== null && $connectionName !== self::$trackingConnectionName) {
                return;
            }

            $key = self::buildQuerySignature($query->sql, $query->bindings);
            $trace = self::captureRelevantStackTrace();

            if (! isset(self::$queryStackTraces[$key])) {
                self::$queryStackTraces[$key] = [];
            }

            self::$queryStackTraces[$key][] = $trace;
        });
    }

    /**
     * Capture a stack trace filtered to show only relevant application code.
     *
     * @return array{file: string, line: int}
     */
    private static function captureRelevantStackTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

        $skipPatterns = [
            '/vendor\/laravel\/framework/',
            '/vendor\/illuminate/',
            '/AssertsQueryCounts\.php$/',
            '/vendor\/phpunit/',
        ];

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

    public static function getQueriesExecuted(): array
    {
        return DB::getQueryLog();
    }

    public static function getQueryCount(): int
    {
        return count(self::getQueriesExecuted());
    }
}
