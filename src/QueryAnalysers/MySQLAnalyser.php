<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Illuminate\Database\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\Enums\Severity;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns\ExplainsQueries;
use Throwable;

class MySQLAnalyser implements QueryAnalyser
{
    use ExplainsQueries;

    /**
     * Minimum rows threshold to flag a full table scan as an error.
     * Scans on small tables are often optimal.
     */
    protected int $minRowsForScanWarning = 100;

    /**
     * Cost threshold above which queries are flagged.
     */
    protected ?float $maxCost = null;

    /**
     * Cache support for JSON EXPLAIN per connection instance.
     *
     * @var array<int, bool>
     */
    protected array $supportsJsonExplainCache = [];

    public function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    public function explain(Connection $connection, string $sql, array $bindings): array
    {
        if ($this->supportsJsonExplain($connection)) {
            return $this->explainJson($connection, $sql, $bindings);
        }

        return $this->explainTabular($connection, $sql, $bindings);
    }

    public function analyzeIndexUsage(array $explainResults, ?string $sql = null, ?Connection $connection = null): array
    {
        if (isset($explainResults['query_block'])) {
            return $this->deduplicateIssues($this->analyzeJsonExplain($explainResults));
        }

        $issues = [];

        foreach ($explainResults as $row) {
            $row = (array) $row;
            $issues = [...$issues, ...$this->analyzeTabularRow($row)];
        }

        return $this->deduplicateIssues($issues);
    }

    public function supportsRowCounting(): bool
    {
        return true;
    }

    public function getRowsExamined(array $explainResults): int
    {
        if (isset($explainResults['query_block'])) {
            return $this->getRowsFromJsonExplain($explainResults['query_block']);
        }

        $totalRows = 0;

        foreach ($explainResults as $row) {
            $row = (array) $row;

            if (isset($row['rows'])) {
                $totalRows += (int) $row['rows'];
            }
        }

        return $totalRows;
    }

    public function withMinRowsForScanWarning(int $minRows): static
    {
        $clone = clone $this;
        $clone->minRowsForScanWarning = $minRows;

        return $clone;
    }

    public function withMaxCost(?float $maxCost): static
    {
        $clone = clone $this;
        $clone->maxCost = $maxCost;

        return $clone;
    }

    protected function supportsJsonExplain(Connection $connection): bool
    {
        $connectionId = spl_object_id($connection);

        if (array_key_exists($connectionId, $this->supportsJsonExplainCache)) {
            return $this->supportsJsonExplainCache[$connectionId];
        }

        $supports = false;

        try {
            $version = $connection->selectOne('SELECT VERSION() as version');
            $versionString = $version->version ?? '';

            // JSON EXPLAIN available in MySQL 5.6+
            // MariaDB uses different format, stick to tabular
            if (str_contains(strtolower($versionString), 'mariadb')) {
                $supports = false;
            } elseif (preg_match('/^(\d+\.\d+)/', $versionString, $matches)) {
                $supports = version_compare($matches[1], '5.6', '>=');
            }
        } catch (Throwable) {
            $supports = false;
        }

        $this->supportsJsonExplainCache[$connectionId] = $supports;

        return $supports;
    }

