# Laravel query count assertions for PHPUnit

[![Tests](https://github.com/mattiasgeniar/phpunit-query-count-assertions/actions/workflows/run-tests.yml/badge.svg)](https://github.com/mattiasgeniar/phpunit-query-count-assertions/actions/workflows/run-tests.yml)

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

Add the `AssertsQueryCounts` trait to your test-class, initialize it in the `setUp()` and you can start asserting queries.

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

    public function test_your_tests_go_here(): void
    {
        $this->assertNoQueriesExecuted();
    }
}
```

## Available assertions

You can use the following methods:

```php
// Exact count
$this->assertNoQueriesExecuted();           // No queries should have been run
$this->assertQueryCountMatches(5);          // Exactly 5 queries

// Upper bounds
$this->assertQueryCountLessThan(6);         // Fewer than 6 queries (strict)
$this->assertQueryCountLessThanOrEqual(5);  // At most 5 queries

// Lower bounds
$this->assertQueryCountGreaterThan(4);         // More than 4 queries (strict)
$this->assertQueryCountGreaterThanOrEqual(5);  // At least 5 queries

// Range
$this->assertQueryCountBetween(3, 7);       // Between 3 and 7 queries (inclusive)
```

## Closure-based assertions

All methods accept a closure as an extra argument. The assertion will only count queries performed inside the closure. When using closures, you don't need to call `trackQueries` in `setUp()`.

```php
$this->assertQueryCountMatches(2, function() {
    // Assertion passes if exactly 2 queries happen here
    $user = User::find(1);
    $posts = $user->posts()->get();
});

$this->assertQueryCountBetween(1, 3, function() {
    // Assertion passes if 1-3 queries happen here
});
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

## Helper methods

```php
// Get all executed queries as an array
$queries = self::getQueriesExecuted();

// Get the current query count
$count = self::getQueryCount();
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
