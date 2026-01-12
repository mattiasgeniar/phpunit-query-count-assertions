# Changelog

All notable changes to `phpunit-db-querycounter` will be documented in this file

## 1.1.6 - 2026-01-12

Query Performance Assertions: Index Usage, Duplicates & Timing

### Added

- `assertAllQueriesUseIndexes()` - detect full table scans and missing indexes via EXPLAIN
- `assertNoDuplicateQueries()` - catch repeated identical queries
- `assertMaxQueryTime()` - fail when any single query exceeds a threshold
- `assertTotalQueryTime()` - fail when total query time exceeds a threshold
- `assertMaxRowsExamined()` - fail when queries examine too many rows (MySQL/MariaDB)
- `assertQueriesAreEfficient()` - combined check for N+1, duplicates, and index usage
- `trackQueriesForEfficiency()` - enable test-wide efficiency tracking in setUp/beforeEach
- `registerQueryAnalyser()` - add custom analysers for additional databases
- MySQL, MariaDB, and SQLite support for query analysis
- Issue severity levels (ERROR, WARNING, INFO)
- Small table optimization: ignores full scans on tables with < 100 rows
- PHPStan analysis in CI

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
