<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/** Middleware that records the method of every request sent. */
final class MethodLog implements MiddlewareInterface
{
    /** @var list<string> */
    public array $methods = [];

    public function process(RpcRequest $request, callable $next): RpcResponse
    {
        $this->methods[] = $request->method;

        return $next($request);
    }

    public function count(string $method): int
    {
        return count(array_keys($this->methods, $method, true));
    }
}
