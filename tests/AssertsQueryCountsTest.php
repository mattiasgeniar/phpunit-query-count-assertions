<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use PHPUnit\Framework\AssertionFailedError;
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
    public function it_can_check_for_less_than_queries(): void
    {
        $this->executeQueries(5);

        $this->assertQueryCountMatches(5);
        $this->assertQueryCountLessThan(6);
        $this->assertQueryCountGreaterThan(4);
    }

    #[Test]
    public function it_can_check_for_less_than_or_equal_queries(): void
    {
        $this->executeQueries(5);

        $this->assertQueryCountLessThanOrEqual(5);
        $this->assertQueryCountLessThanOrEqual(6);
    }

    #[Test]
    public function it_can_check_for_greater_than_or_equal_queries(): void
    {
        $this->executeQueries(5);

        $this->assertQueryCountGreaterThanOrEqual(5);
        $this->assertQueryCountGreaterThanOrEqual(4);
    }

    #[Test]
    public function it_can_check_for_queries_between_range(): void
    {
        $this->executeQueries(5);

        $this->assertQueryCountBetween(3, 7);
        $this->assertQueryCountBetween(5, 5);
        $this->assertQueryCountBetween(5, 10);
        $this->assertQueryCountBetween(1, 5);
    }

    #[Test]
    public function it_can_use_closure_with_less_than_or_equal(): void
    {
        $this->assertQueryCountLessThanOrEqual(2, function () {
            DB::select('SELECT 1');
            DB::select('SELECT 2');
        });
    }

    #[Test]
    public function it_can_use_closure_with_greater_than_or_equal(): void
    {
        $this->assertQueryCountGreaterThanOrEqual(2, function () {
            DB::select('SELECT 1');
            DB::select('SELECT 2');
        });
    }

    #[Test]
    public function it_can_use_closure_with_between(): void
    {
        $this->assertQueryCountBetween(1, 3, function () {
            DB::select('SELECT 1');
            DB::select('SELECT 2');
        });
    }

    #[Test]
    public function failure_message_includes_executed_queries(): void
    {
        $this->executeQueries(3);

        try {
            $this->assertQueryCountMatches(1);
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('Expected 1 queries, got 3', $message);
            $this->assertStringContainsString('Queries executed:', $message);
            $this->assertStringContainsString('SELECT * FROM sqlite_master', $message);
        }
    }

    #[Test]
    public function failure_message_shows_no_queries_when_none_executed(): void
    {
        try {
            $this->assertQueryCountMatches(1);
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('No queries were executed', $e->getMessage());
        }
    }

    private function executeQueries(int $count): void
    {
        collect(range(1, $count))->each(function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        });
    }
}
