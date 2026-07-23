<?php

namespace SurrealDB\Tests\Unit\Runtime;

use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Runtime\Runtime;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\Telemetry\OpenTelemetry\OtelMeter;
use SurrealDB\Telemetry\OpenTelemetry\OtelTracer;

/**
 * Verifies the synchronous (PHP-FPM / CLI) preset attaches an OpenTelemetry
 * tracer and meter when observability is requested, and leaves the no-op
 * defaults untouched otherwise.
 */
final class RuntimeObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TracerProvider::class) || !class_exists(SpanExporter::class)) {
            $this->markTestSkipped('The OpenTelemetry SDK and OTLP exporter are required.');
        }
    }

    public function testSyncPresetWiresOtelTracerAndMeter(): void
    {
        $options = Runtime::sync(observability: new ObservabilityOptions(
            endpoint: 'http://localhost:4318',
            finishRequestBeforeFlush: false,
        ));

        $this->assertInstanceOf(SyncScheduler::class, $options->scheduler);
        $this->assertInstanceOf(OtelTracer::class, $options->tracer);
        $this->assertInstanceOf(OtelMeter::class, $options->meter);
    }

    public function testSyncPresetLeavesTelemetryNullWithoutObservability(): void
    {
        $options = Runtime::sync();

        $this->assertInstanceOf(SyncScheduler::class, $options->scheduler);
        $this->assertNull($options->tracer);
        $this->assertNull($options->meter);
    }
}
