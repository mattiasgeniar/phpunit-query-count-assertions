# Laravel query count assertions for PHPUnit

A custom assertion for phpunit that allows you to count the amount of SQL queries used in a test. Can be used to enforce certain performance characteristics (ie: limit queries to X for a certain action).

This works for Laravel only at the moment.

## Installation

You can install the package via composer:

```bash
composer require --dev mattiasgeniar/phpunit-db-querycounter
```

## Usage

Add the `PhpunitDbQuerycounter` trait to your test-class, initialize it in the `setup()` and you can start asserting queries.

``` php
<?php

use Mattiasgeniar\PhpunitDbQuerycounter\PhpunitDbQuerycounter;

class YourTest extends TestCase
{
    use PhpunitDbQuerycounter;

    public function setUp(): void
    {
        parent::setUp();

        PhpunitDbQuerycounter::trackQueries();
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

## Testing

``` bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
