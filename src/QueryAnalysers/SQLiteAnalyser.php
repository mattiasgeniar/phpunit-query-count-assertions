<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Illuminate\Database\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns\ExplainsQueries;

class SQLiteAnalyser implements QueryAnalyser
{
    use ExplainsQueries;

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function explain(Connection $connection, string $sql, array $bindings): array
    {
        return $connection->select('EXPLAIN QUERY PLAN ' . $sql, $bindings);
    }

    /**
     * @return array<int, QueryIssue>
     */
    public function analyzeIndexUsage(array $explainResults): array
    {
        $issues = [];

        foreach ($explainResults as $row) {
            $row = (array) $row;
            $detail = $row['detail'] ?? '';

            $issues = [...$issues, ...$this->analyzeDetailString($detail)];
        }

        return $issues;
    }

    public function supportsRowCounting(): bool
    {
        return false;
    }

    public function getRowsExamined(array $explainResults): int
    {
        // SQLite's EXPLAIN QUERY PLAN doesn't provide row estimates
        return 0;
    }

    /**
     * Analyze a SQLite EXPLAIN QUERY PLAN detail string.
     *
     * SQLite EXPLAIN QUERY PLAN output patterns:
     * - "SCAN users" = full table scan (bad)
     * - "SCAN CONSTANT ROW" = constant expression optimization (not a real table)
     * - "SEARCH users USING INDEX" = using index (good)
     * - "SEARCH users USING COVERING INDEX" = covering index (best)
     * - "USE TEMP B-TREE FOR ORDER BY" = sorting without index
     *
     * @return array<int, QueryIssue>
     */
    protected function analyzeDetailString(string $detail): array
    {
        $issues = [];

        // Check for full table scan (but not CONSTANT which is an optimization)
        if (preg_match('/^SCAN (\w+)/', $detail, $matches)) {
            $table = $matches[1];

            if (strcasecmp($table, 'CONSTANT') !== 0) {
                $issues[] = QueryIssue::error(
                    message: "Full table scan on '{$table}'",
                    table: $table,
                );
            }
        }

        // Check for temporary B-tree usage
        $tempBTreeOperations = [
            'ORDER BY' => 'Using temporary B-tree for ORDER BY (consider adding index)',
            'DISTINCT' => 'Using temporary B-tree for DISTINCT',
            'GROUP BY' => 'Using temporary B-tree for GROUP BY',
        ];

        foreach ($tempBTreeOperations as $operation => $message) {
            if (str_contains($detail, "USE TEMP B-TREE FOR {$operation}")) {
                $issues[] = QueryIssue::warning(message: $message, table: null);
            }
        }

        // Check for co-routine subqueries
        if (preg_match('/CO-ROUTINE (\w+)/', $detail, $matches)) {
            $issues[] = QueryIssue::info(
                message: "Using co-routine for subquery '{$matches[1]}'",
                table: $matches[1],
            );
        }

        return $issues;
    }
}
