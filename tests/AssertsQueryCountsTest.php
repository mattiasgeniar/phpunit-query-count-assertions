<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Mattiasgeniar\PhpunitQueryCountAssertions\Tests\Fixtures\Post;
use Mattiasgeniar\PhpunitQueryCountAssertions\Tests\Fixtures\User;
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

    #[Test]
    public function it_can_detect_no_lazy_loading(): void
    {
        $user = User::create(['name' => 'John']);
        Post::create(['user_id' => $user->id, 'title' => 'First Post']);
        Post::create(['user_id' => $user->id, 'title' => 'Second Post']);

        $this->assertNoLazyLoading(function () {
            $users = User::with('posts')->get();

            foreach ($users as $user) {
                $user->posts->count();
            }
        });
    }

    #[Test]
    public function it_fails_when_lazy_loading_is_detected(): void
    {
        // Need multiple users - Laravel only enables lazy loading prevention when count > 1
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);
        Post::create(['user_id' => $user1->id, 'title' => 'First Post']);
        Post::create(['user_id' => $user2->id, 'title' => 'Second Post']);

        try {
            $this->assertNoLazyLoading(function () {
                $users = User::all();

                foreach ($users as $user) {
                    // This triggers lazy loading (N+1)!
                    $user->posts->count();
                }
            });
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('Lazy loading violations detected', $message);
            $this->assertStringContainsString('User::$posts', $message);
        }
    }

    #[Test]
    public function it_can_assert_specific_lazy_loading_count(): void
    {
        $user1 = User::create(['name' => 'John']);
        $user2 = User::create(['name' => 'Jane']);
        Post::create(['user_id' => $user1->id, 'title' => 'First Post']);
        Post::create(['user_id' => $user2->id, 'title' => 'Second Post']);

        $this->assertLazyLoadingCount(2, function () {
            $users = User::all();

            foreach ($users as $user) {
                // Each user triggers one lazy load
                $user->posts->count();
            }
        });
    }

    #[Test]
    public function lazy_loading_detection_restores_original_state(): void
    {
        // Run lazy loading assertion
        $this->assertNoLazyLoading(function () {
            // No lazy loading here
        });

        // After the assertion, lazy loading should work normally again
        $user = User::create(['name' => 'John']);
        Post::create(['user_id' => $user->id, 'title' => 'Post']);

        // This should not throw - lazy loading prevention should be disabled
        $freshUser = User::first();
        $this->assertCount(1, $freshUser->posts);
    }

    private function executeQueries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::select('SELECT * FROM sqlite_master WHERE type = "table"');
        }
    }
}
