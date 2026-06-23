<?php

namespace SurrealDB\Tests\Feature;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use SurrealDB\SDK\Surreal;
use SurrealDB\Tests\Fakes\FakeTransport;

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
}
