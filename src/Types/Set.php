<?php

namespace SurrealDB\SDK\Types;

use function count;

/**
 * A SurrealQL `set` value: an array whose items are deduplicated.
 *
 * @template T
 */
final class Set extends Value
{
    /** @var list<T> */
    public readonly array $values;

    /**
     * @param iterable<T> $items
     */
    public function __construct(iterable $items = [])
    {
        $unique = [];

        foreach ($items as $item) {
            if (!self::contains($unique, $item)) {
                $unique[] = $item;
            }
        }

        $this->values = $unique;
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self || count($this->values) !== count($other->values)) {
            return false;
        }

        foreach ($this->values as $index => $value) {
            $otherValue = $other->values[$index];

            $matches = $value instanceof Value ? $value->equals($otherValue) : $value == $otherValue;

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return $this->escape();
    }

    /**
     * Inline SurrealQL literal form: an array literal `[ ... ]`.
     */
    public function escape(): string
    {
        return '[ ' . implode(', ', array_map(Value::toSurql(...), $this->values)) . ' ]';
    }

    /**
     * @return array{'$set': list<T>}
     */
    public function jsonSerialize(): array
    {
        return ['$set' => $this->values];
    }

    /**
     * @param list<T> $items
     * @param T $candidate
     */
    private static function contains(array $items, mixed $candidate): bool
    {
        foreach ($items as $item) {
            $matches = $item instanceof Value ? $item->equals($candidate) : $item === $candidate;

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
