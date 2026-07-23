<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/** Records before/after markers so middleware ordering can be asserted. */
final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $tag,
        private readonly \Closure $record,
    ) {}

    public function process(RpcRequest $request, callable $next): RpcResponse
    {
        ($this->record)("{$this->tag}:before");
        $response = $next($request);
        ($this->record)("{$this->tag}:after");

        return $response;
    }
}
