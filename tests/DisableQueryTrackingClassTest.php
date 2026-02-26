<?php

declare(strict_types=1);

namespace Mattiasgeniar\PhpunitQueryCountAssertions\Tests;

use Mattiasgeniar\PhpunitQueryCountAssertions\AssertsQueryCounts;
use Mattiasgeniar\PhpunitQueryCountAssertions\Attributes\DisableQueryTracking;
use PHPUnit\Framework\Attributes\Test;

#[DisableQueryTracking]
class DisableQueryTrackingClassTest extends TestCase
{
    use AssertsQueryCounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trackQueries();
    }

    #[Test]
    public function it_silently_passes_when_class_has_disable_attribute(): void
    {
        $this->expectNotToPerformAssertions();

        $this->assertQueriesAreEfficient();
    }

    #[Test]
    public function it_silently_passes_count_assertions_when_class_has_disable_attribute(): void
    {
        $this->expectNotToPerformAssertions();

        $this->assertNoQueriesExecuted();
        $this->assertQueryCountMatches(0);
    }
}
