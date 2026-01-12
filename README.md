# Laravel query count assertions for PHPUnit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)
[![Total Downloads](https://img.shields.io/packagist/dt/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)
[![Tests](https://img.shields.io/github/actions/workflow/status/mattiasgeniar/phpunit-query-count-assertions/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/mattiasgeniar/phpunit-query-count-assertions/actions/workflows/run-tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)

Count and assert SQL queries in your tests. Catch N+1 problems, full table scans, duplicate queries, and slow queries before they hit production.

Laravel only.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- PHPUnit 11

## Installation

You can install the package via composer:

```bash
composer require --dev mattiasgeniar/phpunit-query-count-assertions
```

## Usage

Add the trait, wrap your code in a closure:

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

class YourTest extends TestCase
{
    use AssertsQueryCounts;

    public function test_eager_loading_is_efficient(): void
    {
        $this->assertQueryCountMatches(2, function() {
            $user = User::find(1);
            $posts = $user->posts()->get();
        });
    }
}
```

## Available assertions

All assertions accept an optional closure:

```php
// Exact count
$this->assertQueryCountMatches(2, fn() => $this->loadUserWithPosts());

// Upper bounds
$this->assertQueryCountLessThan(6, fn() => $this->fetchDashboard());
$this->assertQueryCountLessThanOrEqual(5, fn() => $this->fetchDashboard());

// Lower bounds
$this->assertQueryCountGreaterThan(0, fn() => $this->warmCache());
$this->assertQueryCountGreaterThanOrEqual(1, fn() => $this->warmCache());

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

        self::trackQueries();
    }

    public function test_queries_across_method_calls(): void
    {
        $this->step1();
        $this->step2();

        $this->assertQueryCountMatches(5);
    }
}
```

## Failure messages

Failed assertions show you the actual queries:

```
Expected 1 queries, got 3.
Queries executed:
  1. [0.45ms] SELECT * FROM users WHERE id = ?
      Bindings: [1]
  2. [0.32ms] SELECT * FROM posts WHERE user_id = ?
      Bindings: [1]
  3. [0.28ms] SELECT * FROM comments WHERE post_id IN (?, ?, ?)
      Bindings: [1, 2, 3]
```

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

Runs EXPLAIN on each query:

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
       - Full table scan on 'users'
```

**Supported:** MySQL and SQLite. Other databases skip the assertion. PostgreSQL PRs welcome.

**MySQL detects:**
- Full table scans (`type=ALL`)
- Available index not used
- Using filesort
- Using temporary tables
- Using join buffer (missing index for joins)
- Full scan on NULL key

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
```

**Note:** Different bindings = different queries. `User::find(1)` and `User::find(2)` are unique.

## Row count threshold (MySQL only)

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
```

SQLite tests are skipped.

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
  2. [102.15ms] SELECT * FROM posts WHERE published = ?
     Bindings: [true]
```

## Combined efficiency assertion

`assertQueriesAreEfficient()` checks everything at once: N+1, duplicates, and missing indexes.

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
    $this->trackQueriesForEfficiency();
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

        $this->trackQueriesForEfficiency();
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

## Helper methods

```php
$queries = self::getQueriesExecuted();
$count = self::getQueryCount();
$violations = self::getLazyLoadingViolations();
$results = self::getIndexAnalysisResults();
$duplicates = self::getDuplicateQueries();
$totalTime = self::getTotalQueryTime();
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
