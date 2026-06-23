<?php

namespace SurrealDB\SDK\Middleware;

use SurrealDB\SDK\Contracts\MiddlewareInterface;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;

/**
 * Composes a stack of {@see MiddlewareInterface} around the engine's `send()`
 * core. The first middleware added runs outermost.
 */
final class MiddlewarePipeline
{
    /** @var list<MiddlewareInterface> */
    private array $middleware;

    public function __construct(MiddlewareInterface ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }

    public function push(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    public function prepend(MiddlewareInterface $middleware): self
    {
        array_unshift($this->middleware, $middleware);

        return $this;
    }

    /**
     * @param callable(RpcRequest): RpcResponse<mixed> $core
     *
     * @return RpcResponse<mixed>
     */
    public function process(RpcRequest $request, callable $core): RpcResponse
    {
        $next = $core;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn (RpcRequest $req): RpcResponse => $middleware->process($req, $next);
        }

        return $next($request);
    }
}
