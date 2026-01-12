<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns;

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
}
