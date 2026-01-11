<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use PHPUnit\Framework\Attributes\Test;

class AssertsQueryCountsTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();

        self::trackQueries();
    }

    #[Test]
    public function no_queries_are_logged_when_none_are_executed(): void
    {
        $this->assertNoQueriesExecuted();
    }

    #[Test]
    public function queries_are_counted_when_executed(): void
    {
        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');

        $this->assertQueryCountMatches(1);

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');

        $this->assertQueryCountMatches(2);
    }

    #[Test]
    public function it_can_assert_the_amount_of_queries_in_callable(): void
    {
        $this->assertQueryCountMatches(1, function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        });

        $this->assertQueryCountMatches(2, function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        });
    }

    #[Test]
    public function we_can_check_for_less_than_queries(): void
    {
        collect(range(1, 5))->each(function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        });

        $this->assertQueryCountMatches(5);

        $this->assertQueryCountLessThan(6);

        $this->assertQueryCountGreaterThan(4);
    }
}
