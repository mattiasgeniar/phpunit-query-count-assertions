<?php

namespace Mattiasgeniar\PhpunitDbQuerycounter;

use Closure;
use Illuminate\Support\Facades\DB;

trait PhpunitDbQuerycounter
{
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

    public function assertNoQueriesExecuted(): void
    {
        $this->assertQueryCountMatches(0);
    }

    public function assertQueryCountMatches(int $count, Closure $closure = null): void
    {
        if ($closure) {
            self::trackQueries();

            $closure();
        }

        $this->assertEquals($count, self::getQueryCount());

        if ($closure) {
            DB::flushQueryLog();
        }
    }

    public function assertQueryCountLessThan(int $count): void
    {
        $this->assertLessThan($count, self::getQueryCount());
    }

    public function assertQueryCountGreaterThan(int $count): void
    {
        $this->assertGreaterThan($count, self::getQueryCount());
    }
}
