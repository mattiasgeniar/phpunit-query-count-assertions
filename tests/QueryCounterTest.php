<?php

namespace Mattiasgeniar\PhpunitDbQueryCounter\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitDbQueryCounter\AssertsQueryCounts;

class QueryCounterTest extends TestCase
{
    use AssertsQueryCounts;

    public function setUp(): void
    {
        parent::setUp();

        AssertsQueryCounts::trackQueries();
    }

    /** @test */
    public function no_queries_are_logged_when_none_are_executed()
    {
        $this->assertNoQueriesExecuted();
    }

    /** @test */
    public function queries_are_counted_when_executed()
    {
        DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query

        $this->assertQueryCountMatches(1);

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query

        $this->assertQueryCountMatches(2);
    }

    /** @test */
    public function it_can_assert_the_amount_of_queries_in_callable()
    {
        $this->assertQueryCountMatches(1, function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        });

        $this->assertQueryCountMatches(2, function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        });
    }

    /** @test */
    public function we_can_check_for_less_than_queries()
    {
        collect(range(1, 5))->each(function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        });

        $this->assertQueryCountMatches(5);

        $this->assertQueryCountLessThan(6);

        $this->assertQueryCountGreaterThan(4);
    }
}
