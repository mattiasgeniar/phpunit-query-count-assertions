<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Closure;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
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
class PhalconDriver extends AbstractDriver
{
    /**
     * Start times for queries, keyed by connection name.
     *
     * @var array<string, float>
     */
    private array $startTimes = [];

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

    protected function wrapConnection(object $connection): ConnectionInterface
    {
        assert($connection instanceof AbstractAdapter);

        return new PhalconConnection($connection);
    }

    public function startListening(Closure $callback, ?array $connections = null): void
    {
        parent::startListening($callback, $connections);
        $this->startTimes = [];
    }

    public function stopListening(): void
    {
        parent::stopListening();
        $this->startTimes = [];
    }

    public function getStackTraceSkipPatterns(): array
    {
        return [
            ...parent::getStackTraceSkipPatterns(),
            '/vendor\/phalcon\//',
            '/Drivers\/PhalconDriver\.php$/',
        ];
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
            if (self::isCurrentlyTracking()) {
                $this->startTimes[$name] = microtime(true);
            }
        });

        $eventsManager->attach('db:afterQuery', function ($event, $connection) use ($name) {
            $startTime = $this->startTimes[$name] ?? microtime(true);
            $timeMs = (microtime(true) - $startTime) * 1000;
            unset($this->startTimes[$name]);

            self::dispatchQuery(
                $connection->getSQLStatement(),
                $connection->getSQLVariables() ?? [],
                $timeMs,
                $name,
            );
        });

        $this->listenersAttached[$name] = true;
    }
}
