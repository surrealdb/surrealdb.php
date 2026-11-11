<?php

namespace SurrealDB\Types;

use function count;
use function is_array;
use function is_float;
use function is_object;
use function is_resource;

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
     * Members are looked up by a key of their type and value, so building a
     * set takes linear time. Equal still means what it did: a value object's
     * {@see Value::equals()}, and `===` for anything else, so `1`, `1.0`,
     * `'1'` and `true` are four members. Only members without a key, such as
     * NAN or a value object that has none, are compared one by one, and only
     * with each other.
     *
     * @param iterable<T> $items
     */
    public function __construct(iterable $items = [])
    {
        $unique = [];
        $keys = [];
        $unkeyed = [];

        foreach ($items as $item) {
            $key = $item instanceof Value ? self::valueKey($item) : self::identicalKey($item);

            if ($key === null) {
                if (!self::contains($unkeyed, $item)) {
                    $unkeyed[] = $item;
                    $unique[] = $item;
                }
            } elseif (!isset($keys[$key])) {
                $keys[$key] = true;
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
            if (!self::same($value, $other->values[$index])) {
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

    /**
     * A key that two values share exactly when `===` holds between them, or
     * null when there is none. Objects are identical only to themselves; a
     * member keeps any object it holds alive, so its id stays unique.
     */
    private static function identicalKey(mixed $value): ?string
    {
        if (is_array($value)) {
            $key = 'a' . count($value) . '[';

            foreach ($value as $name => $item) {
                $itemKey = self::identicalKey($item);

                if ($itemKey === null) {
                    return null;
                }

                $key .= self::scalarKey($name) . $itemKey;
            }

            return $key . ']';
        }

        return match (true) {
            is_object($value) => 'o' . spl_object_id($value) . ';',
            // -0.0 === 0.0, and NAN is identical to nothing.
            is_float($value) => is_nan($value) ? null : 'f' . pack('E', $value === 0.0 ? 0.0 : $value),
            is_resource($value) => null,
            default => self::scalarKey($value),
        };
    }
}
