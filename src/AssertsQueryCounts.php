<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions;

use Closure;
use Illuminate\Support\Facades\DB;

trait AssertsQueryCounts
{
    public function assertNoQueriesExecuted(?Closure $closure = null): void
    {
        $this->assertQueryCountMatches(0, $closure);
    }

    public function assertQueryCountMatches(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertEquals($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected {$count} queries, got " . self::getQueryCount() . "."
            ))
        );
    }

    public function assertQueryCountLessThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThan($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected fewer than {$count} queries, got " . self::getQueryCount() . "."
            ))
        );
    }

    public function assertQueryCountLessThanOrEqual(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThanOrEqual($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected at most {$count} queries, got " . self::getQueryCount() . "."
            ))
        );
    }

    public function assertQueryCountGreaterThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThan($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected more than {$count} queries, got " . self::getQueryCount() . "."
            ))
        );
    }

    public function assertQueryCountGreaterThanOrEqual(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThanOrEqual($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected at least {$count} queries, got " . self::getQueryCount() . "."
            ))
        );
    }

    public function assertQueryCountBetween(int $min, int $max, ?Closure $closure = null): void
    {
        $this->withQueryTracking($closure, function () use ($min, $max) {
            $count = self::getQueryCount();
            $message = $this->formatFailureMessage(
                "Expected between {$min} and {$max} queries, got {$count}."
            );

            $this->assertGreaterThanOrEqual($min, $count, $message);
            $this->assertLessThanOrEqual($max, $count, $message);
        });
    }

    private function withQueryTracking(?Closure $closure, callable $assertion): void
    {
        if ($closure) {
            self::trackQueries();
            $closure();
        }

        $assertion();

        if ($closure) {
            DB::flushQueryLog();
        }
    }

    private function formatFailureMessage(string $message): string
    {
        $queries = self::getQueriesExecuted();

        if (empty($queries)) {
            return $message . "\nNo queries were executed.";
        }

        $formatted = $message . "\nQueries executed:";

        foreach ($queries as $index => $query) {
            $number = $index + 1;
            $sql = $query['query'];
            $time = round($query['time'], 2);

            $formatted .= "\n  {$number}. [{$time}ms] {$sql}";

            if (! empty($query['bindings'])) {
                $bindings = json_encode($query['bindings']);
                $formatted .= "\n      Bindings: {$bindings}";
            }
        }

        return $formatted;
    }

    public static function trackQueries(): void
    {
        DB::enableQueryLog();
    }

    public static function getQueriesExecuted(): array
    {
        return DB::getQueryLog();
    }

    public static function getQueryCount(): int
    {
        return count(self::getQueriesExecuted());
    }
}
