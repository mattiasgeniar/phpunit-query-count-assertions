<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests\Phalcon;

use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\PhalconDriver;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

class PhalconDriverTest extends TestCase
{
    use AssertsQueryCounts;

    private static PhalconDriver $phalconDriver;

    private static Sqlite $db;

    public static function setUpBeforeClass(): void
    {
        self::$db = new Sqlite(['dbname' => ':memory:']);

        self::$phalconDriver = new PhalconDriver;
        self::$phalconDriver->registerConnection('default', self::$db);

        // Create test tables
        self::$db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        self::$db->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id))');
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::useDriver(self::$phalconDriver);

        // Clean tables before each test
        self::$db->execute('DELETE FROM posts');
        self::$db->execute('DELETE FROM users');

        $this->trackQueries();
    }

    #[Test]
    public function it_logs_no_queries_when_none_are_executed(): void
    {
        $this->assertNoQueriesExecuted();
    }

    #[Test]
    public function it_counts_queries_when_executed(): void
    {
        self::$db->query('SELECT * FROM users');

        $this->assertQueryCountMatches(1);

        self::$db->query('SELECT * FROM users');

        $this->assertQueryCountMatches(2);
    }

    #[Test]
    public function it_can_assert_the_amount_of_queries_in_callable(): void
    {
        $this->assertQueryCountMatches(1, function () {
            self::$db->query('SELECT * FROM users');
        });

        $this->assertQueryCountMatches(2, function () {
            self::$db->query('SELECT * FROM users');
            self::$db->query('SELECT * FROM posts');
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
    public function it_includes_executed_queries_in_failure_message(): void
    {
        $this->executeQueries(3);

        try {
            $this->assertQueryCountMatches(1);
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('Expected 1 queries, got 3', $message);
            $this->assertStringContainsString('Queries executed:', $message);
            $this->assertStringContainsString('SELECT 1', $message);
        }
    }

    #[Test]
    public function it_can_detect_duplicate_queries(): void
    {
        try {
            $this->assertNoDuplicateQueries(function () {
                self::$db->query('SELECT 1');
                self::$db->query('SELECT 1');
            });
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('Duplicate queries detected', $message);
            $this->assertStringContainsString('Executed 2 times', $message);
            $this->assertStringContainsString('SELECT 1', $message);
        }
    }

    #[Test]
    public function it_passes_when_no_duplicate_queries(): void
    {
        $this->assertNoDuplicateQueries(function () {
            self::$db->query('SELECT 1');
            self::$db->query('SELECT 2');
            self::$db->query('SELECT 3');
        });
    }

    #[Test]
    public function it_skips_lazy_loading_detection(): void
    {
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('Lazy loading detection is not supported');

        $this->assertNoLazyLoading(function () {
            self::$db->query('SELECT * FROM users');
        });
    }

    #[Test]
    public function it_skips_lazy_loading_count(): void
    {
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('Lazy loading detection is not supported');

        $this->assertLazyLoadingCount(0, function () {
            self::$db->query('SELECT * FROM users');
        });
    }

    #[Test]
    public function it_can_assert_queries_use_indexes(): void
    {
        self::$db->execute("INSERT INTO users (name) VALUES ('John')");

        $this->assertAllQueriesUseIndexes(function () {
            self::$db->query('SELECT * FROM users WHERE rowid = 1');
        });
    }

    #[Test]
    public function it_detects_full_table_scans(): void
    {
        self::$db->execute("INSERT INTO users (name) VALUES ('John')");
        self::$db->execute("INSERT INTO users (name) VALUES ('Jane')");

        try {
            $this->assertAllQueriesUseIndexes(function () {
                self::$db->query("SELECT * FROM users WHERE name = 'John'");
            });
            $this->fail('Expected assertion to fail');
        } catch (AssertionFailedError $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('Queries with index issues detected', $message);
            $this->assertStringContainsString('Full table scan', $message);
            $this->assertStringContainsString('users', $message);
        }
    }

    #[Test]
    public function it_reports_connection_driver_name_as_sqlite(): void
    {
        $connection = self::$phalconDriver->getConnection('default');

        $this->assertEquals('sqlite', $connection->getDriverName());
    }

    #[Test]
    public function it_can_select_rows_via_connection_wrapper(): void
    {
        self::$db->execute("INSERT INTO users (name) VALUES ('John')");

        $connection = self::$phalconDriver->getConnection('default');
        $rows = $connection->select('SELECT * FROM users');

        $this->assertCount(1, $rows);
        $this->assertEquals('John', $rows[0]->name);
    }

    #[Test]
    public function it_can_select_one_row_via_connection_wrapper(): void
    {
        self::$db->execute("INSERT INTO users (name) VALUES ('John')");

        $connection = self::$phalconDriver->getConnection('default');
        $row = $connection->selectOne('SELECT * FROM users LIMIT 1');

        $this->assertNotNull($row);
        $this->assertEquals('John', $row->name);
    }

    #[Test]
    public function it_returns_null_when_select_one_finds_no_results(): void
    {
        $connection = self::$phalconDriver->getConnection('default');
        $row = $connection->selectOne("SELECT * FROM users WHERE name = 'nonexistent'");

        $this->assertNull($row);
    }

    private function executeQueries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            self::$db->query('SELECT 1');
        }
    }
}
