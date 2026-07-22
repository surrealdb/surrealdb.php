<?php

namespace SurrealDB\SDK\Rpc;

use SurrealDB\SDK\Exceptions\ServerException;
use function is_array;
use function is_string;

/**
 * An immutable RPC response. Either carries a `result` or an `error`, matching
 * the JS `RpcResponse` union (`RpcSuccessResponse | RpcErrorResponse`).
 *
 * @template TResult
 */
final readonly class RpcResponse
{
    /**
     * @param TResult|null $result
     */
    public function __construct(
        public ?string $id = null,
        public mixed $result = null,
        public ?RpcError $error = null,
    ) {}

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Return the result, or throw the most specific {@see ServerException}
     * when the response represents an error.
     *
     * @return TResult|null
     */
    public function resultOrThrow(): mixed
    {
        if ($this->error !== null) {
            throw ServerException::fromRpc($this->error);
        }

        return $this->result;
    }

    /**
     * Build a response from a decoded wire payload.
     *
     * @param array<string,mixed> $decoded
     *
     * @return self<mixed>
     */
    public static function fromWire(array $decoded): self
    {
        $id = isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : null;

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];

            return new self($id, null, new RpcError(
                code: (int) ($error['code'] ?? 0),
                message: (string) ($error['message'] ?? 'Unknown server error'),
                kind: isset($error['kind']) ? (string) $error['kind'] : null,
                details: isset($error['details']) && is_array($error['details']) ? $error['details'] : null,
            ));
        }

        return new self($id, $decoded['result'] ?? null, null);
    }
}
