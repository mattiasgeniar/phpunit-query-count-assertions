<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Closure;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\QueryDriverInterface;
use Phalcon\Db\Adapter\AbstractAdapter;
use Phalcon\Events\Manager as EventsManager;

/**
 * Phalcon driver for query tracking.
 *
 * Uses Phalcon's EventsManager to listen for db:beforeQuery and db:afterQuery events.
 *
 * Example usage:
 *
 *     // Get your Phalcon DB adapter (usually from DI)
 *     $db = $this->getDI()->get('db');
 *
 *     // Create and configure driver
 *     $driver = new PhalconDriver();
 *     $driver->registerConnection('default', $db);
 *
 *     // In your test
 *     self::useDriver($driver);
 *
 * Lazy loading detection is NOT supported in Phalcon.
 */
class PhalconDriver implements QueryDriverInterface
{
    /**
     * Registered connections.
     *
     * @var array<string, AbstractAdapter>
     */
    private array $connections = [];

    /**
     * Start times for queries, keyed by connection name.
     *
     * @var array<string, float>
     */
    private array $startTimes = [];

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
     * Track which connections have listeners attached.
     *
     * @var array<string, bool>
     */
    private array $listenersAttached = [];

    /**
     * Register a Phalcon database adapter for tracking.
     */
    public function registerConnection(string $name, AbstractAdapter $adapter): void
    {
        $this->connections[$name] = $adapter;
        $this->attachListeners($name, $adapter);
    }

    private function attachListeners(string $name, AbstractAdapter $adapter): void
    {
        if (isset($this->listenersAttached[$name])) {
            return;
        }

        $eventsManager = $adapter->getEventsManager();

        if ($eventsManager === null) {
            $eventsManager = new EventsManager;
            $adapter->setEventsManager($eventsManager);
        }

        $eventsManager->attach('db:beforeQuery', function ($event, $connection) use ($name) {
            if (self::$isTracking) {
                $this->startTimes[$name] = microtime(true);
            }
        });

        $eventsManager->attach('db:afterQuery', function ($event, $connection) use ($name) {
            if (! self::$isTracking || self::$queryCallback === null) {
                return;
            }

            if (self::$connectionsToTrack !== null
                && ! in_array($name, self::$connectionsToTrack, true)) {
                return;
            }

            $startTime = $this->startTimes[$name] ?? microtime(true);
            $timeMs = (microtime(true) - $startTime) * 1000;

            (self::$queryCallback)([
                'query' => $connection->getSQLStatement(),
                'bindings' => $connection->getSQLVariables() ?? [],
                'time' => $timeMs,
                'connection' => $name,
            ]);

            unset($this->startTimes[$name]);
        });

        $this->listenersAttached[$name] = true;
    }

    public function startListening(Closure $callback, ?array $connections = null): void
    {
        self::$isTracking = true;
        self::$queryCallback = $callback;
        self::$connectionsToTrack = $connections;
        $this->startTimes = [];
    }

    public function stopListening(): void
    {
        self::$isTracking = false;
        self::$queryCallback = null;
        self::$connectionsToTrack = null;
        $this->startTimes = [];
    }

    public function getConnection(?string $name = null): ConnectionInterface
    {
        if (empty($this->connections)) {
            throw new \RuntimeException(
                'No Phalcon connections registered. Call registerConnection() first.'
            );
        }

        $name = $name ?? array_key_first($this->connections);

        if (! isset($this->connections[$name])) {
            throw new \RuntimeException("Phalcon connection '{$name}' not registered.");
        }

        return new PhalconConnection($this->connections[$name]);
    }

    /**
     * Lazy loading detection is NOT supported in Phalcon.
     *
     * @return false Always returns false
     */
    public function enableLazyLoadingDetection(Closure $violationCallback): bool
    {
        return false;
    }

    public function disableLazyLoadingDetection(): void
    {
        // No-op: Phalcon doesn't support lazy loading detection
    }

    public function getBasePath(): string
    {
        return getcwd() ?: '';
    }

    public function getStackTraceSkipPatterns(): array
    {
        return [
            '/vendor\/phalcon\//',
            '/AssertsQueryCounts\.php$/',
            '/Drivers\/PhalconDriver\.php$/',
            '/vendor\/phpunit/',
        ];
    }
}
