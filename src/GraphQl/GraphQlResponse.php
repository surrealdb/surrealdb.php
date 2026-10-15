<?php

namespace SurrealDB\SDK\GraphQl;

use SurrealDB\SDK\Exceptions\GraphQlException;
use function is_array;

/**
 * A GraphQL response envelope: the `data` and the `errors` the server
 * reported. GraphQL can return partial data alongside errors, so errors do
 * not throw unless {@see self::dataOrThrow()} is used.
 */
final readonly class GraphQlResponse
{
    /**
     * @param list<array<string,mixed>> $errors
     */
    public function __construct(
        public mixed $data,
        public array $errors = [],
    ) {}

    public static function fromResult(mixed $result): self
    {
        if (!is_array($result)) {
            return new self(null);
        }

        $errors = [];

        foreach (is_array($result['errors'] ?? null) ? $result['errors'] : [] as $error) {
            if (is_array($error)) {
                /** @var array<string,mixed> $error */
                $errors[] = $error;
            }
        }

        return new self($result['data'] ?? null, $errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @throws GraphQlException when the server reported errors
     */
    public function dataOrThrow(): mixed
    {
        if ($this->errors !== []) {
            throw new GraphQlException($this->errors);
        }

        return $this->data;
    }
}
