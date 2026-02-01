<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 compatible logger for Doctrine DBAL query tracking.
 *
 * This logger can be used with Doctrine's Logging\Middleware to track queries.
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

        // Doctrine DBAL logs "Executing statement: {sql}" when starting a query
        if (str_starts_with($messageStr, 'Executing statement:') || str_starts_with($messageStr, 'Executing query:')) {
            $this->startTime = microtime(true);
            $this->currentSql = $context['sql'] ?? $this->extractSqlFromMessage($messageStr);
            $this->currentBindings = $context['params'] ?? [];

            return;
        }

        // Doctrine DBAL logs "Query execution" with timing info after completion
        // Or simply logs additional context - we capture on any subsequent log call
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

    private function extractSqlFromMessage(string $message): string
    {
        // Remove "Executing statement: " or "Executing query: " prefix
        $sql = preg_replace('/^Executing (statement|query):\s*/', '', $message);

        return $sql ?? $message;
    }
}
