<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns;

use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryIssue;

trait ExplainsQueries
{
    public function canExplain(string $sql): bool
    {
        $type = $this->extractQueryType($sql);

        return match ($type) {
            'SELECT', 'DELETE', 'UPDATE' => true,
            'INSERT', 'REPLACE' => str_contains(strtolower($sql), 'select'),
            default => false,
        };
    }

    protected function extractQueryType(?string $sql): ?string
    {
        if ($sql === null) {
            return null;
        }

        if (preg_match('/^\s*(\w+)/i', $sql, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    protected function fullTableScanIssue(string $table, ?int $estimatedRows = null): QueryIssue
    {
        return QueryIssue::error(
            message: "Full table scan on '{$table}'",
            table: $table,
            estimatedRows: $estimatedRows,
        );
    }

    protected function fullIndexScanIssue(string $table, ?int $estimatedRows = null): QueryIssue
    {
        return QueryIssue::warning(
            message: "Full index scan on '{$table}'",
            table: $table,
            estimatedRows: $estimatedRows,
        );
    }

    /**
     * Remove duplicate issues based on severity and message.
     *
     * @param  array<int, QueryIssue>  $issues
     * @return array<int, QueryIssue>
     */
    protected function deduplicateIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            $key = $issue->severity->value . '|' . $issue->message;
            $unique[$key] ??= $issue;
        }

        return array_values($unique);
    }
}
