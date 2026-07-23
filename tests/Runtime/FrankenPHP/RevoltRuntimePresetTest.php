<?php

namespace SurrealDB\Tests\Runtime\FrankenPHP;

use PHPUnit\Framework\TestCase;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Runtime\Runtime;
use SurrealDB\Scheduler\Amp\RevoltScheduler;
use SurrealDB\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\Telemetry\OpenTelemetry\OtelMeter;
use SurrealDB\Telemetry\OpenTelemetry\OtelTracer;

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

    public function testPresetWiresNonBlockingObservabilityWhenConfigured(): void
    {
        if (!class_exists(\OpenTelemetry\Contrib\Otlp\SpanExporter::class)
            || !class_exists(\Amp\Http\Client\Psr7\PsrHttpClient::class)) {
            $this->markTestSkipped('The OTLP exporter and amphp/http-client-psr7 are required.');
        }

        $options = Runtime::amp(observability: new ObservabilityOptions());

        $this->assertInstanceOf(OtelTracer::class, $options->tracer);
        $this->assertInstanceOf(OtelMeter::class, $options->meter);
    }
}
