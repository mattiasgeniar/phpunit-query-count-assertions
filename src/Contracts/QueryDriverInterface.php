<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Contracts;

use Closure;

/**
 * Database driver abstraction for query tracking.
 *
 * This interface allows the query count assertions to work with
 * any PHP framework (Laravel, Doctrine, Phalcon, etc.) by abstracting
 * the framework-specific query listening and lazy loading detection.
 */
interface QueryDriverInterface
{
    /**
     * Start listening for queries on specified connections.
     *
     * The callback will be invoked for each executed query with the following array:
     * - 'query' => string (SQL)
     * - 'bindings' => array (parameters)
     * - 'time' => float (execution time in ms)
     * - 'connection' => string (connection name)
     *
     * @param  Closure(array{query: string, bindings: array, time: float, connection: string}): void  $callback
     * @param  array<string>|null  $connections  Connection names to track, or null for all
     */
    public function startListening(Closure $callback, ?array $connections = null): void;

    /**
     * Stop listening for queries and cleanup.
     */
    public function stopListening(): void;

    /**
     * Get a connection wrapper for EXPLAIN queries.
     *
     * @param  string|null  $name  Connection name, or null for default
     */
    public function getConnection(?string $name = null): ConnectionInterface;

    /**
     * Enable lazy loading detection.
     *
     * The callback will be invoked for each lazy loading violation with:
     * - 'model' => string (model class name)
     * - 'relation' => string (relation name)
     *
     * Return false if lazy loading detection is not supported by this driver.
     *
     * @param  Closure(array{model: string, relation: string}): void  $violationCallback
     * @return bool True if enabled successfully, false if not supported
     */
    public function enableLazyLoadingDetection(Closure $violationCallback): bool;

    /**
     * Disable lazy loading detection and restore previous state.
     */
    public function disableLazyLoadingDetection(): void;

    /**
     * Get the base path for relative file path formatting.
     */
    public function getBasePath(): string;

    /**
     * Get regex patterns for stack trace frames to skip (framework internals).
     *
     * @return array<int, string>
     */
    public function getStackTraceSkipPatterns(): array;
}
