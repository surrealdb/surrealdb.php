<?php

namespace SurrealDB\Tests\Runtime\OpenSwoole;

use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Scheduler\Swoole\SwooleScheduler;

/**
 * Runtime tests for the OpenSwoole coroutine scheduler and deferred. They
 * exercise the three cooperative primitives the engine is built on — background
 * tasks, cooperative delays, and cross-coroutine awaitables — and therefore run
 * inside an OpenSwoole coroutine context (`OpenSwoole\Coroutine\run`).
 *
 * Self-skips when the openswoole extension is not installed.
 */
final class SwooleSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('The openswoole extension is not installed.');
        }
    }

    public function testSpawnRunsBackgroundCoroutine(): void
    {
        $value = null;

        \OpenSwoole\Coroutine::run(function () use (&$value): void {
            $scheduler = new SwooleScheduler();
            $deferred = $scheduler->defer();

            $scheduler->spawn(static function () use ($deferred): void {
                $deferred->resolve('spawned');
            });

            $value = $deferred->await();
        });

        $this->assertSame('spawned', $value);
    }

    public function testDelayYieldsToOtherCoroutines(): void
    {
        $order = [];

        \OpenSwoole\Coroutine::run(function () use (&$order): void {
            $scheduler = new SwooleScheduler();

            $scheduler->spawn(static function () use (&$order, $scheduler): void {
                $scheduler->delay(0.01);
                $order[] = 'background';
            });

            $order[] = 'before-delay';
            $scheduler->delay(0.05);
            $order[] = 'after-delay';
        });

        $this->assertSame(['before-delay', 'background', 'after-delay'], $order);
    }

    public function testDeferredResolvesAcrossCoroutines(): void
    {
        $value = null;

        \OpenSwoole\Coroutine::run(function () use (&$value): void {
            $scheduler = new SwooleScheduler();
            $deferred = $scheduler->defer();

            $scheduler->spawn(static function () use ($scheduler, $deferred): void {
                $scheduler->delay(0.01);
                $deferred->resolve(['ok' => true]);
            });

            $value = $deferred->await();
        });

        $this->assertSame(['ok' => true], $value);
    }

    public function testDeferredPropagatesFailure(): void
    {
        $caught = null;

        \OpenSwoole\Coroutine::run(function () use (&$caught): void {
            $scheduler = new SwooleScheduler();
            $deferred = $scheduler->defer();

            $scheduler->spawn(static function () use ($deferred): void {
                $deferred->fail(new \RuntimeException('boom'));
            });

            try {
                $deferred->await();
            } catch (\Throwable $error) {
                $caught = $error;
            }
        });

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('boom', $caught->getMessage());
    }

    public function testManyConcurrentCallsMultiplexOverOneReader(): void
    {
        $results = [];

        \OpenSwoole\Coroutine::run(function () use (&$results): void {
            $scheduler = new SwooleScheduler();
            $count = 8;

            /** @var array<string, Deferred> $pending */
            $pending = [];
            $inbox = new Channel($count);
            $done = new Channel($count);

            // A single background "read loop" resolves each call by its id.
            $scheduler->spawn(static function () use ($inbox, &$pending): void {
                while (true) {
                    $frame = $inbox->pop();

                    if ($frame === false) {
                        break;
                    }

                    [$id, $value] = $frame;

                    if (isset($pending[$id])) {
                        $pending[$id]->resolve($value);
                        unset($pending[$id]);
                    }
                }
            });

            for ($i = 0; $i < $count; $i++) {
                $scheduler->spawn(static function () use ($i, $scheduler, $inbox, $done, &$pending, &$results): void {
                    $id = (string) $i;
                    $deferred = $scheduler->defer();
                    $pending[$id] = $deferred;

                    // Simulate the server echoing this request's response.
                    $inbox->push([$id, "result-{$i}"]);

                    $results[$id] = $deferred->await();
                    $done->push(true);
                });
            }

            for ($i = 0; $i < $count; $i++) {
                $done->pop();
            }

            $inbox->close();
        });

        ksort($results);

        $expected = [];
        for ($i = 0; $i < 8; $i++) {
            $expected[(string) $i] = "result-{$i}";
        }

        $this->assertSame($expected, $results);
    }
}
