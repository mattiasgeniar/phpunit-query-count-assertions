<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use PHPUnit\Framework\Attributes\Test;

class IgnorePatternsTest extends TestCase
{
    use AssertsQueryCounts;

    protected function tearDown(): void
    {
        // Clean up global patterns after each test
        self::setIgnoreQueryPatterns([]);

        parent::tearDown();
    }

    #[Test]
    public function it_ignores_queries_matching_contains_pattern(): void
    {
        self::setIgnoreQueryPatterns(['sqlite_master']);

        $this->trackQueries();

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');

        $this->assertQueryCountMatches(1);
    }

    #[Test]
    public function it_ignores_queries_matching_regex_pattern(): void
    {
        self::setIgnoreQueryPatterns(['/^SELECT \* FROM sqlite_master/']);

        $this->trackQueries();

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');

        $this->assertQueryCountMatches(1);
    }

    #[Test]
    public function it_supports_multiple_patterns(): void
    {
        self::setIgnoreQueryPatterns([
            'sqlite_master',
            '/SELECT 2/',
        ]);

        $this->trackQueries();

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');
        DB::select('SELECT 2');
        DB::select('SELECT 3');

        $this->assertQueryCountMatches(2);
    }

    #[Test]
    public function it_merges_session_patterns_with_global_patterns(): void
    {
        self::setIgnoreQueryPatterns(['sqlite_master']);

        $this->trackQueries();

        self::addIgnoreQueryPatterns(['/SELECT 2/']);

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');
        DB::select('SELECT 2');

        $this->assertQueryCountMatches(1);
    }

    #[Test]
    public function it_clears_session_patterns_on_track_queries(): void
    {
        self::setIgnoreQueryPatterns(['sqlite_master']);
        self::addIgnoreQueryPatterns(['/SELECT 2/']);

        // This should clear session patterns
        $this->trackQueries();

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');
        DB::select('SELECT 2');

        // Session patterns were cleared, so SELECT 2 should be counted
        $this->assertQueryCountMatches(2);
    }

    #[Test]
    public function it_clears_session_patterns_manually(): void
    {
        $this->trackQueries();

        self::addIgnoreQueryPatterns(['/SELECT 1/']);
        self::clearSessionIgnorePatterns();

        DB::select('SELECT 1');

        $this->assertQueryCountMatches(1);
    }

    #[Test]
    public function it_clears_global_patterns_with_empty_array(): void
    {
        self::setIgnoreQueryPatterns(['sqlite_master']);
        self::setIgnoreQueryPatterns([]);

        $this->trackQueries();

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');

        $this->assertQueryCountMatches(1);
    }

    #[Test]
    public function it_supports_regex_with_case_insensitive_flag(): void
    {
        self::setIgnoreQueryPatterns(['/SELECT.*SQLITE_MASTER/i']);

        $this->trackQueries();

        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');

        $this->assertQueryCountMatches(1);
    }

    #[Test]
    public function it_does_not_ignore_queries_not_matching_patterns(): void
    {
        self::setIgnoreQueryPatterns(['sessions', '/^INSERT/']);

        $this->trackQueries();

        DB::select('SELECT 1');
        DB::select('SELECT 2');
        DB::select('SELECT 3');

        $this->assertQueryCountMatches(3);
    }

    #[Test]
    public function it_works_with_closure_based_assertions(): void
    {
        self::setIgnoreQueryPatterns(['sqlite_master']);

        $this->assertQueryCountMatches(1, function () {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
            DB::select('SELECT 1');
        });
    }

    #[Test]
    public function it_preserves_global_patterns_across_track_queries_calls(): void
    {
        self::setIgnoreQueryPatterns(['sqlite_master']);

        $this->trackQueries();
        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 1');
        $this->assertQueryCountMatches(1);

        // Track queries again - global patterns should still be active
        $this->trackQueries();
        DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        DB::select('SELECT 2');
        $this->assertQueryCountMatches(1);
    }
}
