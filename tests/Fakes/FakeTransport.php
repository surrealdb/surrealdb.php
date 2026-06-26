<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\SDK\Contracts\TransportInterface;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;

/** An in-memory request/response transport for testing the HTTP engine path. */
final class FakeTransport implements TransportInterface
{
    /** @var list<RpcRequest> */
    public array $requests = [];

    private bool $open = false;

    /** @var \Closure(RpcRequest): RpcResponse */
    private \Closure $responder;

    public function __construct(\Closure $responder)
    {
        $this->responder = $responder;
    }

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }

    public function send(RpcRequest $request): RpcResponse
    {
        $this->requests[] = $request;

        return ($this->responder)($request);
    }
}
