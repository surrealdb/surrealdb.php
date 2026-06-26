<?php

namespace SurrealDB\SDK\Events;

use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;

/** Dispatched after an RPC response is received (success or error). */
final readonly class RpcResponseReceived
{
    /**
     * @param RpcResponse<mixed> $response
     */
    public function __construct(
        public RpcRequest $request,
        public RpcResponse $response,
    ) {}
}
