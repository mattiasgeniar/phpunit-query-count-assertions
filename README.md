# PHP query count assertions for PHPUnit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)
[![Total Downloads](https://img.shields.io/packagist/dt/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)
[![Tests](https://img.shields.io/github/actions/workflow/status/mattiasgeniar/phpunit-query-count-assertions/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/mattiasgeniar/phpunit-query-count-assertions/actions/workflows/run-tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)

Count and assert SQL queries in your tests. Catch N+1 problems, full table scans, duplicate queries, and slow queries before they hit production.

Supports Laravel, Doctrine/Symfony, and Phalcon.

## Requirements

- PHP 8.2+
- PHPUnit 11 or Pest 3
- **Laravel 11/12**, **Doctrine DBAL 4**, or **Phalcon 6+**

## Driver Compatibility

| Feature | Laravel | Doctrine | Phalcon |
|---------|:-------:|:--------:|:-------:|
| Query counting | ✅ | ✅ | ✅ |
| Query timing | ✅ | ❌ | ✅ |
| Duplicate detection | ✅ | ✅ | ✅ |
| Index analysis (EXPLAIN) | ✅ | ✅ | ✅ |
| Row count analysis | ✅ | ✅ | ✅ |
| Lazy loading detection | ✅ | ❌ | ❌ |

**Note:** Lazy loading detection requires framework-specific hooks that only Laravel provides. Assertions like `assertNoLazyLoading()` will emit a warning on Doctrine and Phalcon and pass without checking, since violations cannot be detected.

**Note:** Doctrine's logging middleware only fires before query execution, so query timing is not available. Timing assertions (`assertMaxQueryTime`, `assertTotalQueryTime`) will emit a warning and pass without checking for Doctrine.

## Installation

You can install the package via composer:

```bash
composer require --dev mattiasgeniar/phpunit-query-count-assertions
```

## Quick start

Add the trait, wrap your core logic with efficiency tracking:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

class CertificateHealthCheckTest extends TestCase
{
    use AssertsQueryCounts;

    public function test_health_checker_is_efficient(): void
    {
        // Setup - create test data (these queries aren't tracked)
        $certificate = Certificate::factory()->expired()->create();
        $run = new InMemoryRun();

        // Track only the code under test
        $this->trackQueries();
        app(CertificateHealthChecker::class)->perform($run);
        $this->assertQueriesAreEfficient();
    }
}
```

This catches N+1 queries, duplicate queries, and missing indexes in a single assertion. Your test setup (factories, seeders) stays outside the tracked block so it doesn't trigger false positives.

## Framework Setup

### Laravel (auto-detected)

No configuration needed. The package auto-detects Laravel and uses `DB::listen()` for query tracking.

### Symfony

Symfony requires the logging middleware to be registered as a service. Add this to `config/packages/test/services.yaml` (this directory is only loaded when `APP_ENV=test`, so the middleware won't affect dev or production):

```yaml
services:
    test.query_assertions.driver:
        class: Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\DoctrineDriver
        public: true

    test.query_assertions.logger:
        class: Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\DoctrineQueryLogger
        arguments:
            - '@test.query_assertions.driver'
            - 'default'

    test.query_assertions.middleware:
        class: Doctrine\DBAL\Logging\Middleware
        arguments:
            - '@test.query_assertions.logger'
        tags:
            - { name: doctrine.middleware }
```

Then in your tests:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\DoctrineDriver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// For KernelTestCase (unit/integration tests)
class YourTest extends KernelTestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->setUpQueryAssertions();
    }

    private function setUpQueryAssertions(): void
    {
        $driver = self::getContainer()->get('test.query_assertions.driver');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $driver->registerConnection('default', $connection);
        self::useDriver($driver);
    }

    public function test_queries(): void
    {
        $this->trackQueries();
        // ... your test code
        $this->assertQueryCountMatches(2);
    }
}
```

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// For WebTestCase (functional/controller tests)
class YourControllerTest extends WebTestCase
{
    use AssertsQueryCounts;

    public function test_queries(): void
    {
        $client = static::createClient(); // Boots kernel automatically

        // Set up query assertions AFTER createClient()
        $driver = self::getContainer()->get('test.query_assertions.driver');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $driver->registerConnection('default', $connection);
        self::useDriver($driver);

        $this->trackQueries();
        $client->request('GET', '/api/users');
        $this->assertQueryCountMatches(2);
    }
}
```

### Phalcon

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Mattiasgeniar\PhpunitQueryCountAssertions\Drivers\PhalconDriver;

class YourTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();

        // Get DB adapter from DI and register with driver
        $driver = new PhalconDriver();
        $driver->registerConnection('default', $this->getDI()->get('db'));

        self::useDriver($driver);
    }

    public function test_queries(): void
    {
        $this->trackQueries();
        // ... your test code
        $this->assertQueryCountMatches(2);
    }
}
```

### What it catches

- **N+1 queries** — lazy loading violations
- **Duplicate queries** — same query executed multiple times
- **Missing indexes** — full table scans, unused indexes
- **Filesort & temp tables** — common MySQL performance issues

When something fails, you get actionable output with the exact queries and their locations (file:line).

## Query count assertions

For cases where you need precise control over query counts:

```php
// Exact count
$this->assertQueryCountMatches(2, fn() => $this->loadUserWithPosts());

// Upper bounds
$this->assertQueryCountLessThan(6, fn() => $this->fetchDashboard());

// No queries (cached?)
$this->assertNoQueriesExecuted(fn() => $this->getCachedData());

// Range
$this->assertQueryCountBetween(3, 7, fn() => $this->complexOperation());
```

## Tracking queries across the entire test

If you need to count queries outside closures, initialize tracking in `setUp()`:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

class YourTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trackQueries();
    }

    public function test_queries_across_method_calls(): void
    {
        $this->step1();
        $this->step2();

        $this->assertQueryCountMatches(5);
    }
}
```

## Multi-connection support

By default, `trackQueries()` captures queries from **all database connections** — not just the default one. This is useful when your application uses read replicas, separate analytics databases, or tenant-specific connections.

```php
// Track all connections (default)
$this->trackQueries();

DB::select('SELECT 1');                         // Tracked
DB::connection('replica')->select('SELECT 2');  // Also tracked

$queries = self::getQueriesExecuted();
// $queries[0]['connection'] === 'mysql'
// $queries[1]['connection'] === 'replica'
```

### Filtering to specific connections

You can optionally filter to only track specific connection(s):

```php
// Track only the replica connection
$this->trackQueries('replica');

// Track multiple specific connections
$this->trackQueries(['mysql', 'replica']);
```

This is useful when:
- Your test setup runs queries on different connections that you don't want to count
- You want to verify that specific queries go to the right connection
- You're debugging connection routing in read/write split setups

## Failure messages

Failed assertions show you the actual queries:

```
Expected 1 queries, got 3.
Queries executed:
  1. [0.45ms] SELECT * FROM users WHERE id = ?
      Bindings: [1]
      Locations:
        #1: tests/Feature/UserTest.php:42
  2. [0.32ms] SELECT * FROM posts WHERE user_id = ?
      Bindings: [1]
      Locations:
        #1: tests/Feature/UserTest.php:46
  3. [0.28ms] SELECT * FROM comments WHERE post_id IN (?, ?, ?)
      Bindings: [1, 2, 3]
      Locations:
        #1: tests/Feature/UserTest.php:50
```

Locations (file:line) are shown for each query when available. This applies to duplicate, index, row count, timing, and total time failures too.

## Lazy loading / N+1 detection

Uses Laravel's built-in lazy loading prevention:

```php
// Fails if any lazy loading occurs
$this->assertNoLazyLoading(function () {
    $users = User::all();

    foreach ($users as $user) {
        $user->posts->count(); // N+1 query
    }
});

// Passes with eager loading
$this->assertNoLazyLoading(function () {
    $users = User::with('posts')->get();

    foreach ($users as $user) {
        $user->posts->count();
    }
});

// Assert specific number of violations
$this->assertLazyLoadingCount(2, function () {
    // ...
});
```

Output:

```
Lazy loading violations detected:
Violations:
  1. App\Models\User::$posts
  2. App\Models\User::$posts
```

**Note:** Laravel only triggers this when loading multiple models. Single model fetches won't trigger violations.

## Index usage / full table scan detection

Runs EXPLAIN on each query to detect performance issues:

```php
$this->assertAllQueriesUseIndexes(function () {
    User::find(1); // Uses primary key, passes
});

$this->assertAllQueriesUseIndexes(function () {
    User::where('name', 'John')->get(); // Full table scan, fails
});
```

Output:

```
Queries with index issues detected:

  1. SELECT * FROM users WHERE name = ?
     Bindings: ["John"]
     Issues:
       - [ERROR] Full table scan on 'users'
     Locations:
       #1: tests/Feature/UserTest.php:42
```

### Supported databases

- **MySQL** (5.6+) - Full support with JSON EXPLAIN
- **MariaDB** - Full support with tabular EXPLAIN
- **SQLite** - Index analysis supported, row counting not available

Other databases will emit a warning and pass without checking. See [Custom analysers](#custom-analysers) to add support for additional databases.

### What gets analyzed

Only queries that support EXPLAIN are analyzed:
- SELECT queries
- UPDATE queries
- DELETE queries
- INSERT...SELECT queries
- REPLACE...SELECT queries

Plain INSERT, CREATE, DROP, and other DDL statements are skipped.

### Issue severity levels

Issues are classified by severity and shown with prefixes in the output:

| Severity | Prefix | Meaning |
|----------|--------|---------|
| Error | `[ERROR]` | Critical issues that almost always need fixing (full table scans, unused available indexes) |
| Warning | `[WARNING]` | Potential issues that may be acceptable in some cases (filesort, temporary tables, full index scans) |
| Info | `[INFO]` | Informational notes (low filter efficiency, co-routine usage) |

By default, only errors and warnings cause assertion failures.
Informational issues are printed as `[INFO]` notices (non-failing) so they're visible even when tests pass.

### MySQL / MariaDB detects

- Full table scans (`type=ALL`)
- Full index scans (`type=index`)
- Index available but not used
- Using filesort
- Using temporary tables
- Using join buffer (missing index for joins)
- Full scan on NULL key
- Low filter efficiency (examining many rows, keeping few)
- High query cost (when threshold configured)

### SQLite detects

- Full table scans (`SCAN table`)
- Temporary B-tree usage for ORDER BY, DISTINCT, GROUP BY
- Co-routine subqueries
- **FK constraint checks** - When a DELETE/UPDATE triggers scans on related tables, the message includes FK details:
  ```
  [WARNING] Full table scan on 'posts' (FK constraint check: posts.user_id → users.id (ON DELETE CASCADE))
  ```

### Small table optimization

Full table scans, full index scans, and "index available but not used" warnings on tables with fewer than 10 rows are ignored by default, since scanning tiny tables is often faster than using an index. MySQL's docs note this is common for tables with fewer than 10 rows: https://dev.mysql.com/doc/refman/8.4/en/table-scan-avoidance.html. See [Configurable thresholds](#configurable-thresholds) to adjust this.

## Duplicate query detection

Same query executed multiple times? You'll know:

```php
$this->assertNoDuplicateQueries(function () {
    User::find(1);
    User::find(1); // Duplicate
});
```

Output:

```
Duplicate queries detected:

  1. Executed 2 times: SELECT * FROM users WHERE id = ?
     Bindings: [1]
     Locations:
       #1: tests/Feature/UserTest.php:42
       #2: tests/Feature/UserTest.php:43
```

**Note:** Different bindings = different queries. `User::find(1)` and `User::find(2)` are unique.

## Row count threshold (MySQL / MariaDB only)

```php
$this->assertMaxRowsExamined(1000, function () {
    User::where('status', 'active')->get();
});
```

Output:

```
Queries examining more than 1000 rows:

  1. SELECT * FROM users WHERE status = ?
     Bindings: ["active"]
     Rows examined: 15000
     Locations:
       #1: tests/Feature/UserTest.php:42
```

SQLite doesn't provide row estimates in EXPLAIN QUERY PLAN, so a warning is emitted and the assertion passes without checking.

## Query timing assertions

```php
// No single query over 100ms
$this->assertMaxQueryTime(100, function () {
    User::with('posts', 'comments')->get();
});

// Total time under 500ms
$this->assertTotalQueryTime(500, function () {
    $users = User::all();
    $posts = Post::where('published', true)->get();
    $stats = DB::select('SELECT COUNT(*) FROM analytics');
});
```

Output:

```
Queries exceeding 100ms:

  1. [245.32ms] SELECT * FROM users
     Locations:
       #1: tests/Feature/UserTest.php:42
  2. [102.15ms] SELECT * FROM posts WHERE published = ?
     Bindings: [true]
     Locations:
       #1: tests/Feature/UserTest.php:43
```

## Combined efficiency assertion

`assertQueriesAreEfficient()` checks everything at once: N+1, duplicates, and missing indexes. The [Quick start](#quick-start) shows the recommended inline pattern. Below are alternative approaches.

### With a closure

```php
$this->assertQueriesAreEfficient(function () {
    $users = User::with('posts')->get();

    foreach ($users as $user) {
        $user->posts->count();
    }
});
```

### Pest: beforeEach()

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

uses(AssertsQueryCounts::class);

beforeEach(function () {
    $this->trackQueries();
});

it('loads the dashboard efficiently', function () {
    $this->get('/dashboard');

    $this->assertQueriesAreEfficient();
});

it('processes orders without N+1', function () {
    $order = Order::factory()->create();

    $this->post("/orders/{$order->id}/process");

    $this->assertQueriesAreEfficient();
});
```

### PHPUnit: setUp()

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trackQueries();
    }

    public function test_dashboard_loads_efficiently(): void
    {
        $this->get('/dashboard');

        $this->assertQueriesAreEfficient();
    }

    public function test_order_processing_has_no_n_plus_one(): void
    {
        $order = Order::factory()->create();

        $this->post("/orders/{$order->id}/process");

        $this->assertQueriesAreEfficient();
    }
}
```

### Paranoid mode (automatic checks on every test)

Want to automatically check every test for query efficiency issues? You can use `afterEach()` hooks to run assertions globally. This is aggressive and may surface many issues - use with caution.

**Pest (in `tests/Pest.php`):**

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

pest()->extend(Tests\TestCase::class)
    ->use(AssertsQueryCounts::class)
    ->beforeEach(fn () => self::trackQueries())
    ->afterEach(fn () => $this->assertQueriesAreEfficient())
    ->in('Feature');
```

