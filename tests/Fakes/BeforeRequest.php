<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/**
 * Middleware that calls a closure with each request before it is sent, so a
 * test can hold a request back, or act between two of them.
 */
final class BeforeRequest implements MiddlewareInterface
{
    /**
     * @param \Closure(RpcRequest): void $before
     */
    public function __construct(private readonly \Closure $before) {}

    public function process(RpcRequest $request, callable $next): RpcResponse
    {
        ($this->before)($request);

        return $next($request);
    }
}
