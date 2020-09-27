<?php

namespace Mattiasgeniar\PhpunitDbQuerycounter;

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

    public function assertNoQueriesExecuted()
    {
        return $this->assertQueryCountMatches(0);
    }

    public function assertQueryCountMatches(int $count)
    {
        return $this->assertEquals($count, self::getQueryCount());
    }

    public function assertQueryCountLessThan(int $count)
    {
        return $this->assertLessThan($count, self::getQueryCount());
    }

    public function assertQueryCountGreaterThan(int $count)
    {
        return $this->assertGreaterThan($count, self::getQueryCount());
    }
}
