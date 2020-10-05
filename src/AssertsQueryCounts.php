<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

trait AssertsQueryCounts
{
    protected static $trackedConnection;

    public function assertNoQueriesExecuted(Closure $closure = null, string $connection = null): void
    {
        $this->assertQueryCount(function (int $count) {
            $this->assertEquals(0, $count);
        }, $closure, $connection);
    }

    public function assertQueryCountMatches(int $expected, Closure $closure = null, string $connection = null): void
    {
        $this->assertQueryCount(function (int $count) use ($expected) {
            $this->assertEquals($expected, $count);
        }, $closure, $connection);
    }

    public function assertQueryCountLessThan(int $expected, Closure $closure = null, string $connection = null): void
    {
        $this->assertQueryCount(function (int $count) use ($expected) {
            $this->assertLessThan($expected, $count);
        }, $closure, $connection);
    }

    public function assertQueryCountGreaterThan(int $expected, Closure $closure = null, string $connection = null): void
    {
        $this->assertQueryCount(function (int $count) use ($expected) {
            $this->assertGreaterThan($expected, $count);
        }, $closure, $connection);
    }

    protected function assertQueryCount(Closure $assert, Closure $closure = null, string $connection = null): void
    {
        if ($closure) {
            $trackedConnection = static::$trackedConnection;

            self::trackQueries($connection);

            $closure();
        }

        $assert(self::getQueryCount($connection));

        if ($closure) {
            static::getTrackedConnection($connection)->flushQueryLog();

            static::$trackedConnection = $trackedConnection;
        }
    }

    public static function trackQueries(?string $connection = null): void
    {
        static::getTrackedConnection(static::$trackedConnection = $connection)->enableQueryLog();
    }

    public static function getQueriesExecuted(?string $connection = null): array
    {
        return static::getTrackedConnection($connection)->getQueryLog();
    }

    public static function getQueryCount(?string $connection = null): int
    {
        return count(self::getQueriesExecuted($connection));
    }

    protected static function getTrackedConnection(?string $connection = null): Connection
    {
        return DB::connection($connection ?? static::$trackedConnection);
    }
}
