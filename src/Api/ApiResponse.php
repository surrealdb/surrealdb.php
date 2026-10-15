<?php

namespace SurrealDB\SDK\Api;

use function is_array;
use function is_int;
use function is_string;

/**
 * The response of a user-defined API endpoint (`DEFINE API`), as returned by
 * `api::invoke`.
 *
 * @template TBody
 */
final readonly class ApiResponse
{
    /**
     * @param TBody                $body
     * @param array<string,string> $headers
     */
    public function __construct(
        public int $status,
        public mixed $body = null,
        public array $headers = [],
    ) {}

    /**
     * @param array<array-key,mixed> $result
     *
     * @return self<mixed>
     */
    public static function fromResult(array $result): self
    {
        $headers = [];

        foreach (is_array($result['headers'] ?? null) ? $result['headers'] : [] as $name => $value) {
            if (is_string($value)) {
                $headers[(string) $name] = $value;
            }
        }

        return new self(
            is_int($result['status'] ?? null) ? $result['status'] : 0,
            $result['body'] ?? null,
            $headers,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
