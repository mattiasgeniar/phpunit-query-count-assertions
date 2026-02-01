<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ExplainTrackingTest extends TestCase
{
    use AssertsQueryCounts;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }

    #[Test]
    public function explain_queries_should_not_be_tracked(): void
    {
        $this->trackQueries();

        // Run exactly 2 queries
        DB::select('SELECT 1');
        DB::select('SELECT 2');

        // This runs EXPLAIN queries internally
        $this->assertAllQueriesUseIndexes();

        // Check if only our 2 queries were tracked (not EXPLAIN queries)
        $count = self::getQueryCount();
        $this->assertEquals(2, $count, "Expected 2 queries but got {$count}. EXPLAIN queries may have been tracked.");
    }
}
