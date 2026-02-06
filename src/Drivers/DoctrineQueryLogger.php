<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 compatible logger for Doctrine DBAL query tracking.
 *
 * This logger can be used with Doctrine's Logging\Middleware to track queries.
 * Verified against Doctrine DBAL 3.x and 4.x, which log queries with structured
 * context arrays containing 'sql' and 'params' keys.
 *
 * Example usage:
 *
 *     use Doctrine\DBAL\Configuration;
 *     use Doctrine\DBAL\DriverManager;
 *     use Doctrine\DBAL\Logging\Middleware;
 *
 *     $driver = new DoctrineDriver();
 *     $logger = new DoctrineQueryLogger($driver, 'default');
 *
 *     $config = new Configuration();
 *     $config->setMiddlewares([new Middleware($logger)]);
 *
 *     $connection = DriverManager::getConnection($params, $config);
 *     $driver->registerConnection('default', $connection);
 *
 * Note: Query timing is measured between the "Executing" log entry and the next
 * log call from the same middleware. This is an approximation that may include
 * minor overhead from the logging infrastructure.
 */
class DoctrineQueryLogger extends AbstractLogger
{
    private ?float $startTime = null;

    private ?string $currentSql = null;

    /** @var array<int|string, mixed> */
    private array $currentBindings = [];

    public function __construct(
        private readonly DoctrineDriver $driver,
        private readonly string $connectionName = 'default'
    ) {}

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $messageStr = (string) $message;

        // Doctrine DBAL logs "Executing statement: {sql}" or "Executing query: {sql}"
        // with structured context: ['sql' => ..., 'params' => ..., 'types' => ...]
        if (str_starts_with($messageStr, 'Executing statement:') || str_starts_with($messageStr, 'Executing query:')) {
            if (! isset($context['sql'])) {
                return;
            }

            $this->startTime = microtime(true);
            $this->currentSql = $context['sql'];
            $this->currentBindings = $context['params'] ?? [];

            return;
        }

        // The next log call after "Executing" marks query completion
        if ($this->startTime !== null && $this->currentSql !== null) {
            $timeMs = (microtime(true) - $this->startTime) * 1000;

            $this->driver->recordQuery(
                $this->currentSql,
                $this->currentBindings,
                $timeMs,
                $this->connectionName
            );

            $this->startTime = null;
            $this->currentSql = null;
            $this->currentBindings = [];
        }
    }
}
