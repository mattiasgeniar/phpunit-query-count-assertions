<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Illuminate\Database\Connection;

class MySQLAnalyser implements QueryAnalyser
{
    public function supports(string $driver): bool
    {
        return $driver === 'mysql';
    }

    public function explain(Connection $connection, string $sql, array $bindings): array
    {
        return $connection->select('EXPLAIN ' . $sql, $bindings);
    }

    public function analyzeIndexUsage(array $explainResults): array
    {
        $issues = [];

        foreach ($explainResults as $row) {
            $row = (array) $row;
            $table = $row['table'] ?? 'unknown';

            // type=ALL means full table scan
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $issues[] = "Full table scan on '{$table}'";
            }

            // No key used when possible keys exist
            if (! empty($row['possible_keys']) && empty($row['key'])) {
                $issues[] = "Index available but not used on '{$table}'";
            }

            // Check Extra column for various issues
            if (isset($row['Extra'])) {
                $extra = $row['Extra'];

                if (str_contains($extra, 'Using filesort')) {
                    $issues[] = "Using filesort on '{$table}'";
                }
                if (str_contains($extra, 'Using temporary')) {
                    $issues[] = "Using temporary table on '{$table}'";
                }
                if (str_contains($extra, 'Using join buffer')) {
                    $issues[] = "Using join buffer on '{$table}' (missing index for join)";
                }
                if (str_contains($extra, 'Full scan on NULL key')) {
                    $issues[] = "Full scan on NULL key on '{$table}'";
                }
            }
        }

        return $issues;
    }

    public function supportsRowCounting(): bool
    {
        return true;
    }

    public function getRowsExamined(array $explainResults): int
    {
        $totalRows = 0;

        foreach ($explainResults as $row) {
            $row = (array) $row;

            if (isset($row['rows'])) {
                $totalRows += (int) $row['rows'];
            }
        }

        return $totalRows;
    }
}
