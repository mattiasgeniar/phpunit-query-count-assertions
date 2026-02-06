<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Closure;
use Doctrine\DBAL\Connection;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\QueryDriverInterface;
use RuntimeException;

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
class DoctrineDriver implements QueryDriverInterface
{
    /**
     * Registered connections.
     *
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * Whether we're currently tracking.
     */
    private static bool $isTracking = false;

    /**
     * Current query callback.
     */
    private static ?Closure $queryCallback = null;

    /**
     * Connections to track (null = all).
     *
     * @var array<string>|null
     */
    private static ?array $connectionsToTrack = null;

    /**
     * Cached connection wrappers.
     *
     * @var array<string, ConnectionInterface>
     */
    private array $connectionWrappers = [];

    /**
     * Register a Doctrine DBAL connection for tracking.
     */
    public function registerConnection(string $name, Connection $connection): void
    {
        $this->connections[$name] = $connection;
    }

    public function startListening(Closure $callback, ?array $connections = null): void
    {
        self::$isTracking = true;
        self::$queryCallback = $callback;
        self::$connectionsToTrack = $connections;
    }

    public function stopListening(): void
    {
        self::$isTracking = false;
        self::$queryCallback = null;
        self::$connectionsToTrack = null;
    }

    public function getConnection(?string $name = null): ConnectionInterface
    {
        if (empty($this->connections)) {
            throw new RuntimeException(
                'No Doctrine connections registered. Call registerConnection() first.'
            );
        }

        $name = $name ?? array_key_first($this->connections);

        if (! isset($this->connections[$name])) {
            throw new RuntimeException("Doctrine connection '{$name}' not registered.");
        }

        return $this->connectionWrappers[$name] ??= new DoctrineConnection($this->connections[$name]);
    }

    /**
     * Lazy loading detection is NOT supported in Doctrine.
     *
     * Doctrine uses explicit loading strategies (eager, lazy, extra-lazy) but doesn't
     * have the same implicit lazy loading pattern as Laravel's Eloquent ORM.
     *
     * @return false Always returns false
     */
    public function enableLazyLoadingDetection(Closure $violationCallback): bool
    {
        return false;
    }

    public function disableLazyLoadingDetection(): void
    {
        // No-op: Doctrine doesn't support lazy loading detection
    }

    public function getBasePath(): string
    {
        return getcwd() ?: '';
    }

    public function getStackTraceSkipPatterns(): array
    {
        return [
            '/vendor\/doctrine\/dbal/',
            '/vendor\/doctrine\/orm/',
            '/vendor\/symfony\//',
            '/AssertsQueryCounts\.php$/',
            '/Drivers\/DoctrineDriver\.php$/',
            '/vendor\/phpunit/',
        ];
    }

    /**
     * Record a query (called by DoctrineQueryLogger middleware).
     *
     * @internal
     */
    public function recordQuery(string $sql, array $bindings, float $timeMs, string $connectionName): void
    {
        if (! self::$isTracking || self::$queryCallback === null) {
            return;
        }

        if (self::$connectionsToTrack !== null
            && ! in_array($connectionName, self::$connectionsToTrack, true)) {
            return;
        }

        (self::$queryCallback)([
            'query' => $sql,
            'bindings' => $bindings,
            'time' => $timeMs,
            'connection' => $connectionName,
        ]);
    }
}
