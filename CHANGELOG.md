# Changelog

All notable changes to `phpunit-db-querycounter` will be documented in this file

## 1.2.5 - 2026-02-06

**Full Changelog**: https://github.com/mattiasgeniar/phpunit-query-count-assertions/compare/1.2.4...1.2.5

## Support multiple DB connections - 2026-01-25

### What's Changed

* Support multiple connections by @mattiasgeniar in https://github.com/mattiasgeniar/phpunit-query-count-assertions/pull/17

**Full Changelog**: https://github.com/mattiasgeniar/phpunit-query-count-assertions/compare/1.2.2...1.2.4

## Track queries across all connections by default - 2026-01-25

**Full Changelog**: https://github.com/mattiasgeniar/phpunit-query-count-assertions/compare/1.2.1...1.2.3

## Cleanup version - 2026-01-18

### Simplify API by consolidating tracking methods

#### Summary

- Consolidated `trackQueriesForEfficiency()` into `trackQueries()` — one method now does everything
- Changed `trackQueries()` from static to instance method for consistent `$this->` API

#### Breaking Changes

**`self::trackQueries()` → `$this->trackQueries()`**

The method is no longer static. Update your setUp/beforeEach:

```php
// Before
protected function setUp(): void
{
    parent::setUp();
    self::trackQueries();
}

// After
protected function setUp(): void
{
    parent::setUp();
    $this->trackQueries();
}




```
**`trackQueriesForEfficiency()` is deprecated**

Replace with `trackQueries()`:

```php
// Before
$this->trackQueriesForEfficiency();

// After
$this->trackQueries();




```
#### Migration

Find and replace in your test files:

| Find | Replace |
|------|---------|
| `self::trackQueries()` | `$this->trackQueries()` |
| `$this->trackQueriesForEfficiency()` | `$this->trackQueries()` |

#### Why

The previous API had two methods that were confusingly similar:

- `trackQueries()` — basic query logging
- `trackQueriesForEfficiency()` — query logging + N+1 detection

Now there's just one method that does everything. If you only need query counts, the efficiency tracking has zero overhead — just don't call `assertQueriesAreEfficient()`.

## Unreleased

### Added

- **Multi-connection support:** `trackQueries()` now tracks queries across ALL database connections by default using `DB::listen()`. Previously only the default connection was tracked. This fixes the issue where queries on named connections (like 'replica', 'read', 'write') were not captured.
- New optional parameter to filter tracking to specific connections:
  ```php
  $this->trackQueries();                          // Track all connections (new default)
  $this->trackQueries('replica');                 // Track only 'replica' connection
  $this->trackQueries(['mysql', 'replica']);      // Track multiple specific connections
  
  
  ```
- Tracked queries now include a `connection` key indicating which connection executed each query.

### Changed

- **Breaking:** `trackQueries()` is now an instance method. Change `self::trackQueries()` to `$this->trackQueries()`.
- **Breaking:** `trackQueries()` now tracks all connections by default instead of just the default connection. If you relied on the previous behavior of only tracking the default connection, pass the connection name explicitly: `$this->trackQueries('mysql')`.
- Consolidated `trackQueriesForEfficiency()` into `trackQueries()`. The single `trackQueries()` method now enables all tracking features including N+1/lazy loading detection. `trackQueriesForEfficiency()` is deprecated and will be removed in the next major version.
- Lowered the default MySQL `minRowsForScanWarning` threshold to 10 (aligns with MySQL docs on tiny tables).
- Emit INFO-level index analysis notices by default (non-failing) to surface suppressed index issues.
- Replaced per-connection query logging with a global `DB::listen()` callback for more reliable cross-connection tracking.
- Simplified internal implementation by removing redundant code and using modern PHP features (null coalescing assignment, typed properties).

### Fixed

- **Fixed:** Queries on non-default connections (e.g., 'replica') are now properly tracked ([#16](https://github.com/mattiasgeniar/phpunit-query-count-assertions/issues/16))
- Skip full index scan warnings for small tables (fewer than 10 rows) where MySQL optimizer prefers scans over seeks
- Skip unused index warnings for small tables where MySQL optimizer prefers full scans (fewer than 10 rows)

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
- Small table optimization: ignores full scans on tables < 10 rows
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
