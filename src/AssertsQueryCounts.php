<?php

namespace Mattiasgeniar\PhpunitQueryCountAssertions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;

trait AssertsQueryCounts
{
    private static array $lazyLoadingViolations = [];

    public function assertNoQueriesExecuted(?Closure $closure = null): void
    {
        $this->assertQueryCountMatches(0, $closure);
    }

    public function assertQueryCountMatches(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertEquals($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountLessThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThan($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected fewer than {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountLessThanOrEqual(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertLessThanOrEqual($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected at most {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountGreaterThan(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThan($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected more than {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountGreaterThanOrEqual(int $count, ?Closure $closure = null): void
    {
        $this->withQueryTracking(
            $closure,
            fn () => $this->assertGreaterThanOrEqual($count, self::getQueryCount(), $this->formatFailureMessage(
                "Expected at least {$count} queries, got " . self::getQueryCount() . '.'
            ))
        );
    }

    public function assertQueryCountBetween(int $min, int $max, ?Closure $closure = null): void
    {
        $this->withQueryTracking($closure, function () use ($min, $max) {
            $count = self::getQueryCount();
            $message = $this->formatFailureMessage(
                "Expected between {$min} and {$max} queries, got {$count}."
            );

            $this->assertGreaterThanOrEqual($min, $count, $message);
            $this->assertLessThanOrEqual($max, $count, $message);
        });
    }

    /**
     * Assert that no lazy loading occurs within the closure.
     *
     * This leverages Laravel's built-in lazy loading prevention to detect N+1 queries.
     *
     * @see https://laravel.com/docs/eloquent-relationships#preventing-lazy-loading
     */
    public function assertNoLazyLoading(Closure $closure): void
    {
        $violations = $this->collectLazyLoadingViolations($closure);

        $this->assertEmpty(
            $violations,
            $this->formatLazyLoadingFailureMessage($violations)
        );
    }

    /**
     * Assert that lazy loading occurs a specific number of times within the closure.
     */
    public function assertLazyLoadingCount(int $expectedCount, Closure $closure): void
    {
        $violations = $this->collectLazyLoadingViolations($closure);

        $this->assertCount(
            $expectedCount,
            $violations,
            $this->formatLazyLoadingFailureMessage(
                $violations,
                "Expected {$expectedCount} lazy loading violations, got " . count($violations) . '.'
            )
        );
    }

    /**
     * Get the lazy loading violations that were collected.
     *
     * @return array<int, array{model: string, relation: string}>
     */
    public static function getLazyLoadingViolations(): array
    {
        return self::$lazyLoadingViolations;
    }

    private function collectLazyLoadingViolations(Closure $closure): array
    {
        self::$lazyLoadingViolations = [];

        // Store original state using reflection
        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        $originalPrevention = $preventionProperty->getValue(null);
        $originalCallback = $callbackProperty->getValue(null);

        // Enable lazy loading prevention with our collector
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            self::$lazyLoadingViolations[] = [
                'model' => $model::class,
                'relation' => $relation,
            ];
        });

        try {
            $closure();
        } finally {
            // Restore original state
            $preventionProperty->setValue(null, $originalPrevention);
            $callbackProperty->setValue(null, $originalCallback);
        }

        return self::$lazyLoadingViolations;
    }

    private function formatLazyLoadingFailureMessage(array $violations, ?string $prefix = null): string
    {
        if (empty($violations)) {
            return $prefix ?? 'No lazy loading violations detected.';
        }

        $message = $prefix ?? 'Lazy loading violations detected:';
        $message .= "\nViolations:";

        foreach ($violations as $index => $violation) {
            $number = $index + 1;
            $message .= "\n  {$number}. {$violation['model']}::\${$violation['relation']}";
        }

        return $message;
    }

    private function withQueryTracking(?Closure $closure, callable $assertion): void
    {
        if ($closure === null) {
            $assertion();

            return;
        }

        self::trackQueries();
        $closure();
        $assertion();
        DB::flushQueryLog();
    }

    private function formatFailureMessage(string $message): string
    {
        $queries = self::getQueriesExecuted();

        if (empty($queries)) {
            return $message . "\nNo queries were executed.";
        }

        $formatted = $message . "\nQueries executed:";

        foreach ($queries as $index => $query) {
            $number = $index + 1;
            $sql = $query['query'];
            $time = round($query['time'], 2);

            $formatted .= "\n  {$number}. [{$time}ms] {$sql}";

            if (! empty($query['bindings'])) {
                $bindings = json_encode($query['bindings']);
                $formatted .= "\n      Bindings: {$bindings}";
            }
        }

        return $formatted;
    }

    public static function trackQueries(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    public static function getQueriesExecuted(): array
    {
        return DB::getQueryLog();
    }

    public static function getQueryCount(): int
    {
        return count(self::getQueriesExecuted());
    }
}
