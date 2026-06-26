<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\SDK\Exceptions\NotFoundException;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Rpc\RpcResponse;

final class ServerExceptionTest extends TestCase
{
    public function testErrorResponseMapsToTypedServerException(): void
    {
        $response = RpcResponse::fromWire([
            'id' => '1',
            'error' => ['code' => 0, 'message' => 'not here', 'kind' => 'NotFound'],
        ]);

        $this->assertTrue($response->isError());

        try {
            $response->resultOrThrow();
            $this->fail('Expected a NotFoundException to be thrown.');
        } catch (NotFoundException $exception) {
            $this->assertSame('NotFound', $exception->kind);
            $this->assertSame('not here', $exception->getMessage());
        }
    }

    public function testUnknownKindFallsBackToBaseServerException(): void
    {
        $response = RpcResponse::fromWire([
            'error' => ['code' => 500, 'message' => 'boom', 'kind' => 'Something'],
        ]);

        $this->expectException(ServerException::class);
        $response->resultOrThrow();
    }

    public function testSuccessResponseReturnsResult(): void
    {
        $response = RpcResponse::fromWire(['id' => '1', 'result' => ['ok' => true]]);

        $this->assertFalse($response->isError());
        $this->assertSame(['ok' => true], $response->resultOrThrow());
    }
}
