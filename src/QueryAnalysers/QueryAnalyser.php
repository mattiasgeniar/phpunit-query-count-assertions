<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Illuminate\Database\Connection;

interface QueryAnalyser
{
    /**
     * Check if this analyser supports the given database driver.
     */
    public function supports(string $driver): bool;

    /**
     * Run EXPLAIN on a query and return the raw results.
     *
     * @return array<int, object>
     */
    public function explain(Connection $connection, string $sql, array $bindings): array;

    /**
     * Analyze EXPLAIN results and return any index-related issues found.
     *
     * @param  array<int, object>  $explainResults
     * @return array<int, string> List of issues (e.g., "Full table scan on 'users'")
     */
    public function analyzeIndexUsage(array $explainResults): array;

    /**
     * Check if this analyser supports row count estimation.
     */
    public function supportsRowCounting(): bool;

    /**
     * Get the estimated number of rows examined from EXPLAIN results.
     *
     * @param  array<int, object>  $explainResults
     */
    public function getRowsExamined(array $explainResults): int;
}
