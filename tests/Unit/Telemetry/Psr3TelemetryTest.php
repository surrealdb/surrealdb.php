<?php

namespace SurrealDB\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use SurrealDB\Enum\SpanKind;
use SurrealDB\Enum\SpanStatus;
use SurrealDB\Telemetry\Psr\Psr3Meter;
use SurrealDB\Telemetry\Psr\Psr3Tracer;
use SurrealDB\Tests\Fakes\RecordingLogger;

final class Psr3TelemetryTest extends TestCase
{
    public function testTracerLogsSpanOnEnd(): void
    {
        $logger = new RecordingLogger();
        $tracer = new Psr3Tracer($logger, LogLevel::INFO);

        $tracer
            ->startSpan('surrealdb.query', SpanKind::Client, ['db.system.name' => 'surrealdb'])
            ->setAttribute('db.operation.name', 'query')
            ->setStatus(SpanStatus::Ok)
            ->end();

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame(LogLevel::INFO, $record['level']);
        $this->assertSame('surrealdb.query', $record['context']['span']);
        $this->assertSame('Ok', $record['context']['status']);
        $this->assertSame('surrealdb', $record['context']['attributes']['db.system.name']);
        $this->assertSame('query', $record['context']['attributes']['db.operation.name']);
        $this->assertArrayHasKey('duration_ms', $record['context']);
    }

    public function testMeterLogsCounterAndHistogram(): void
    {
        $logger = new RecordingLogger();
        $meter = new Psr3Meter($logger, LogLevel::INFO);

        $meter->counter('db.client.operation.count', '{operation}')->add(1, ['outcome' => 'ok']);
        $meter->histogram('db.client.operation.duration', 's')->record(0.25, ['outcome' => 'ok']);

        $this->assertCount(2, $logger->records);
        $this->assertSame('db.client.operation.count', $logger->records[0]['context']['metric']);
        $this->assertSame(1, $logger->records[0]['context']['value']);
        $this->assertSame('db.client.operation.duration', $logger->records[1]['context']['metric']);
        $this->assertSame(0.25, $logger->records[1]['context']['value']);
        $this->assertSame('ok', $logger->records[1]['context']['attributes']['outcome']);
    }
}
