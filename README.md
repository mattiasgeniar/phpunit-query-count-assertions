# Laravel query count assertions for PHPUnit

A custom assertion for phpunit that allows you to count the amount of SQL queries used in a test. Can be used to enforce certain performance characteristics (ie: limit queries to X for a certain action).

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

## Available assertions/methods

You can use the following methods, their names should be self-explanatory:

```php
$this->assertNoQueriesExecuted();       // No queries should have been run

$this->assertQueryCountMatches(5);      // Query count should be exactly 5

$this->assertQueryCountLessThan(6);     // Should be less than 6 queries

$this->assertQueryCountGreaterThan(4);  // Should be more than 4 queries
```

All these methods can accept a closure as an extra argument. The assertion will only take in account the queries performed inside the closure. If you use this way of testing, you don't need to call `trackQueries` yourself.

```php
$this->assertQueryCountMatches(2, function() {
    // assertion will pass if exactly 2 queries happen here.
});
```

## Testing

``` bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
