<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Scheduler\Amp\RevoltScheduler;

/**
 * Verifies the Amp / FrankenPHP runtime preset wires the Revolt scheduler and
 * the non-blocking Amp WebSocket + HTTP transports onto {@see DriverOptions}.
 *
 * These are pure configuration assertions (no event loop is started), so they
 * run regardless of which async runtime is installed.
 */
final class RevoltRuntimePresetTest extends TestCase
{
    public function testPresetInstallsRevoltSchedulerAndTransports(): void
    {
        $options = Runtime::amp();

        $this->assertInstanceOf(RevoltScheduler::class, $options->scheduler);
        $this->assertNotNull($options->webSocketTransportFactory);
        $this->assertNotNull($options->httpTransportFactory);
    }

    public function testPresetReusesProvidedOptions(): void
    {
        $base = new DriverOptions(pingInterval: 5);
        $options = Runtime::amp($base);

        $this->assertSame($base, $options);
        $this->assertSame(5, $options->pingInterval);
        $this->assertInstanceOf(RevoltScheduler::class, $options->scheduler);
    }
}
