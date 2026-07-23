<?php

namespace SurrealDB\Contracts;

use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/**
 * The raw wire abstraction for request/response transports (e.g. HTTP). This is
 * the runtime-swappable seam: a synchronous PSR-18 implementation ships today,
 * while async implementations (Swoole/Amp) can drop in without touching the
 * engine layer.
 */
interface TransportInterface
{
    public function open(): void;

    public function close(): void;

    public function isConnected(): bool;

    /**
     * Encode, send, and decode a single request/response round-trip.
     *
     * @return RpcResponse<mixed>
     */
    public function send(RpcRequest $request): RpcResponse;
}
