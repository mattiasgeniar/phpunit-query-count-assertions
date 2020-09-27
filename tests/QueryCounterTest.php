<?php

namespace Mattiasgeniar\PhpunitDbQuerycounter\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitDbQuerycounter\PhpunitDbQuerycounter;

class QueryCounterTest extends TestCase
{
    use PhpunitDbQuerycounter;

    public function setUp(): void
    {
        parent::setUp();

        PhpunitDbQuerycounter::trackQueries();
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
    public function we_can_check_for_less_than_queries()
    {
        collect(range(1,5))->each(function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"'); // SQLite query
        });

        $this->assertQueryCountMatches(5);

        $this->assertQueryCountLessThan(6);

        $this->assertQueryCountGreaterThan(4);
    }
}
