<?php

namespace SurrealDB\Rpc;

/**
 * An immutable RPC request. Mirrors the JS `RpcRequest` type: a method plus
 * optional params, scoped to an optional session and transaction.
 *
 * The `id` is assigned by the engine right before the request hits the wire,
 * so request construction stays free of transport concerns.
 */
final readonly class RpcRequest
{
    /**
     * @param list<mixed> $params
     */
    public function __construct(
        public string $method,
        public array $params = [],
        public ?string $session = null,
        public ?string $txn = null,
        public ?string $id = null,
    ) {}

    public function withId(string $id): self
    {
        return new self($this->method, $this->params, $this->session, $this->txn, $id);
    }

    /**
     * @param list<mixed> $params
     */
    public function withParams(array $params): self
    {
        return new self($this->method, $params, $this->session, $this->txn, $this->id);
    }

    /**
     * Build the wire payload sent to the server.
     *
     * @return array<string,mixed>
     */
    public function toWire(): array
    {
        $wire = ['method' => $this->method];

        if ($this->id !== null) {
            $wire['id'] = $this->id;
        }

        if ($this->params !== []) {
            $wire['params'] = $this->params;
        }

        if ($this->session !== null) {
            $wire['session'] = $this->session;
        }

        if ($this->txn !== null) {
            $wire['txn'] = $this->txn;
        }

        return $wire;
    }
}