**PHPUnit (base test class):**

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

abstract class TestCase extends BaseTestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trackQueries();
    }

    protected function tearDown(): void
    {
        $this->assertQueriesAreEfficient();
        parent::tearDown();
    }
}
```

This will fail any test that has N+1 queries, duplicate queries, or missing indexes. Consider starting with a subset of tests rather than your entire suite.

### Opting out with `#[DisableQueryTracking]`

In paranoid mode, some tests may need to opt out — for example, tests with heavy seeders, migrations, or tests that intentionally execute many queries. Use the `#[DisableQueryTracking]` attribute to skip tracking for specific tests or entire classes:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\Attributes\DisableQueryTracking;

class DashboardTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trackQueries();
    }

    protected function tearDown(): void
    {
        $this->assertQueriesAreEfficient();
        parent::tearDown();
    }

    // This test is checked normally
    public function test_dashboard_loads_efficiently(): void
    {
        $this->get('/dashboard');
    }

    // This test opts out of query tracking
    #[DisableQueryTracking]
    public function test_heavy_seeder_setup(): void
    {
        $this->seed(LargeDatasetSeeder::class);
        // ...
    }
}
```

You can also disable tracking for an entire test class:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\Attributes\DisableQueryTracking;

#[DisableQueryTracking]
class MigrationTest extends TestCase
{
    use AssertsQueryCounts;

    // All tests in this class skip query tracking
}
```

