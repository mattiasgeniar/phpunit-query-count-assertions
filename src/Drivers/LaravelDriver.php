<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Drivers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\ConnectionInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\QueryDriverInterface;
use Mattiasgeniar\PhpunitQueryCountAssertions\Contracts\SupportsQueryTimingInterface;
use ReflectionProperty;

/**
 * Laravel driver for query tracking.
 *
 * Implements query listening via DB::listen() and lazy loading
 * detection via Model::preventLazyLoading().
 */
class LaravelDriver implements QueryDriverInterface, SupportsQueryTimingInterface
{
    /**
     * Hash of the app instance where listener was registered, to detect app refreshes in tests.
     */
    private static ?string $listenerAppHash = null;

    /**
     * Current query callback (set during tracking).
     */
    private static ?Closure $queryCallback = null;

    /**
     * Connections to track (null = all connections).
     *
     * @var array<string>|null
     */
    private static ?array $connectionsToTrack = null;

    /**
     * Whether we're currently tracking.
     */
    private static bool $isTracking = false;

    /**
     * Cached connection wrappers.
     *
     * @var array<string, ConnectionInterface>
     */
    private array $connectionWrappers = [];

    /**
     * Snapshot of lazy loading state to restore after tracking.
     *
     * @var array{prevention: bool, callback: callable|null}|null
     */
    private static ?array $lazyLoadingState = null;

    /**
     * Current lazy loading violation callback.
     */
    private static ?Closure $lazyLoadingCallback = null;

    public function startListening(Closure $callback, ?array $connections = null): void
    {
        self::$queryCallback = $callback;
        self::$connectionsToTrack = $connections;
        self::$isTracking = true;

        $this->registerGlobalListener();
    }

    public function stopListening(): void
    {
        self::$isTracking = false;
        self::$queryCallback = null;
        self::$connectionsToTrack = null;
    }

    public function getConnection(?string $name = null): ConnectionInterface
    {
        $key = $name ?? DB::getDefaultConnection();

        return $this->connectionWrappers[$key] ??= new LaravelConnection(DB::connection($name));
    }

    public function enableLazyLoadingDetection(Closure $violationCallback): bool
    {
        $this->captureLazyLoadingState();

        self::$lazyLoadingCallback = $violationCallback;

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing($this->createViolationHandler());

        return true;
    }

    public function disableLazyLoadingDetection(): void
    {
        $this->restoreLazyLoadingState();

        self::$lazyLoadingCallback = null;
    }

    public function getBasePath(): string
    {
        return base_path();
    }

    public function getStackTraceSkipPatterns(): array
    {
        return [
            '/vendor\/laravel\/framework/',
            '/vendor\/illuminate/',
            '/AssertsQueryCounts\.php$/',
            '/Drivers\/LaravelDriver\.php$/',
            '/vendor\/phpunit/',
        ];
    }

    public function supportsQueryTiming(): bool
    {
        return true;
    }

    private function registerGlobalListener(): void
    {
        $currentAppHash = (string) spl_object_id(app());

        if (self::$listenerAppHash === $currentAppHash) {
            return;
        }

        self::$listenerAppHash = $currentAppHash;
        $this->connectionWrappers = [];

        DB::listen(function (QueryExecuted $query) {
            if (! self::$isTracking || self::$queryCallback === null) {
                return;
            }

            $connectionName = $query->connectionName;

            if (self::$connectionsToTrack !== null
                && ! in_array($connectionName, self::$connectionsToTrack, true)) {
                return;
            }

            (self::$queryCallback)([
                'query' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
                'connection' => $connectionName,
            ]);
        });
    }

    private function captureLazyLoadingState(): void
    {
        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        self::$lazyLoadingState = [
            'prevention' => $preventionProperty->getValue(null),
            'callback' => $callbackProperty->getValue(null),
        ];
    }

    private function restoreLazyLoadingState(): void
    {
        if (self::$lazyLoadingState === null) {
            return;
        }

        $preventionProperty = new ReflectionProperty(Model::class, 'modelsShouldPreventLazyLoading');
        $callbackProperty = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

        $preventionProperty->setValue(null, self::$lazyLoadingState['prevention']);
        $callbackProperty->setValue(null, self::$lazyLoadingState['callback']);
        self::$lazyLoadingState = null;
    }

    private function createViolationHandler(): Closure
    {
        return function (Model $model, string $relation): void {
            if (self::$lazyLoadingCallback !== null) {
                (self::$lazyLoadingCallback)([
                    'model' => $model::class,
                    'relation' => $relation,
                ]);
            }
        };
    }
}
