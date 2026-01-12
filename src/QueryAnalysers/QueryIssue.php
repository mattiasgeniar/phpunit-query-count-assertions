<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers;

use Mattiasgeniar\PhpunitQueryCountAssertions\Enums\Severity;

/**
 * Represents a single query performance issue detected during EXPLAIN analysis.
 */
readonly class QueryIssue
{
    public function __construct(
        public Severity $severity,
        public string $message,
        public ?string $table = null,
        public ?int $estimatedRows = null,
        public ?float $cost = null,
    ) {}

    public static function error(string $message, ?string $table = null, ?int $estimatedRows = null): self
    {
        return new self(
            severity: Severity::Error,
            message: $message,
            table: $table,
            estimatedRows: $estimatedRows,
        );
    }

    public static function warning(string $message, ?string $table = null, ?int $estimatedRows = null): self
    {
        return new self(
            severity: Severity::Warning,
            message: $message,
            table: $table,
            estimatedRows: $estimatedRows,
        );
    }

    public static function info(string $message, ?string $table = null): self
    {
        return new self(
            severity: Severity::Info,
            message: $message,
            table: $table,
        );
    }

    public function meetsThreshold(Severity $threshold): bool
    {
        return $this->severity->meetsThreshold($threshold);
    }

    public function __toString(): string
    {
        $parts = [$this->message];

        if ($this->estimatedRows !== null) {
            $parts[] = "~{$this->estimatedRows} rows";
        }

        if ($this->cost !== null) {
            $parts[] = "cost: {$this->cost}";
        }

        return implode(' ', $parts);
    }
}