    protected function explainJson(Connection $connection, string $sql, array $bindings): array
    {
        $result = $connection->selectOne('EXPLAIN FORMAT=JSON ' . $sql, $bindings);

        if ($result === null || ! isset($result->EXPLAIN)) {
            return [];
        }

        $decoded = json_decode($result->EXPLAIN, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function explainTabular(Connection $connection, string $sql, array $bindings): array
    {
        return $connection->select('EXPLAIN ' . $sql, $bindings);
    }

    /**
     * Analyze JSON EXPLAIN output recursively.
     *
     * @return array<int, QueryIssue>
     */
    protected function analyzeJsonExplain(array $explain): array
    {
        $issues = [];

        $this->walkJsonExplain($explain, function (array $node) use (&$issues) {
            $issues = [...$issues, ...$this->analyzeJsonNode($node)];
        });

        return $issues;
    }

    /**
     * Walk the JSON EXPLAIN tree and apply callback to each table/operation node.
     */
    protected function walkJsonExplain(array $node, callable $callback): void
    {
        // Process query_block (top-level entry point)
        if (isset($node['query_block'])) {
            $this->walkJsonExplain($node['query_block'], $callback);

            return;
        }

        // Process table node
        if (isset($node['table'])) {
            $callback($node['table']);
        }

        // Recurse into single child nodes
        $singleChildKeys = ['ordering_operation', 'grouping_operation', 'materialized_from_subquery'];
        foreach ($singleChildKeys as $key) {
            if (isset($node[$key])) {
                $this->walkJsonExplain($node[$key], $callback);
            }
        }

        // Recurse into array child nodes
        $arrayChildKeys = ['nested_loop', 'optimized_away_subqueries', 'query_specifications'];
        foreach ($arrayChildKeys as $key) {
            if (isset($node[$key])) {
                foreach ($node[$key] as $child) {
                    $this->walkJsonExplain($child, $callback);
                }
            }
        }
    }

    /**
     * Analyze a single JSON EXPLAIN table node.
     *
     * @return array<int, QueryIssue>
     */
    protected function analyzeJsonNode(array $node): array
    {
        $issues = [];
        $table = $node['table_name'] ?? 'unknown';
        $accessType = $node['access_type'] ?? null;
        $rows = isset($node['rows_examined_per_scan']) ? (int) $node['rows_examined_per_scan'] : null;
        $filtered = $node['filtered'] ?? null;
        $cost = $this->extractCost($node);
        $possibleKeys = $node['possible_keys'] ?? [];
        $key = $node['key'] ?? null;

        // Full table scan
        if ($accessType === 'ALL') {
            if ($rows === null || $rows >= $this->minRowsForScanWarning) {
                $issues[] = QueryIssue::error(
                    message: "Full table scan on '{$table}'",
                    table: $table,
                    estimatedRows: $rows,
                );
            }
        }

        // Full index scan (reads all index entries - often suboptimal)
        if ($accessType === 'index') {
            $issues[] = QueryIssue::warning(
                message: "Full index scan on '{$table}'",
                table: $table,
                estimatedRows: $rows,
            );
        }

        // Index available but not used
        if (! empty($possibleKeys) && $key === null) {
            $issues[] = QueryIssue::error(
                message: "Index available but not used on '{$table}'",
                table: $table,
                estimatedRows: $rows,
            );
        }

        // Low filter efficiency (examining many rows, keeping few)
        if ($filtered !== null && $filtered < 25 && $rows !== null && $rows > 1000) {
            $issues[] = QueryIssue::info(
                message: "Low filter efficiency on '{$table}' ({$filtered}% rows kept)",
                table: $table,
            );
        }

        // Check for filesort
        if (isset($node['using_filesort']) && $node['using_filesort']) {
            $issues[] = QueryIssue::warning(
                message: "Using filesort on '{$table}'",
                table: $table,
            );
        }

        // Check for temporary table
        if (isset($node['using_temporary_table']) && $node['using_temporary_table']) {
            $issues[] = QueryIssue::warning(
                message: "Using temporary table on '{$table}'",
                table: $table,
            );
        }

        // Cost threshold exceeded
        if ($this->maxCost !== null && $cost !== null && $cost > $this->maxCost) {
            $issues[] = new QueryIssue(
                severity: Severity::Warning,
                message: "High query cost on '{$table}'",
                table: $table,
                cost: $cost,
            );
        }

        return $issues;
    }

    /**
     * Extract cost from JSON node.
     */
    protected function extractCost(array $node): ?float
    {
        $cost = $node['cost_info']['read_cost'] ?? $node['cost_info']['query_cost'] ?? null;

        return $cost !== null ? (float) $cost : null;
    }

    /**
     * Get total rows examined from JSON EXPLAIN.
     */
    protected function getRowsFromJsonExplain(array $node): int
    {
        $totalRows = 0;

        $this->walkJsonExplain($node, function (array $tableNode) use (&$totalRows) {
            if (isset($tableNode['rows_examined_per_scan'])) {
                $totalRows += (int) $tableNode['rows_examined_per_scan'];
            }
        });

        return $totalRows;
    }

    /**
     * Analyze a single tabular EXPLAIN row.
     *
     * @return array<int, QueryIssue>
     */
    protected function analyzeTabularRow(array $row): array
    {
        $issues = [];
        $table = $row['table'] ?? 'unknown';
        $type = $row['type'] ?? null;
        $rows = isset($row['rows']) ? (int) $row['rows'] : null;
        $extra = $row['Extra'] ?? '';
        $possibleKeys = $row['possible_keys'] ?? null;
        $key = $row['key'] ?? null;

        // Full table scan
        if ($type === 'ALL') {
            if ($rows === null || $rows >= $this->minRowsForScanWarning) {
                $issues[] = QueryIssue::error(
                    message: "Full table scan on '{$table}'",
                    table: $table,
                    estimatedRows: $rows,
                );
            }
        }

        // Full index scan
        if ($type === 'index') {
            $issues[] = QueryIssue::warning(
                message: "Full index scan on '{$table}'",
                table: $table,
                estimatedRows: $rows,
            );
        }

        // Index available but not used
        if (! empty($possibleKeys) && empty($key)) {
            $issues[] = QueryIssue::error(
                message: "Index available but not used on '{$table}'",
                table: $table,
                estimatedRows: $rows,
            );
        }

        // Check Extra column for various issues
        $extraWarnings = [
            'Using filesort' => "Using filesort on '{$table}'",
            'Using temporary' => "Using temporary table on '{$table}'",
            'Using join buffer' => "Using join buffer on '{$table}' (missing index for join)",
        ];

        foreach ($extraWarnings as $pattern => $message) {
            if (str_contains($extra, $pattern)) {
                $issues[] = QueryIssue::warning(message: $message, table: $table);
            }
        }

        if (str_contains($extra, 'Full scan on NULL key')) {
            $issues[] = QueryIssue::error(
                message: "Full scan on NULL key on '{$table}'",
                table: $table,
            );
        }

        return $issues;
    }
}
