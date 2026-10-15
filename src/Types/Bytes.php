<?php

namespace SurrealDB\Types;

use function strlen;

/**
 * A SurrealQL `bytes` value wrapping a raw binary string.
 *
 * Over the JSON protocol the bytes are carried base64url-encoded (the SQON-J
 * `$bytes` representation).
 */
final class Bytes extends Value
{
    public function __construct(public readonly string $bytes) {}

    /**
     * Construct from a base64url-encoded string.
     */
    public static function fromBase64(string $base64): self
    {
        $padded = strtr($base64, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return new self(base64_decode($padded, true) ?: '');
    }

    /**
     * The raw bytes as a base64url string (no padding).
     */
    public function toBase64(): string
    {
        return rtrim(strtr(base64_encode($this->bytes), '+/', '-_'), '=');
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->bytes === $other->bytes;
    }

    public function __toString(): string
    {
        return $this->bytes;
    }

    /**
     * Inline SurrealQL form: decode the base64url payload back to bytes.
     */
    public function escape(): string
    {
        return 'encoding::base64::decode(' . self::encodeString($this->toBase64()) . ')';
    }

    /**
     * @return array{'$bytes': string}
     */
    public function jsonSerialize(): array
    {
        return ['$bytes' => $this->toBase64()];
    }
}
