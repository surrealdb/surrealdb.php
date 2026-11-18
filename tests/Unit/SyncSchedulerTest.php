<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\CallTerminatedException;
use SurrealDB\Scheduler\SyncScheduler;

final class SyncSchedulerTest extends TestCase
{
    public function testDeferredResolvesAfterBeingDriven(): void
    {
        $scheduler = new SyncScheduler();
        $ticks = 0;
        $deferred = null;

        $deferred = $scheduler->defer(function () use (&$ticks, &$deferred): bool {
            $ticks++;

            if ($ticks >= 3) {
                $deferred?->resolve('done');
            }

            return true;
        });

        $this->assertSame('done', $deferred->await());
        $this->assertSame(3, $ticks);
    }

    public function testDeferredFailsWhenDriveReportsClosure(): void
    {
        $scheduler = new SyncScheduler();
        $deferred = $scheduler->defer(static fn (): bool => false);

        $this->expectException(CallTerminatedException::class);
        $deferred->await();
    }
}
