<?php

namespace Surreal\Cbor\Types;

use \Ramsey\Uuid\Uuid as RamseyUuid;

final class Uuid implements \JsonSerializable
{
    public RamseyUuid $value;

    public function __construct(RamseyUuid|array|string $value)
    {
        $this->value = match (true) {
            is_string($value) => RamseyUuid::fromString($value),
            is_array($value) => RamseyUuid::fromBytes($value),
            default => $value,
        };
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->value->toString();
    }

    /**
     * Returns a version 4 (random) UUID
     * @return self
     */
    public static function v4(): self
    {
        return new self(RamseyUuid::uuid4());
    }

    /**
     * Returns a version 7 (Unix Epoch time) UUID
     * @return self
     */
    public static function v7(): self
    {
        return new self(RamseyUuid::uuid7());
    }

    /**
     * Returns a UUID from the given string
     * @param string $uuid
     * @return self
     */
    public static function fromString(string $uuid): self
    {
        return new self(RamseyUuid::fromString($uuid));
    }

    /**
     * Returns a UUID from the given bytes
     * @param string $bytes
     * @return self
     */
    public static function fromBytes(string $bytes): self
    {
        return new self(RamseyUuid::fromBytes($bytes));
    }

    /**
     * Checks whether the given value is a valid UUID
     * @param string|Uuid $uuid
     * @return bool
     */
    public function isUuid(string|Uuid $uuid): bool
    {
        return match (true) {
            $uuid instanceof Uuid => true,
            RamseyUuid::isValid($uuid) => true,
            default => false,
        };
    }

    /**
     * Returns the binary string representation of the UUID
     * @return string
     */
    public function getBytes(): string
    {
        return $this->value->getBytes();
    }
}