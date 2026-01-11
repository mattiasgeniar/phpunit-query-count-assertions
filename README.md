# Laravel query count assertions for PHPUnit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)
[![Total Downloads](https://img.shields.io/packagist/dt/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)
[![Tests](https://img.shields.io/github/actions/workflow/status/mattiasgeniar/phpunit-query-count-assertions/run-tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/mattiasgeniar/phpunit-query-count-assertions/actions/workflows/run-tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/mattiasgeniar/phpunit-query-count-assertions.svg?style=flat-square)](https://packagist.org/packages/mattiasgeniar/phpunit-query-count-assertions)

A custom assertion for phpunit that allows you to count the number of SQL queries used in a test. Can be used to enforce certain performance characteristics (ie: limit queries to X for a certain action).

This works for Laravel only at the moment.

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

Add the `AssertsQueryCounts` trait to your test-class and wrap your code in a closure - no additional setup required:

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

All assertions accept an optional closure. When provided, only queries within the closure are counted:

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

If you need to count queries outside of closures (e.g., counting queries across multiple method calls), initialize tracking in `setUp()`:

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

When an assertion fails, you'll see the actual queries that were executed:

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

Detect N+1 query problems by leveraging Laravel's built-in lazy loading prevention:

```php
// Fails if any lazy loading occurs (N+1 detected)
$this->assertNoLazyLoading(function () {
    $users = User::all();

    foreach ($users as $user) {
        $user->posts->count(); // N+1! This will fail
    }
});

// Passes - using eager loading
$this->assertNoLazyLoading(function () {
    $users = User::with('posts')->get();

    foreach ($users as $user) {
        $user->posts->count(); // Already loaded, no N+1
    }
});

// Assert specific number of lazy loading violations
$this->assertLazyLoadingCount(2, function () {
    // Expect exactly 2 lazy loads
});
```

When a lazy loading violation is detected, you'll see which relations were lazy loaded:

```
Lazy loading violations detected:
Violations:
  1. App\Models\User::$posts
  2. App\Models\User::$posts
```

**Note:** Laravel only triggers lazy loading prevention when loading multiple models. A single model fetch won't trigger violations.

## Index usage / full table scan detection

Detect queries that don't use indexes by running EXPLAIN on each query:

```php
// Fails if any query does a full table scan
$this->assertAllQueriesUseIndexes(function () {
    // This uses the primary key index - passes
    User::find(1);
});

$this->assertAllQueriesUseIndexes(function () {
    // This does a full table scan - fails!
    User::where('name', 'John')->get();
});
```

When a full table scan is detected, you'll see which queries have issues:

```
Queries with index issues detected:

  1. SELECT * FROM users WHERE name = ?
     Bindings: ["John"]
     Issues:
       - Full table scan on 'users'
```

**Supported databases:** MySQL and SQLite. Other databases will skip the assertion. PostgreSQL support welcome via PR.

**MySQL-specific detection:**
- Full table scans (`type=ALL`)
- Available index not used
- Using filesort
- Using temporary tables
- Using join buffer (missing index for joins)
- Full scan on NULL key

## Duplicate query detection

Detect when the same query is executed multiple times (potential caching opportunity):

```php
$this->assertNoDuplicateQueries(function () {
    User::find(1);
    User::find(1); // Duplicate! Should cache or refactor
});
```

When duplicates are found:

```
Duplicate queries detected:

  1. Executed 2 times: SELECT * FROM users WHERE id = ?
     Bindings: [1]
```

**Note:** Queries with different bindings are not considered duplicates. `User::find(1)` and `User::find(2)` are unique queries.

## Row count threshold (MySQL only)

Assert that queries don't examine too many rows:

```php
// Fails if any query examines more than 1000 rows
$this->assertMaxRowsExamined(1000, function () {
    User::where('status', 'active')->get();
});
```

When the threshold is exceeded:

```
Queries examining more than 1000 rows:

  1. SELECT * FROM users WHERE status = ?
     Bindings: ["active"]
     Rows examined: 15000
```

**Note:** This assertion only works on MySQL. SQLite tests will be skipped.

## Query timing assertions

Enforce performance budgets by asserting query execution times:

```php
// Fail if any single query takes longer than 100ms
$this->assertMaxQueryTime(100, function () {
    User::with('posts', 'comments')->get();
});

// Fail if total query time exceeds 500ms budget
$this->assertTotalQueryTime(500, function () {
    $users = User::all();
    $posts = Post::where('published', true)->get();
    $stats = DB::select('SELECT COUNT(*) FROM analytics');
});
```

When a query exceeds the threshold:

```
Queries exceeding 100ms:

  1. [245.32ms] SELECT * FROM users
  2. [102.15ms] SELECT * FROM posts WHERE published = ?
     Bindings: [true]
```

When total time exceeds budget:

```
Total query time 623.45ms exceeds budget of 500ms.
Queries executed:
  1. [245.32ms] SELECT * FROM users
  2. [102.15ms] SELECT * FROM posts WHERE published = ?
      Bindings: [true]
  3. [275.98ms] SELECT COUNT(*) FROM analytics
```

## Helper methods

```php
// Get all executed queries as an array
$queries = self::getQueriesExecuted();

// Get the current query count
$count = self::getQueryCount();

// Get lazy loading violations (after using assertNoLazyLoading)
$violations = self::getLazyLoadingViolations();

// Get index analysis results (after using assertAllQueriesUseIndexes)
$results = self::getIndexAnalysisResults();

// Get duplicate queries (after using assertNoDuplicateQueries)
$duplicates = self::getDuplicateQueries();

// Get total query execution time in milliseconds
$totalTime = self::getTotalQueryTime();
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
