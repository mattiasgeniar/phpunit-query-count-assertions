<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\MySQLAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\SQLiteAnalyser;
use PDO;
use ReflectionProperty;

trait AssertsQueryCounts
{
    private static array $lazyLoadingViolations = [];

    private static array $indexAnalysisResults = [];

    private static array $duplicateQueries = [];

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

    /**
     * Assert that lazy loading occurs a specific number of times within the closure.
     */
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

    /**
     * Assert that no query examines more than the specified number of rows.
     *
     * Runs EXPLAIN on each query and checks the estimated rows examined.
     * Only supported on databases where the analyser supports row counting.
     */
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

    /**
     * Assert that no duplicate queries are executed.
     *
     * Detects when the exact same query (with same bindings) is executed multiple times.
     */
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

    /**
     * Assert that no single query exceeds the specified execution time.
     *
     * @param  float  $maxMilliseconds  Maximum allowed time for any single query
     */
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

    /**
     * Assert that total query execution time doesn't exceed the specified budget.
     *
     * @param  float  $maxMilliseconds  Maximum allowed total time for all queries
     */
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
     * Get the total execution time of all tracked queries in milliseconds.
     */
    public static function getTotalQueryTime(): float
    {
        $queries = self::getQueriesExecuted();
        $total = 0.0;

        foreach ($queries as $query) {
            $total += $query['time'] ?? 0;
        }

        return $total;
    }

    /**
     * Get duplicate queries from the last check.
     *
     * @return array<string, array{count: int, query: string, bindings: array}>
     */
    public static function getDuplicateQueries(): array
    {
        return self::$duplicateQueries;
    }

    /**
     * Register a custom query analyser.
     *
     * Use this to add support for additional database drivers.
     */
    public static function registerQueryAnalyser(QueryAnalyser $analyser): void
    {
        self::$queryAnalysers[] = $analyser;
    }

