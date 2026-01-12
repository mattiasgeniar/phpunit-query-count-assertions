<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Illuminate\Database\Connection;

class SQLiteAnalyser implements QueryAnalyser
{
    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function explain(Connection $connection, string $sql, array $bindings): array
    {
        return $connection->select('EXPLAIN QUERY PLAN ' . $sql, $bindings);
    }

    public function analyzeIndexUsage(array $explainResults): array
    {
        $issues = [];

        foreach ($explainResults as $row) {
            $row = (array) $row;
            $detail = $row['detail'] ?? '';

            // SQLite EXPLAIN QUERY PLAN output:
            // "SCAN users" = full table scan (bad)
            // "SCAN CONSTANT ROW" = constant expression optimization (good, not a real table)
            // "SEARCH users USING INDEX" = using index (good)
            // "SEARCH users USING INTEGER PRIMARY KEY" = using primary key (good)
            if (preg_match('/^SCAN (\w+)/', $detail, $matches)) {
                $table = $matches[1];

                // CONSTANT is not a real table - it's SQLite's optimization for
                // constant expressions (e.g., EXISTS subqueries, scalar subqueries)
                if (strcasecmp($table, 'CONSTANT') === 0) {
                    continue;
                }

                $issues[] = "Full table scan on '{$table}'";
            }
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
}
