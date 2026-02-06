<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Doctrine\DBAL\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;

/**
 * Doctrine DBAL connection wrapper implementing ConnectionInterface.
 */
class DoctrineConnection implements ConnectionInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getDriverName(): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $className = strtolower(basename(str_replace('\\', '/', $platform::class)));

        // Map Doctrine platform class names to driver names
        return match (true) {
            str_contains($className, 'mysql') => 'mysql',
            str_contains($className, 'mariadb') => 'mariadb',
            str_contains($className, 'sqlite') => 'sqlite',
            str_contains($className, 'postgresql'), str_contains($className, 'postgres') => 'pgsql',
            str_contains($className, 'sqlserver'), str_contains($className, 'mssql') => 'sqlsrv',
            str_contains($className, 'oracle') => 'oci',
            default => $className,
        };
    }

    public function select(string $sql, array $bindings = []): array
    {
        $result = $this->connection->executeQuery($sql, $bindings)->fetchAllAssociative();

        // Convert to objects to match Laravel's behavior
        return array_map(fn (array $row) => (object) $row, $result);
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        $result = $this->connection->executeQuery($sql, $bindings)->fetchAssociative();

        return $result !== false ? (object) $result : null;
    }
}
