<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Phalcon\Db\Adapter\AdapterInterface;

/**
 * Phalcon database adapter wrapper implementing ConnectionInterface.
 */
class PhalconConnection implements ConnectionInterface
{
    public function __construct(
        private readonly AdapterInterface $adapter
    ) {}

    public function getDriverName(): string
    {
        $type = $this->adapter->getType();

        // Normalize Phalcon adapter types to common driver names
        return match (strtolower($type)) {
            'mysql' => 'mysql',
            'postgresql' => 'pgsql',
            'sqlite' => 'sqlite',
            default => $type,
        };
    }

    public function select(string $sql, array $bindings = []): array
    {
        // For EXPLAIN queries, substitute bindings directly since MySQL doesn't
        // support parameterized LIMIT/OFFSET in prepared statements
        if ($this->isExplainQuery($sql)) {
            $sql = $this->substituteBindings($sql, $bindings);
            $bindings = [];
        }

        $result = $this->adapter->query($sql, $bindings);
        $rows = [];

        if ($result !== false) {
            $result->setFetchMode(\Phalcon\Db\Enum::FETCH_OBJ);
            while ($row = $result->fetch()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        // For EXPLAIN queries, substitute bindings directly since MySQL doesn't
        // support parameterized LIMIT/OFFSET in prepared statements
        if ($this->isExplainQuery($sql)) {
            $sql = $this->substituteBindings($sql, $bindings);
            $bindings = [];
        }

        $result = $this->adapter->query($sql, $bindings);

        if ($result === false) {
            return null;
        }

        $result->setFetchMode(\Phalcon\Db\Enum::FETCH_OBJ);
        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    private function isExplainQuery(string $sql): bool
    {
        return str_starts_with(strtoupper(ltrim($sql)), 'EXPLAIN');
    }

    /**
     * Substitute named bindings directly into SQL.
     *
     * This is safe for EXPLAIN queries since they are read-only and
     * don't modify data. MySQL doesn't support parameterized LIMIT/OFFSET
     * in prepared statements, so we must substitute values directly.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    private function substituteBindings(string $sql, array $bindings): string
    {
        foreach ($bindings as $key => $value) {
            $placeholder = is_int($key) ? '?' : ':' . ltrim((string) $key, ':');
            $replacement = $this->formatBindingValue($value);

            if ($placeholder === '?') {
                // Replace only the first ? for positional parameters
                $pos = strpos($sql, '?');
                if ($pos !== false) {
                    $sql = substr_replace($sql, $replacement, $pos, 1);
                }
            } else {
                // Replace named parameter
                $sql = preg_replace(
                    '/' . preg_quote($placeholder, '/') . '\b/',
                    $replacement,
                    $sql,
                    1
                ) ?? $sql;
            }
        }

        return $sql;
    }

    private function formatBindingValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Escape string values
        return $this->adapter->escapeString((string) $value);
    }
}
