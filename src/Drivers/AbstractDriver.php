<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Closure;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\QueryDriverInterface;
use RuntimeException;

/**
 * Base driver for frameworks without lazy loading detection (Doctrine, Phalcon, etc.)
 *
 * Provides shared tracking state, connection management, and query dispatching.
 * Subclasses only need to implement registerConnection(), wrapConnection(),
 * and getStackTraceSkipPatterns().
 */
abstract class AbstractDriver implements QueryDriverInterface
{
    private static bool $isTracking = false;

    private static ?Closure $queryCallback = null;

    /**
     * @var array<string>|null
     */
    private static ?array $connectionsToTrack = null;

    /**
     * @var array<string, object>
     */
    protected array $connections = [];

    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connectionWrappers = [];

    abstract protected function wrapConnection(object $connection): ConnectionInterface;

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
                'No connections registered. Call registerConnection() first.'
            );
        }

        $resolvedName = $name ?? array_key_first($this->connections);

        if (! isset($this->connections[$resolvedName])) {
            throw new RuntimeException("Connection '{$resolvedName}' is not registered.");
        }

        return $this->connectionWrappers[$resolvedName] ??= $this->wrapConnection($this->connections[$resolvedName]);
    }

    public function enableLazyLoadingDetection(Closure $violationCallback): bool
    {
        return false;
    }

    public function disableLazyLoadingDetection(): void
    {
        // No-op: lazy loading detection requires Laravel
    }

    public function getBasePath(): string
    {
        return getcwd() ?: '';
    }

    public function getStackTraceSkipPatterns(): array
    {
        return [
            '/AssertsQueryCounts\.php$/',
            '/vendor\/phpunit/',
        ];
    }

    /**
     * Dispatch a query to the tracking callback.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    protected static function dispatchQuery(string $sql, array $bindings, float $timeMs, string $connectionName): void
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

    protected static function isCurrentlyTracking(): bool
    {
        return self::$isTracking;
    }
}
