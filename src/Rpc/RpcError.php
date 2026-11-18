<?php

namespace SurrealDB\Rpc;

/**
 * The structured error payload returned inside an RPC response.
 */
final readonly class RpcError
{
    /**
     * @param array<string,mixed>|null $details
     */
    public function __construct(
        public int $code,
        public string $message,
        public ?string $kind = null,
        public ?array $details = null,
    ) {}
}