When `#[DisableQueryTracking]` is present, `trackQueries()` returns early without setting up listeners, and all assertions (`assertQueriesAreEfficient()`, `assertQueryCountMatches()`, etc.) pass silently.

## Configurable thresholds

### MySQL analyser options

The MySQL analyser has configurable thresholds that can be set by registering a customized instance:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\MySQLAnalyser;

class YourTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();

        // Flag full table scans only on tables with 500+ rows (default: 10)
        self::registerQueryAnalyser(
            (new MySQLAnalyser)->withMinRowsForScanWarning(500)
        );

        // Also flag queries with cost above threshold
        self::registerQueryAnalyser(
            (new MySQLAnalyser)
                ->withMinRowsForScanWarning(500)
                ->withMaxCost(1000.0)
        );
    }
}
```

| Method | Default | Description |
|--------|---------|-------------|
| `withMinRowsForScanWarning(int)` | 10 | Minimum rows to flag full table scans, full index scans, and unused index warnings |
| `withMaxCost(float)` | null (disabled) | Maximum query cost before flagging as a warning |

## Custom analysers

Add support for additional databases by implementing the `QueryAnalyser` interface:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryAnalyser;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\QueryIssue;
use Mattiasgeniar\PhpunitQueryCountAssertions\QueryAnalysers\Concerns\ExplainsQueries;

class PostgreSQLAnalyser implements QueryAnalyser
{
    use ExplainsQueries; // Provides canExplain() for SELECT, UPDATE, DELETE, INSERT...SELECT

    public function supports(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    public function explain(ConnectionInterface $connection, string $sql, array $bindings): array
    {
        return $connection->select('EXPLAIN (FORMAT JSON) ' . $sql, $bindings);
    }

    public function analyzeIndexUsage(array $explainResults, ?string $sql = null, ?ConnectionInterface $connection = null): array
    {
        $issues = [];

        // Parse PostgreSQL EXPLAIN JSON output
        // Look for "Seq Scan" nodes (full table scans)
        // Return QueryIssue instances for problems found
        // Use $sql to detect FK constraint checks (see SQLiteAnalyser for example)

        return $issues;
    }

    public function supportsRowCounting(): bool
    {
        return true; // PostgreSQL provides row estimates
    }

    public function getRowsExamined(array $explainResults): int
    {
        // Sum up "Plan Rows" from EXPLAIN output
        return 0;
    }
}
```

