<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Contracts;

/**
 * Optional capability interface for drivers that can report query execution time.
 */
interface SupportsQueryTimingInterface
{
    /**
     * Whether timing assertions are supported by this driver.
     */
    public function supportsQueryTiming(): bool;
}
