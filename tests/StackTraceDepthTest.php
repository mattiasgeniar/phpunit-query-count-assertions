<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

class StackTraceDepthTest extends TestCase
{
    use AssertsQueryCounts;

    protected function tearDown(): void
    {
        // Reset to default depth after each test
        self::setStackTraceDepth(1);

        parent::tearDown();
    }

    #[Test]
    public function it_captures_single_frame_by_default(): void
    {
        try {
            $this->assertNoDuplicateQueries(function () {
                DB::select('SELECT 1');
                DB::select('SELECT 1');
            });
        } catch (AssertionFailedError) {
        }

        $duplicates = self::getDuplicateQueries();
        $first = array_values($duplicates)[0];

        // Each execution should have exactly 1 frame
        foreach ($first['locations'] as $frames) {
            $this->assertCount(1, $frames);
        }
    }

    #[Test]
    public function it_captures_multiple_frames_when_configured(): void
    {
        self::setStackTraceDepth(3);

        try {
            $this->assertNoDuplicateQueries(function () {
                DB::select('SELECT 1');
                DB::select('SELECT 1');
            });
        } catch (AssertionFailedError) {
        }

        $duplicates = self::getDuplicateQueries();
        $first = array_values($duplicates)[0];

        // Each execution should have up to 3 frames
        foreach ($first['locations'] as $frames) {
            $this->assertGreaterThanOrEqual(1, count($frames));
            $this->assertLessThanOrEqual(3, count($frames));

            // All frames should have file and line
            foreach ($frames as $frame) {
                $this->assertArrayHasKey('file', $frame);
                $this->assertArrayHasKey('line', $frame);
            }
        }
    }

    #[Test]
    public function it_shows_multiple_frames_in_failure_message(): void
    {
        self::setStackTraceDepth(2);

        try {
            $this->assertNoDuplicateQueries(function () {
                $this->helperThatRunsQuery();
                $this->helperThatRunsQuery();
            });
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            // Should contain the arrow indicator for additional frames
            $this->assertStringContainsString('Locations:', $message);
            // When there are multiple frames, they show with ← prefix
            // The exact output depends on stack depth, but we should have location info
            $this->assertStringContainsString('#1:', $message);
            $this->assertStringContainsString('#2:', $message);
        }
    }

    #[Test]
    public function it_enforces_minimum_depth_of_one(): void
    {
        self::setStackTraceDepth(0);

        try {
            $this->assertNoDuplicateQueries(function () {
                DB::select('SELECT 1');
                DB::select('SELECT 1');
            });
        } catch (AssertionFailedError) {
        }

        $duplicates = self::getDuplicateQueries();
        $first = array_values($duplicates)[0];

        // Even with 0, should still capture at least 1 frame
        foreach ($first['locations'] as $frames) {
            $this->assertGreaterThanOrEqual(1, count($frames));
        }
    }

    #[Test]
    public function it_preserves_depth_across_tracking_sessions(): void
    {
        self::setStackTraceDepth(3);

        // First tracking session
        $this->trackQueries();
        DB::select('SELECT 1');

        // Second tracking session - depth setting should persist
        try {
            $this->assertNoDuplicateQueries(function () {
                DB::select('SELECT 2');
                DB::select('SELECT 2');
            });
        } catch (AssertionFailedError) {
        }

        $duplicates = self::getDuplicateQueries();
        $first = array_values($duplicates)[0];

        // Should still use depth of 3
        foreach ($first['locations'] as $frames) {
            $this->assertLessThanOrEqual(3, count($frames));
        }
    }

    private function helperThatRunsQuery(): void
    {
        DB::select('SELECT 1');
    }
}
