<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns\ExplainsQueries;

class SQLiteAnalyser implements QueryAnalyser
{
    use ExplainsQueries;

    public function supports(string $driver): bool
    {
        return $driver === 'sqlite';
    }

    public function explain(ConnectionInterface $connection, string $sql, array $bindings): array
    {
        return $connection->select('EXPLAIN QUERY PLAN ' . $sql, $bindings);
    }

    /**
     * @return array<int, QueryIssue>
     */
    public function analyzeIndexUsage(array $explainResults, ?string $sql = null, ?ConnectionInterface $connection = null): array
    {
        $issues = [];
        $targetTable = $this->extractTargetTable($sql);
        $queryType = $this->extractQueryType($sql);

        foreach ($explainResults as $row) {
            $row = (array) $row;
            $detail = $row['detail'] ?? '';

            $rowIssues = $this->analyzeDetailString(
                $detail,
                $targetTable,
                $queryType,
                $connection
            );

            $issues = [...$issues, ...$rowIssues];
        }

        return $this->deduplicateIssues($issues);
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
     * Extract the target table from a DELETE or UPDATE query.
     */
    protected function extractTargetTable(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        // DELETE FROM "table" or DELETE FROM table
        if (preg_match('/^\s*delete\s+from\s+[`"\']?(\w+)[`"\']?/i', $sql, $matches)) {
            return $matches[1];
        }

        // UPDATE "table" SET or UPDATE table SET
        if (preg_match('/^\s*update\s+[`"\']?(\w+)[`"\']?/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get foreign key information for a table that references the target table.
     *
     * @return array<int, array{from: string, to: string, on_delete: string, on_update: string}>
     */
    protected function getForeignKeysReferencing(ConnectionInterface $connection, string $childTable, string $parentTable): array
    {
        $fks = $connection->select("PRAGMA foreign_key_list({$childTable})");
        $matching = [];

        foreach ($fks as $fk) {
            $fk = (array) $fk;
            if (strcasecmp($fk['table'] ?? '', $parentTable) === 0) {
                $matching[] = [
                    'from' => $fk['from'],
                    'to' => $fk['to'],
                    'on_delete' => $fk['on_delete'] ?? 'NO ACTION',
                    'on_update' => $fk['on_update'] ?? 'NO ACTION',
                ];
            }
        }

        return $matching;
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
    protected function analyzeDetailString(
        string $detail,
        ?string $targetTable,
        ?string $queryType,
        ?ConnectionInterface $connection
    ): array {
        $issues = [];

        // Check for full table scan (but not CONSTANT which is an optimization)
        if (preg_match('/^SCAN (\w+)/', $detail, $matches)) {
            $scannedTable = $matches[1];

            if (strcasecmp($scannedTable, 'CONSTANT') === 0) {
                return $issues;
            }

            // Create appropriate issue based on whether this is a FK constraint check
            $issues[] = $this->isForeignKeyConstraintCheck($scannedTable, $targetTable, $queryType)
                ? $this->createForeignKeyIssue($scannedTable, $targetTable, $queryType, $connection)
                : $this->fullTableScanIssue($scannedTable);
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

    /**
     * Check if a table scan is likely a FK constraint check.
     */
    protected function isForeignKeyConstraintCheck(?string $scannedTable, ?string $targetTable, ?string $queryType): bool
    {
        if ($scannedTable === null || $targetTable === null || $queryType === null) {
            return false;
        }

        // FK checks only happen on DELETE/UPDATE
        if (! in_array($queryType, ['DELETE', 'UPDATE'], true)) {
            return false;
        }

        // If scanning a different table than the target, it's likely a FK check
        return strcasecmp($scannedTable, $targetTable) !== 0;
    }

    /**
     * Create an issue for a FK constraint check.
     */
    protected function createForeignKeyIssue(
        string $scannedTable,
        ?string $targetTable,
        ?string $queryType,
        ?ConnectionInterface $connection
    ): QueryIssue {
        $fkDetails = '';

        if ($connection !== null && $targetTable !== null) {
            $fks = $this->getForeignKeysReferencing($connection, $scannedTable, $targetTable);

            if (! empty($fks)) {
                $fkDescriptions = [];
                foreach ($fks as $fk) {
                    $action = $queryType === 'DELETE' ? $fk['on_delete'] : $fk['on_update'];
                    $fkDescriptions[] = "{$scannedTable}.{$fk['from']} → {$targetTable}.{$fk['to']} (ON {$queryType} {$action})";
                }
                $fkDetails = ': ' . implode(', ', $fkDescriptions);
            }
        }

        return QueryIssue::warning(
            message: "Full table scan on '{$scannedTable}' (FK constraint check{$fkDetails})",
            table: $scannedTable,
        );
    }
}
