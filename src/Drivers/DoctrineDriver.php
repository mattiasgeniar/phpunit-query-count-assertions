<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Doctrine\DBAL\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;

/**
 * Doctrine DBAL driver for query tracking.
 *
 * IMPORTANT: Doctrine requires middleware to be configured at connection creation time.
 * You must add DoctrineQueryLogger middleware to your connection configuration.
 *
 * Example setup:
 *
 *     use Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\DoctrineDriver;
 *     use Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\DoctrineQueryLogger;
 *
 *     // Create the driver and logger
 *     $driver = new DoctrineDriver();
 *     $logger = new DoctrineQueryLogger($driver);
 *
 *     // Add logger to Doctrine configuration
 *     $config = new Configuration();
 *     $config->setMiddlewares([new Doctrine\DBAL\Logging\Middleware($logger)]);
 *
 *     // Create connection with middleware
 *     $connection = DriverManager::getConnection($params, $config);
 *
 *     // Register with driver
 *     $driver->registerConnection('default', $connection);
 *
 *     // In your test
 *     self::useDriver($driver);
 *
 * Lazy loading detection is NOT supported in Doctrine as it doesn't have
 * implicit lazy loading like Laravel's Eloquent ORM.
 */
class DoctrineDriver extends AbstractDriver
{
    /**
     * Register a Doctrine DBAL connection for tracking.
     */
    public function registerConnection(string $name, Connection $connection): void
    {
        $this->connections[$name] = $connection;
    }

    protected function wrapConnection(object $connection): ConnectionInterface
    {
        assert($connection instanceof Connection);

        return new DoctrineConnection($connection);
    }

    public function getStackTraceSkipPatterns(): array
    {
        return [
            ...parent::getStackTraceSkipPatterns(),
            '/vendor\/doctrine\/dbal/',
            '/vendor\/doctrine\/orm/',
            '/vendor\/symfony\//',
            '/Drivers\/DoctrineDriver\.php$/',
        ];
    }

    public function supportsQueryTiming(): bool
    {
        return false;
    }

    /**
     * Record a query (called by DoctrineQueryLogger middleware).
     *
     * @param  array<int|string, mixed>  $bindings
     *
     * @internal
     */
    public function recordQuery(string $sql, array $bindings, float $timeMs, string $connectionName): void
    {
        self::dispatchQuery($sql, $bindings, $timeMs, $connectionName);
    }
}
