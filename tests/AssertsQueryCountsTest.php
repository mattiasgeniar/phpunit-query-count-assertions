<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

class QueryCounterTest extends TestCase
{
    use AssertsQueryCounts;

    public function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function no_queries_are_logged_when_none_are_executed()
    {
        AssertsQueryCounts::trackQueries();
        $this->assertNoQueriesExecuted();

        AssertsQueryCounts::trackQueries('other');
        $this->assertNoQueriesExecuted(null, 'other');
    }

    /** @test */
    public function no_queries_are_logged_when_none_are_executed_in_callable()
    {
        $this->assertNoQueriesExecuted(function () {
        });

        $this->assertNoQueriesExecuted(function () {
        }, 'other');
    }

    /** @test */
    public function queries_are_counted_when_executed()
    {
        AssertsQueryCounts::trackQueries();
        DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        $this->assertQueryCountMatches(1);
        $this->assertQueryCountMatches(0, null, 'other');

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        $this->assertQueryCountMatches(2);
        $this->assertQueryCountMatches(0, null, 'other');

        AssertsQueryCounts::trackQueries('other');
        DB::connection('other')->select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        $this->assertQueryCountMatches(2);
        $this->assertQueryCountMatches(1, null, 'other');

        DB::connection('other')->select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        $this->assertQueryCountMatches(2);
        $this->assertQueryCountMatches(2, null, 'other');
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

        $this->assertQueryCountMatches(1, function () {
            DB::connection('other')->select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        }, 'other');

        $this->assertQueryCountMatches(2, function () {
            DB::connection('other')->select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
            DB::connection('other')->select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        }, 'other');
    }

    /** @test */
    public function we_can_check_for_less_than_queries()
    {
        AssertsQueryCounts::trackQueries();
        collect(range(1, 5))->each(function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        });
        $this->assertQueryCountMatches(5);
        $this->assertQueryCountLessThan(6);
        $this->assertQueryCountGreaterThan(4);

        AssertsQueryCounts::trackQueries('other');
        collect(range(1, 5))->each(function () {
            DB::connection('other')->select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        });
        $this->assertQueryCountMatches(5, null, 'other');
        $this->assertQueryCountLessThan(6, null, 'other');
        $this->assertQueryCountGreaterThan(4, null, 'other');
    }
}
