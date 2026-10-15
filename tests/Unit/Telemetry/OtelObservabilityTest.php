<?php

namespace SurrealDB\Tests\Unit\Telemetry;

use ArrayObject;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Enum\SpanKind;
use SurrealDB\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\Telemetry\OpenTelemetry\OtelObservability;

/**
 * The behavioural contract of the two export strategies: the batched (FPM/CLI)
 * strategy buffers spans until an explicit flush, while the direct (async)
 * strategy exports each span as it ends. Both use an in-memory exporter so no
 * OTLP/contrib package or network is required.
 */
final class OtelObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TracerProvider::class)) {
            $this->markTestSkipped('The open-telemetry/sdk package is not installed.');
        }
    }

    public function testBatchedBuffersSpansUntilFlush(): void
    {
        $exporter = new InMemorySpanExporter(new ArrayObject());
        $observability = OtelObservability::batched(
            new ObservabilityOptions(metrics: false),
            $exporter,
        );

        $observability->tracer()
            ->startSpan('surrealdb.query', SpanKind::Client)
            ->end();

        $this->assertCount(0, $exporter->getSpans(), 'spans should be buffered, not exported on end');

        $observability->forceFlush();

        $this->assertCount(1, $exporter->getSpans(), 'forceFlush should drain the buffer');
    }

    public function testDirectExportsSpansImmediately(): void
    {
        $exporter = new InMemorySpanExporter(new ArrayObject());
        $observability = OtelObservability::direct(
            new ObservabilityOptions(metrics: false),
            null,
            $exporter,
        );

        $observability->tracer()
            ->startSpan('surrealdb.query', SpanKind::Client)
            ->end();

        $this->assertCount(1, $exporter->getSpans(), 'direct strategy should export on span end');
    }

    public function testShutdownDrainsBatchedSpans(): void
    {
        $exporter = new InMemorySpanExporter(new ArrayObject());
        $observability = OtelObservability::batched(
            new ObservabilityOptions(metrics: false),
            $exporter,
        );

        $observability->tracer()->startSpan('surrealdb.connect')->end();
        $observability->shutdown();

        $this->assertCount(1, $exporter->getSpans(), 'shutdown should flush buffered spans');
    }

    public function testMeterExportsThroughConfiguredReader(): void
    {
        $spanExporter = new InMemorySpanExporter(new ArrayObject());
        $metricExporter = new InMemoryMetricExporter(new ArrayObject());

        $observability = OtelObservability::batched(
            new ObservabilityOptions(),
            $spanExporter,
            $metricExporter,
        );

        $observability->meter()
            ->counter('db.client.operation.count', '{operation}')
            ->add(1, ['outcome' => 'ok']);

        $observability->forceFlush();

        $names = array_map(static fn (Metric $metric): string => $metric->name, $metricExporter->collect());
        $this->assertContains('db.client.operation.count', $names);
    }

    public function testTracesCanBeDisabled(): void
    {
        $exporter = new InMemorySpanExporter(new ArrayObject());
        $observability = OtelObservability::batched(
            new ObservabilityOptions(traces: false, metrics: false),
            $exporter,
        );

        $observability->tracer()->startSpan('surrealdb.query')->end();
        $observability->forceFlush();

        $this->assertCount(0, $exporter->getSpans(), 'no processor is attached when traces are disabled');
    }
}
