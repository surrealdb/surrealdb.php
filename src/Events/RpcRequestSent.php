<?php

namespace SurrealDB\Events;

use SurrealDB\Rpc\RpcRequest;

/** Dispatched immediately before an RPC request is handed to the transport. */
final readonly class RpcRequestSent
{
    public function __construct(public RpcRequest $request) {}
}
