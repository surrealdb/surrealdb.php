<?php

namespace SurrealDB\SDK\Events;

use SurrealDB\SDK\Rpc\RpcRequest;

/** Dispatched immediately before an RPC request is handed to the transport. */
final readonly class RpcRequestSent
{
    public function __construct(public RpcRequest $request) {}
}
