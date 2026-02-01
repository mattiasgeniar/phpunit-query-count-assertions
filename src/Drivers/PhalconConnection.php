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
        $result = $this->adapter->query($sql, $bindings);

        if ($result === false) {
            return null;
        }

        $result->setFetchMode(\Phalcon\Db\Enum::FETCH_OBJ);
        $row = $result->fetch();

        return $row !== false ? $row : null;
    }
}
