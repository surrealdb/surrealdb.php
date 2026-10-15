<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use SurrealDB\Contracts\Deferred;
use SurrealDB\Scheduler\Amp\RevoltScheduler;

/**
 * Runtime tests for the Revolt fiber scheduler that powers the Amp / FrankenPHP
 * worker runtime. They exercise the three cooperative primitives the engine is
 * built on — background tasks, cooperative delays, and cross-fiber awaitables —
 * directly on the Revolt event loop.
 *
 * Self-skips when revolt/event-loop is not installed.
 */
final class RevoltSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EventLoop::class)) {
            $this->markTestSkipped('revolt/event-loop is not installed.');
        }
    }

    public function testSpawnRunsTaskOnTheEventLoop(): void
    {
        $scheduler = new RevoltScheduler();
        $deferred = $scheduler->defer();

        $scheduler->spawn(static function () use ($deferred): void {
            $deferred->resolve('spawned');
        });

        $this->assertSame('spawned', $deferred->await());
    }

    public function testDelaySuspendsAndResumesCooperatively(): void
    {
        $scheduler = new RevoltScheduler();
        $order = [];

        $scheduler->spawn(static function () use (&$order, $scheduler): void {
            $scheduler->delay(0.01);
            $order[] = 'background';
        });

        $order[] = 'before-delay';
        $scheduler->delay(0.05);
        $order[] = 'after-delay';

        $this->assertSame(['before-delay', 'background', 'after-delay'], $order);
    }

    public function testDeferredResolvesAcrossFibers(): void
    {
        $scheduler = new RevoltScheduler();
        $deferred = $scheduler->defer();

        $scheduler->spawn(static function () use ($scheduler, $deferred): void {
            $scheduler->delay(0.01);
            $deferred->resolve(['ok' => true]);
        });

        $this->assertSame(['ok' => true], $deferred->await());
    }

    public function testDeferredPropagatesFailure(): void
    {
        $scheduler = new RevoltScheduler();
        $deferred = $scheduler->defer();

        $scheduler->spawn(static function () use ($deferred): void {
            $deferred->fail(new \RuntimeException('boom'));
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $deferred->await();
    }

    public function testManyConcurrentCallsMultiplexOverOneReader(): void
    {
        $scheduler = new RevoltScheduler();
        $count = 8;

        /** @var array<string, Deferred> $pending */
        $pending = [];
        $results = [];
        $remaining = $count;
        $done = $scheduler->defer();

        // Spawn N concurrent callers, each parked on its own correlated slot.
        for ($i = 0; $i < $count; $i++) {
            $id = (string) $i;
            $deferred = $scheduler->defer();
            $pending[$id] = $deferred;

            $scheduler->spawn(static function () use ($id, $deferred, &$results, &$remaining, $done): void {
                $results[$id] = $deferred->await();

                if (--$remaining === 0) {
                    $done->resolve(true);
                }
            });
        }

        // A single background "read loop" resolves every call by id, out of order.
        $scheduler->spawn(static function () use ($scheduler, &$pending, $count): void {
            $ids = array_map('strval', range(0, $count - 1));
            shuffle($ids);

            foreach ($ids as $id) {
                $scheduler->delay(0.001);
                $pending[$id]->resolve("result-{$id}");
            }
        });

        $done->await();

        ksort($results);

        $expected = [];
        for ($i = 0; $i < $count; $i++) {
            $expected[(string) $i] = "result-{$i}";
        }

        $this->assertSame($expected, $results);
    }
}
