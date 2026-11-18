<?php

namespace SurrealDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SurrealDB\Middleware\MiddlewarePipeline;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use SurrealDB\Tests\Fakes\RecordingMiddleware;

final class MiddlewarePipelineTest extends TestCase
{
    public function testFirstMiddlewareIsOutermost(): void
    {
        $events = [];
        $record = function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $pipeline = new MiddlewarePipeline(
            new RecordingMiddleware('a', $record),
            new RecordingMiddleware('b', $record),
        );

        $response = $pipeline->process(
            new RpcRequest('ping'),
            function (RpcRequest $request) use ($record): RpcResponse {
                $record('core');

                return new RpcResponse($request->id, 'pong');
            },
        );

        $this->assertSame('pong', $response->result);
        $this->assertSame(['a:before', 'b:before', 'core', 'b:after', 'a:after'], $events);
    }
}
