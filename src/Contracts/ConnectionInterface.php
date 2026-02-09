<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Contracts;

/**
 * Database connection abstraction for query analyzers.
 *
 * This interface abstracts the database connection to allow
 * query analyzers to work with any framework (Laravel, Doctrine, Phalcon, etc.)
 */
interface ConnectionInterface
{
    /**
     * Get the database driver name (mysql, sqlite, pgsql, etc.)
     */
    public function getDriverName(): string;

    /**
     * Execute a SELECT query and return all rows.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<int, object>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * Execute a SELECT query and return a single row.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public function selectOne(string $sql, array $bindings = []): ?object;
}