Register your custom analyser in your test's `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();

    self::registerQueryAnalyser(new PostgreSQLAnalyser);
}
```

Custom analysers are checked before the built-in MySQL and SQLite analysers.

## Helper methods

These methods let you inspect query data for custom assertions or debugging:

```php
// Get all executed queries with their SQL, bindings, timing, and connection
$queries = self::getQueriesExecuted();
// Returns: [['query' => 'SELECT...', 'bindings' => [...], 'time' => 0.45, 'connection' => 'mysql'], ...]

// Get total number of queries executed
$count = self::getQueryCount();

// Get lazy loading violations from the last assertion
$violations = self::getLazyLoadingViolations();
// Returns: [['model' => 'App\Models\User', 'relation' => 'posts'], ...]

// Get detailed EXPLAIN results from the last index analysis
$results = self::getIndexAnalysisResults();
// Returns: [['query' => '...', 'bindings' => [...], 'issues' => [...], 'explain' => [...]], ...]

// Get duplicate queries from the last check
$duplicates = self::getDuplicateQueries();
// Returns: ['key' => ['count' => 2, 'query' => '...', 'bindings' => [...], 'locations' => [['file' => '...', 'line' => 123]]], ...]

// Get total query execution time in milliseconds
$totalTime = self::getTotalQueryTime();
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
