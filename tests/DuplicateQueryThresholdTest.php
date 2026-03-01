<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

class DuplicateQueryThresholdTest extends TestCase
{
    use AssertsQueryCounts;

    protected function tearDown(): void
    {
        // Reset to default threshold after each test
        self::setDuplicateQueryThreshold(2);

        parent::tearDown();
    }

    #[Test]
    public function it_flags_duplicates_with_default_threshold(): void
    {
        // Default threshold is 2, so queries executed 2+ times are duplicates
        $this->expectException(AssertionFailedError::class);

        $this->assertNoDuplicateQueries(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });
    }

    #[Test]
    public function it_allows_duplicates_below_threshold(): void
    {
        // Set threshold to 3, so only queries executed 3+ times are duplicates
        self::setDuplicateQueryThreshold(3);

        // This should pass - only 2 executions, threshold is 3
        $this->assertNoDuplicateQueries(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });
    }

    #[Test]
    public function it_flags_duplicates_at_threshold(): void
    {
        self::setDuplicateQueryThreshold(3);

        // This should fail - 3 executions meets threshold of 3
        $this->expectException(AssertionFailedError::class);

        $this->assertNoDuplicateQueries(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });
    }

    #[Test]
    public function it_reports_correct_count_in_failure_message(): void
    {
        self::setDuplicateQueryThreshold(3);

        try {
            $this->assertNoDuplicateQueries(function () {
                DB::select('SELECT 1');
                DB::select('SELECT 1');
                DB::select('SELECT 1');
                DB::select('SELECT 1');
            });
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Executed 4 times', $e->getMessage());
        }
    }

    #[Test]
    public function it_enforces_minimum_threshold_of_two(): void
    {
        // Setting to 0 should still use 2 as minimum
        self::setDuplicateQueryThreshold(0);

        $this->expectException(AssertionFailedError::class);

        $this->assertNoDuplicateQueries(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });
    }

    #[Test]
    public function it_works_with_efficient_queries_assertion(): void
    {
        self::setDuplicateQueryThreshold(3);

        // Should pass - only 2 executions, threshold is 3
        $this->assertQueriesAreEfficient(function () {
            // Use a query that doesn't trigger index warnings
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });
    }

    #[Test]
    public function it_preserves_threshold_across_tests(): void
    {
        self::setDuplicateQueryThreshold(5);

        // First assertion - should pass (only 3 executions, threshold is 5)
        $this->assertNoDuplicateQueries(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });

        // Second assertion - should also pass
        $this->assertNoDuplicateQueries(function () {
            DB::select('SELECT 2');
            DB::select('SELECT 2');
            DB::select('SELECT 2');
            DB::select('SELECT 2');
        });
    }
}
