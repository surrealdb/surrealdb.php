<?php

namespace SurrealDB\Tests\Runtime\OpenSwoole;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Scheduler\Swoole\SwooleScheduler;
use SurrealDB\SDK\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\SDK\Telemetry\OpenTelemetry\OtelMeter;
use SurrealDB\SDK\Telemetry\OpenTelemetry\OtelTracer;

/**
 * Verifies the OpenSwoole runtime preset wires the coroutine scheduler and the
 * native coroutine WebSocket transport onto {@see DriverOptions}.
 *
 * These are pure configuration assertions (coroutine hooks disabled, no
 * coroutine is started), so they run even without the openswoole extension.
 */
final class SwooleRuntimePresetTest extends TestCase
{
    public function testPresetInstallsSwooleSchedulerAndWebSocketTransport(): void
    {
        $options = Runtime::swoole(enableHooks: false);

        $this->assertInstanceOf(SwooleScheduler::class, $options->scheduler);
        $this->assertNotNull($options->webSocketTransportFactory);
    }

    public function testPresetReusesProvidedOptions(): void
    {
        $base = new DriverOptions(pingInterval: 5);
        $options = Runtime::swoole($base, enableHooks: false);

        $this->assertSame($base, $options);
        $this->assertSame(5, $options->pingInterval);
        $this->assertInstanceOf(SwooleScheduler::class, $options->scheduler);
    }

    public function testPresetWiresDirectObservabilityWhenConfigured(): void
    {
        if (!class_exists(\OpenTelemetry\Contrib\Otlp\SpanExporter::class)) {
            $this->markTestSkipped('The OTLP exporter is required.');
        }

        $options = Runtime::swoole(enableHooks: false, observability: new ObservabilityOptions());

        $this->assertInstanceOf(OtelTracer::class, $options->tracer);
        $this->assertInstanceOf(OtelMeter::class, $options->meter);
    }
}
