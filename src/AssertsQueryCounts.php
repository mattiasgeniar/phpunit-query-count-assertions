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
            fn () => $this->assertEquals($count, self::getQueryCount())
        );
    }

    public function assertQueryCountLessThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThan($count, self::getQueryCount())
        );
    }

    public function assertQueryCountGreaterThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThan($count, self::getQueryCount())
        );
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