    /**
     * Get the current database driver name.
     */
    private function getDriverName(): string
    {
        return DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Get the query analyser for the current database driver.
     */
    private function getQueryAnalyser(): ?QueryAnalyser
    {
        $driver = $this->getDriverName();

        // Check custom analysers first
        foreach (self::$queryAnalysers as $analyser) {
            if ($analyser->supports($driver)) {
                return $analyser;
            }
        }

        // Fall back to built-in analysers
        $builtInAnalysers = [
            new MySQLAnalyser,
            new SQLiteAnalyser,
        ];

        foreach ($builtInAnalysers as $analyser) {
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

            // Create a unique key for this query+bindings combination
            $key = $sql . '|' . json_encode($bindings);

            if (! isset($seen[$key])) {
                $seen[$key] = [
                    'count' => 0,
                    'query' => $sql,
                    'bindings' => $bindings,
                ];
            }
            $seen[$key]['count']++;
        }

        // Filter to only duplicates (count > 1)
        foreach ($seen as $key => $data) {
            if ($data['count'] > 1) {
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

            if (! empty($data['bindings'])) {
                $bindings = json_encode($data['bindings']);
                $message .= "\n     Bindings: {$bindings}";
            }
        }

        return $message;
    }

    /**
     * @return array<int, array{query: string, bindings: array, time: float}>
     */
    private function findSlowQueries(array $queries, float $maxMilliseconds): array
    {
        $slowQueries = [];

        foreach ($queries as $query) {
            $time = $query['time'] ?? 0;

            if ($time > $maxMilliseconds) {
                $slowQueries[] = [
                    'query' => $query['query'],
                    'bindings' => $query['bindings'] ?? [],
                    'time' => $time,
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

            if (! empty($query['bindings'])) {
                $bindings = json_encode($query['bindings']);
                $message .= "\n     Bindings: {$bindings}";
            }
        }

        return $message;
    }

    private function calculateTotalQueryTime(array $queries): float
    {
        $total = 0.0;

        foreach ($queries as $query) {
            $total += $query['time'] ?? 0;
        }

        return $total;
    }

    private function formatTotalTimeFailureMessage(array $queries, float $totalTime, float $maxMilliseconds): string
    {
        $roundedTotalTime = round($totalTime, 2);
        $message = "Total query time {$roundedTotalTime}ms exceeds budget of {$maxMilliseconds}ms.";
        $message .= "\nQueries executed:";

        foreach ($queries as $index => $query) {
            $number = $index + 1;
            $time = round($query['time'] ?? 0, 2);
            $sql = $query['query'];
            $message .= "\n  {$number}. [{$time}ms] {$sql}";

            if (! empty($query['bindings'])) {
                $bindings = json_encode($query['bindings']);
                $message .= "\n      Bindings: {$bindings}";
            }
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

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];

            // Only analyze SELECT queries
            if (! $this->isSelectQuery($sql)) {
                continue;
            }

            $explainResults = $analyser->explain($connection, $sql, $bindings);
            $totalRows = $analyser->getRowsExamined($explainResults);

            if ($totalRows > $maxRows) {
                $issues[] = [
                    'query' => $sql,
                    'bindings' => $bindings,
                    'rows' => $totalRows,
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

            if (! empty($issue['bindings'])) {
                $bindings = json_encode($issue['bindings']);
                $message .= "\n     Bindings: {$bindings}";
            }

            $message .= "\n     Rows examined: {$issue['rows']}";
        }

        return $message;
    }

    /**
     * Analyze queries for index usage.
     *
     * @return array<int, array{query: string, bindings: array, issues: array, explain: array}>
     */
    private function analyzeQueriesForIndexUsage(array $queries): array
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

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = $query['bindings'] ?? [];

            // Only analyze SELECT queries
            if (! $this->isSelectQuery($sql)) {
                continue;
            }

            $explainResults = $analyser->explain($connection, $sql, $bindings);
            $queryIssues = $analyser->analyzeIndexUsage($explainResults);

            self::$indexAnalysisResults[] = [
                'query' => $sql,
                'bindings' => $bindings,
                'issues' => $queryIssues,
                'explain' => $explainResults,
            ];

            if (! empty($queryIssues)) {
                $issues[] = [
                    'query' => $sql,
                    'bindings' => $bindings,
                    'issues' => $queryIssues,
                ];
            }
        }

        return $issues;
    }

    private function isSelectQuery(string $sql): bool
    {
        return stripos(trim($sql), 'select') === 0;
    }

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

            if (! empty($issue['bindings'])) {
                $bindings = json_encode($issue['bindings']);
                $message .= "\n     Bindings: {$bindings}";
            }

            $message .= "\n     Issues:";
            foreach ($issue['issues'] as $issueDetail) {
                $message .= "\n       - {$issueDetail}";
            }
        }

        return $message;
    }

    private function collectLazyLoadingViolations(Closure $closure): array
    {
        self::$lazyLoadingViolations = [];

        // Store original state using reflection
        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        $originalPrevention = $preventionProperty->getValue(null);
        $originalCallback = $callbackProperty->getValue(null);

        // Enable lazy loading prevention with our collector
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            self::$lazyLoadingViolations[] = [
                'model' => $model::class,
                'relation' => $relation,
            ];
        });

        try {
            $closure();
        } finally {
            // Restore original state
            $preventionProperty->setValue(null, $originalPrevention);
            $callbackProperty->setValue(null, $originalCallback);
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

        self::trackQueries();
        $closure();
        $assertion();
        DB::flushQueryLog();
    }

    private function formatFailureMessage(string $message): string
    {
        $queries = self::getQueriesExecuted();

        if (empty($queries)) {
            return $message . "\nNo queries were executed.";
        }

        $formatted = $message . "\nQueries executed:";

        foreach ($queries as $index => $query) {
            $number = $index + 1;
            $sql = $query['query'];
            $time = round($query['time'], 2);

            $formatted .= "\n  {$number}. [{$time}ms] {$sql}";

            if (! empty($query['bindings'])) {
                $bindings = json_encode($query['bindings']);
                $formatted .= "\n      Bindings: {$bindings}";
            }
        }

        return $formatted;
    }

    public static function trackQueries(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
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
