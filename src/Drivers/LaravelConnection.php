<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Illuminate\Database\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;

/**
 * Laravel connection wrapper implementing ConnectionInterface.
 */
class LaravelConnection implements ConnectionInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function getDriverName(): string
    {
        return $this->connection->getDriverName();
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->connection->select($sql, $bindings);
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        return $this->connection->selectOne($sql, $bindings);
    }
}
