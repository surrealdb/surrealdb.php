<?php

namespace SurrealDB\Types;

/**
 * An uncomputed SurrealQL future value (e.g. `<future> { ... }`).
 *
 * @deprecated Futures were removed in SurrealDB 3.0; retained for compatibility
 *             with older servers and parity with the JS SDK.
 */
final class Future extends Value
{
    public function __construct(public readonly string $body) {}

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->body === $other->body;
    }

    public function __toString(): string
    {
        return '<future> ' . $this->body;
    }

    public function escape(): string
    {
        return $this->__toString();
    }

    /**
     * @return array{'$future': string}
     */
    public function jsonSerialize(): array
    {
        return ['$future' => $this->body];
    }
}
