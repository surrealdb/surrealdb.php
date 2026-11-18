<?php

namespace SurrealDB\Contracts;

use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/**
 * A request/response interceptor wrapping the engine `send()` primitive. This
 * is the primary injection seam: authentication, logging/debugging, retries,
 * and metrics all participate as middleware.
 *
 * PSR-15-inspired (PSR-15 itself targets server request handling), adapted to
 * the SurrealDB RPC request/response.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(RpcRequest): RpcResponse<mixed> $next
     *
     * @return RpcResponse<mixed>
     */
    public function process(RpcRequest $request, callable $next): RpcResponse;
}
