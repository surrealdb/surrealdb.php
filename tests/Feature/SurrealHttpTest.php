<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use SurrealDB\Surreal;
use SurrealDB\Tests\Fakes\FakeTransport;
use SurrealDB\Tests\Fakes\RecordingMeter;
use SurrealDB\Tests\Fakes\RecordingSpan;
use SurrealDB\Tests\Fakes\RecordingTracer;

final class SurrealHttpTest extends TestCase
{
    private function makeTransport(): FakeTransport
    {
        return new FakeTransport(static fn (RpcRequest $request): RpcResponse => match ($request->method) {
            'version' => new RpcResponse($request->id, 'surrealdb-2.1.0'),
            'query' => new RpcResponse($request->id, [
                ['status' => 'OK', 'time' => '1ms', 'result' => [42]],
            ]),
            default => new RpcResponse($request->id),
        });
    }

    public function testConnectAndQueryOverHttp(): void
    {
        $transport = $this->makeTransport();
        $db = new Surreal(new DriverOptions(httpTransportFactory: fn (): FakeTransport => $transport));

        $db->connect('http://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));

        $this->assertTrue($db->isConnected());
        $this->assertSame([[42]], $db->run('SELECT 42'));
    }

    public function testLetIsNoOpOverHttpButMergedIntoQueryParams(): void
    {
        $transport = $this->makeTransport();
        $db = new Surreal(new DriverOptions(httpTransportFactory: fn (): FakeTransport => $transport));

        $db->connect('http://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));
        $db->let('name', 'Tobie');
        $db->run('SELECT * FROM person WHERE name = $name');

        $methods = array_map(static fn (RpcRequest $request): string => $request->method, $transport->requests);
        $this->assertNotContains('let', $methods, 'let must be a no-op over HTTP');

        $queryRequest = null;
        foreach ($transport->requests as $request) {
            if ($request->method === 'query') {
                $queryRequest = $request;
            }
        }

        $this->assertNotNull($queryRequest);
        $this->assertSame(['name' => 'Tobie'], $queryRequest->params[1]);
    }

    public function testTelemetryIsEmittedForConnectionAndQueries(): void
    {
        $transport = $this->makeTransport();
        $tracer = new RecordingTracer();
        $meter = new RecordingMeter();
        $db = new Surreal(new DriverOptions(
            tracer: $tracer,
            meter: $meter,
            httpTransportFactory: fn (): FakeTransport => $transport,
        ));

        $db->connect('http://localhost:8000/rpc', new ConnectOptions(namespace: 'test', database: 'test'));
        $db->run('SELECT 42');

        $spanNames = array_map(static fn (RecordingSpan $span): string => $span->name, $tracer->spans);
        $this->assertContains('surrealdb.connect', $spanNames, 'the connection should be traced');
        $this->assertContains('surrealdb.version', $spanNames, 'restore-time RPCs should be traced');
        $this->assertContains('surrealdb.query', $spanNames, 'queries should be traced');

        $connections = $meter->counters['db.client.connection.count']->measurements;
        $this->assertNotSame([], $connections);
        $this->assertSame('ok', $connections[0]['attributes']['outcome']);

        $this->assertArrayHasKey('db.client.operation.duration', $meter->histograms);
        $this->assertArrayHasKey('db.client.operation.count', $meter->counters);
    }
}
