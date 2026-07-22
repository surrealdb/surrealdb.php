<?php

namespace SurrealDB\Tests\Unit\Telemetry;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Enum\SpanKind;
use SurrealDB\SDK\Enum\SpanStatus;
use SurrealDB\SDK\Telemetry\OpenTelemetry\OtelMeter;
use SurrealDB\SDK\Telemetry\OpenTelemetry\OtelTracer;

final class OtelAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TracerProvider::class)) {
            $this->markTestSkipped('The open-telemetry/sdk package is not installed.');
        }
    }

    public function testTracerExportsSpanWithAttributesAndStatus(): void
    {
        $exporter = new InMemorySpanExporter(new ArrayObject());
        $provider = new TracerProvider(new SimpleSpanProcessor($exporter));

        (new OtelTracer($provider->getTracer('test')))
            ->startSpan('surrealdb.query', SpanKind::Client, ['db.system.name' => 'surrealdb'])
            ->setAttribute('db.operation.name', 'query')
            ->setStatus(SpanStatus::Ok)
            ->end();

        $spans = $exporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('surrealdb.query', $spans[0]->getName());
        $this->assertSame(OtelSpanKind::KIND_CLIENT, $spans[0]->getKind());
        $this->assertSame('surrealdb', $spans[0]->getAttributes()->get('db.system.name'));
        $this->assertSame('query', $spans[0]->getAttributes()->get('db.operation.name'));
        $this->assertSame('Ok', $spans[0]->getStatus()->getCode());

        $provider->shutdown();
    }

    public function testMeterExportsCounterAndHistogram(): void
    {
        $exporter = new InMemoryMetricExporter(new ArrayObject());
        $reader = new ExportingReader($exporter);
        $provider = (new MeterProviderBuilder())->addReader($reader)->build();

        $meter = new OtelMeter($provider->getMeter('test'));
        $meter->counter('db.client.operation.count', '{operation}')->add(1, ['outcome' => 'ok']);
        $meter->histogram('db.client.operation.duration', 's')->record(0.25, ['outcome' => 'ok']);

        $reader->collect();

        $names = array_map(static fn (Metric $metric): string => $metric->name, $exporter->collect());
        $this->assertContains('db.client.operation.count', $names);
        $this->assertContains('db.client.operation.duration', $names);

        $provider->shutdown();
    }
}
