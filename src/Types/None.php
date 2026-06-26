<?php

namespace SurrealDB\SDK\Types;

/**
 * The SurrealQL `NONE` value (the absence of a value, distinct from `NULL`).
 */
final class None extends Value
{
    private static ?self $instance = null;

    /**
     * The shared {@see None} singleton.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self;
    }

    public function __toString(): string
    {
        return 'NONE';
    }

    public function escape(): string
    {
        return 'NONE';
    }

    /**
     * @return array{'$none': true}
     */
    public function jsonSerialize(): array
    {
        return ['$none' => true];
    }
}
