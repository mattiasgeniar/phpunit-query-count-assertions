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
     * Check if a SQL query can be analyzed with EXPLAIN.
     *
     * Typically SELECT, UPDATE, DELETE, and INSERT...SELECT queries.
     */
    public function canExplain(string $sql): bool;

    /**
     * Run EXPLAIN on a query and return the raw results.
     *
     * The format varies by database:
     * - MySQL (tabular): array of row objects
     * - MySQL (JSON): associative array with 'query_block' key
     * - SQLite: array of row objects with 'detail' field
     *
     * @return array<array-key, mixed>
     */
    public function explain(Connection $connection, string $sql, array $bindings): array;

    /**
     * Analyze EXPLAIN results and return any performance issues found.
     *
     * @param  array<array-key, mixed>  $explainResults
     * @return array<int, QueryIssue>
     */
    public function analyzeIndexUsage(array $explainResults): array;

    /**
     * Check if this analyser supports row count estimation.
     */
    public function supportsRowCounting(): bool;

    /**
     * Get the estimated number of rows examined from EXPLAIN results.
     *
     * @param  array<array-key, mixed>  $explainResults
     */
    public function getRowsExamined(array $explainResults): int;
}
