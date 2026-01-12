<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns;

use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryIssue;

trait ExplainsQueries
{
    public function canExplain(string $sql): bool
    {
        $sql = strtolower(trim($sql));

        return str_starts_with($sql, 'select')
            || str_starts_with($sql, 'delete')
            || str_starts_with($sql, 'update')
            || (str_starts_with($sql, 'insert') && str_contains($sql, 'select'))
            || (str_starts_with($sql, 'replace') && str_contains($sql, 'select'));
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
