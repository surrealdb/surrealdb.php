<?php

namespace SurrealDB\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Enum\SpanKind;
use SurrealDB\SDK\Enum\SpanStatus;
use SurrealDB\SDK\Middleware\TelemetryMiddleware;
use SurrealDB\SDK\Rpc\RpcError;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use SurrealDB\Tests\Fakes\RecordingMeter;
use SurrealDB\Tests\Fakes\RecordingTracer;

final class TelemetryMiddlewareTest extends TestCase
{
    public function testSuccessfulCallStartsClientSpanAndRecordsMetrics(): void
    {
        $tracer = new RecordingTracer();
        $meter = new RecordingMeter();
        $middleware = new TelemetryMiddleware($tracer, $meter);

        $response = $middleware->process(
            new RpcRequest('query', ['SELECT 1'], session: 'sess', txn: 'txn'),
            static fn (RpcRequest $request): RpcResponse => new RpcResponse($request->id, 'pong'),
        );

        $this->assertSame('pong', $response->result);

        $span = $tracer->lastSpan();
        $this->assertSame('surrealdb.query', $span->name);
        $this->assertSame(SpanKind::Client, $span->kind);
        $this->assertSame('surrealdb', $span->attributes['db.system.name']);
        $this->assertSame('query', $span->attributes['db.operation.name']);
        $this->assertSame('sess', $span->attributes['db.surrealdb.session']);
        $this->assertSame('txn', $span->attributes['db.surrealdb.transaction']);
        $this->assertSame(SpanStatus::Ok, $span->status);
        $this->assertTrue($span->ended);

        $duration = $meter->histograms['db.client.operation.duration'];
        $this->assertSame('s', $duration->unit);
        $this->assertCount(1, $duration->measurements);
        $this->assertSame('ok', $duration->measurements[0]['attributes']['outcome']);
        $this->assertSame('query', $duration->measurements[0]['attributes']['db.operation.name']);

        $operations = $meter->counters['db.client.operation.count'];
        $this->assertCount(1, $operations->measurements);
        $this->assertSame(1, $operations->measurements[0]['value']);
    }

    public function testQueryTextOmittedByDefaultButIncludedWhenEnabled(): void
    {
        $core = static fn (RpcRequest $request): RpcResponse => new RpcResponse($request->id, null);

        $default = new RecordingTracer();
        (new TelemetryMiddleware($default, new RecordingMeter()))
            ->process(new RpcRequest('query', ['SELECT 1']), $core);
        $this->assertArrayNotHasKey('db.query.text', $default->lastSpan()->attributes);

        $withText = new RecordingTracer();
        (new TelemetryMiddleware($withText, new RecordingMeter(), recordQueryText: true))
            ->process(new RpcRequest('query', ['SELECT 1']), $core);
        $this->assertSame('SELECT 1', $withText->lastSpan()->attributes['db.query.text']);
    }

    public function testErrorResponseMarksSpanAsError(): void
    {
        $tracer = new RecordingTracer();
        $meter = new RecordingMeter();

        (new TelemetryMiddleware($tracer, $meter))->process(
            new RpcRequest('query'),
            static fn (RpcRequest $request): RpcResponse => new RpcResponse(
                $request->id,
                null,
                new RpcError(code: 0, message: 'boom', kind: 'Query'),
            ),
        );

        $span = $tracer->lastSpan();
        $this->assertSame(SpanStatus::Error, $span->status);
        $this->assertSame('boom', $span->statusDescription);
        $this->assertSame('Query', $span->attributes['error.type']);
        $this->assertTrue($span->ended);

        $duration = $meter->histograms['db.client.operation.duration'];
        $this->assertSame('error', $duration->measurements[0]['attributes']['outcome']);
    }

    public function testThrownExceptionIsRecordedAndRethrown(): void
    {
        $tracer = new RecordingTracer();
        $meter = new RecordingMeter();
        $boom = new \RuntimeException('kaboom');

        try {
            (new TelemetryMiddleware($tracer, $meter))->process(
                new RpcRequest('query'),
                static function () use ($boom): RpcResponse {
                    throw $boom;
                },
            );
            $this->fail('Expected the exception to propagate.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($boom, $caught);
        }

        $span = $tracer->lastSpan();
        $this->assertSame(SpanStatus::Error, $span->status);
        $this->assertSame([$boom], $span->exceptions);
        $this->assertSame(\RuntimeException::class, $span->attributes['error.type']);
        $this->assertTrue($span->ended);

        $duration = $meter->histograms['db.client.operation.duration'];
        $this->assertSame('exception', $duration->measurements[0]['attributes']['outcome']);
    }
}
