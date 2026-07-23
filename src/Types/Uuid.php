<?php

namespace SurrealDB\Types;

use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid as SymfonyUuid;

/**
 * A SurrealQL `uuid` value, backed by {@see \Symfony\Component\Uid\Uuid}.
 *
 * Supports parsing from the canonical RFC 4122 string or raw 16-byte binary,
 * and generation of v4 (random) and v7 (time-ordered) UUIDs.
 */
final class Uuid extends Value
{
    private function __construct(private readonly AbstractUid $inner) {}

    /**
     * Wrap a UUID provided as a string, raw binary, another {@see self}, or a
     * Symfony UID instance.
     */
    public static function from(self|AbstractUid|string $uuid): self
    {
        if ($uuid instanceof self) {
            return $uuid;
        }

        if ($uuid instanceof AbstractUid) {
            return new self($uuid);
        }

        return new self(SymfonyUuid::fromString($uuid));
    }

    /**
     * Parse a UUID from its canonical string representation.
     */
    public static function fromString(string $uuid): self
    {
        return new self(SymfonyUuid::fromString($uuid));
    }

    /**
     * Parse a UUID from its raw 16-byte binary representation.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self(SymfonyUuid::fromBinary($bytes));
    }

    /**
     * Generate a new random (v4) UUID.
     */
    public static function v4(): self
    {
        return new self(SymfonyUuid::v4());
    }

    /**
     * Generate a new time-ordered (v7) UUID.
     */
    public static function v7(): self
    {
        return new self(SymfonyUuid::v7());
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->inner->equals($other->inner);
    }

    /**
     * The canonical RFC 4122 string (e.g. `09748193-048a-4bfb-b825-8528cf74fdc1`).
     */
    public function __toString(): string
    {
        return $this->inner->toRfc4122();
    }

    /**
     * The raw 16-byte binary representation.
     */
    public function toBytes(): string
    {
        return $this->inner->toBinary();
    }

    /**
     * Inline SurrealQL literal form: `u"..."`.
     */
    public function escape(): string
    {
        return 'u' . self::encodeString($this->__toString());
    }

    /**
     * @return array{'$uuid': string}
     */
    public function jsonSerialize(): array
    {
        return ['$uuid' => $this->__toString()];
    }
}
