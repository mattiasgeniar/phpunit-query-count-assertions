# Laravel query count assertions for PHPUnit

A custom assertion for phpunit that allows you to count the number of SQL queries used in a test. Can be used to enforce certain performance characteristics (ie: limit queries to X for a certain action).

This works for Laravel only at the moment.

## Installation

You can install the package via composer:

```bash
composer require --dev mattiasgeniar/phpunit-query-count-assertions
```

## Usage

Add the `AssertsQueryCounts` trait to your test-class, initialize it in the `setup()` and you can start asserting queries.

```php
use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;

class YourTest extends TestCase
{
    use AssertsQueryCounts;

    public function setUp(): void
    {
        parent::setUp();

        AssertsQueryCounts::trackQueries();
    }

    /** @test */
    public function your_tests_go_here()
    {
        $this->assertNoQueriesExecuted();
    }
}
```

If you want to track queries from another database, you can specify the connection name during initialization:

```php
AssertQueryCount::trackQueries('sqlite');
```

## Available assertions/methods

You can use the following methods, their names should be self-explanatory:

```php
$this->assertNoQueriesExecuted();       // No queries should have been run

$this->assertQueryCountMatches(5);      // Query count should be exactly 5

$this->assertQueryCountLessThan(6);     // Should be less than 6 queries

$this->assertQueryCountGreaterThan(4);  // Should be more than 4 queries
```

All these methods can accept a closure as an extra argument. The assertion will only take in account the queries performed inside the closure. If you use this way of testing, you don't need to call `trackQueries` yourself. You can also specify
the name of the database connection after the closure.

```php
$this->assertQueryCountMatches(2, function() {
    // assertion will pass if exactly 2 queries happen here.
});

$this->assertQueryCountLessThan(3, function() {
    // assertion will pass if less than 3 queries happen in the sqlite database.
}, 'sqlite');
```

## Testing

``` bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
