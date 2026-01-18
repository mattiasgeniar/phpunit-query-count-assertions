# Changelog

All notable changes to `phpunit-db-querycounter` will be documented in this file

## Unreleased

### Changed

- Consolidated `trackQueriesForEfficiency()` into `trackQueries()`. The single `trackQueries()` method now enables all tracking features including N+1/lazy loading detection. `trackQueriesForEfficiency()` is deprecated and will be removed in the next major version.

### Fixed

- Skip unused index warnings for small tables where MySQL optimizer prefers full scans

## 1.1.7 - 2026-01-14

### What's Changed

* Add query location reporting to assertion failures by @mattiasgeniar in https://github.com/mattiasgeniar/phpunit-query-count-assertions/pull/14

**Full Changelog**: https://github.com/mattiasgeniar/phpunit-query-count-assertions/compare/1.1.6...1.1.7

## Query Performance Assertions: Index Usage, Duplicates & Timing - 2026-01-12

### Query Performance Assertions: Index Usage, Duplicates & Timing

This release adds powerful query performance assertions to catch inefficient queries in your tests.

#### New Assertions

##### Index Usage Detection

Detect full table scans and missing indexes by running EXPLAIN on your queries:

```php
$this->assertAllQueriesUseIndexes(function () {
    User::where('email', 'test@example.com')->first();
});



```
Supports MySQL, MariaDB, and SQLite. Detects full table scans, unused indexes, filesort, temporary tables, and more.

##### Duplicate Query Detection

Catch repeated identical queries:

```php
$this->assertNoDuplicateQueries(function () {
    User::find(1);
    User::find(1); // Fails - duplicate
});



```
##### Query Timing Assertions

Set performance budgets for your queries:

```php
$this->assertMaxQueryTime(100, fn() => ...);    // No single query over 100ms
$this->assertTotalQueryTime(500, fn() => ...);  // Total time under 500ms



```
##### Row Count Threshold (MySQL/MariaDB)

Fail when queries examine too many rows:

```php
$this->assertMaxRowsExamined(1000, fn() => User::where('status', 'active')->get());



```
##### Combined Efficiency Assertion

Check everything at once—N+1, duplicates, and index usage:

```php
$this->assertQueriesAreEfficient(function () {
    $users = User::with('posts')->get();
});



```
Or use `trackQueries()` in setUp/beforeEach for test-wide tracking.

##### Custom Query Analysers

Add support for additional databases:

```php
AssertsQueryCounts::registerQueryAnalyser(new PostgresAnalyser());



```
#### Other Improvements

- Issue severity levels (ERROR, WARNING, INFO) with configurable failure thresholds
- Small table optimization: ignores full scans on tables < 100 rows
- FK constraint context in SQLite scan warnings
- PHPStan analysis added to CI

#### Backwards Compatibility

Fully backwards compatible. All existing methods unchanged.

## 1.1.5 - 2025-02-24

PHP 8.4 support

## 1.1.4 - 2025-02-17

Support for Laravel 12

## 1.1.3 - 2024-03-04

Support for Laravel 11.x added

## 1.1.2 - 2023-01-24

- support Laravel 10

## 1.1.1 - 2022-01-24

- support Laravel 9

## 1.0.1 - 2022-01-21

- support Laravel 9

## 1.0.0 - 202X-XX-XX

- initial release
