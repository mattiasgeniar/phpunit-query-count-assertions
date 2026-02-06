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
 * Note: Queries are recorded when the "Executing" log entry is received (before
 * actual execution). Timing is not available since Doctrine's logging middleware
 * does not emit a post-execution event.
 */
class DoctrineQueryLogger extends AbstractLogger
{
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

            $this->driver->recordQuery(
                $context['sql'],
                $context['params'] ?? [],
                0.0,
                $this->connectionName
            );
        }
    }
}
