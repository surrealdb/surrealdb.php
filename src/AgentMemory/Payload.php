<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;

/** Small helpers shared by the options classes when building wire payloads. */
final class Payload
{
    /**
     * Drops `null` entries so optional fields are omitted from the wire payload.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    public static function compact(array $fields): array
    {
        return array_filter($fields, static fn (mixed $value): bool => $value !== null);
    }

    /** Unwraps a backed enum to its wire value, passing strings through. */
    public static function enum(\BackedEnum|string|null $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }

    /**
     * Unwraps a list of backed enums and/or strings to their wire values.
     *
     * @param list<\BackedEnum|string>|null $values
     *
     * @return list<string>|null
     */
    public static function enums(?array $values): ?array
    {
        return $values === null ? null : array_map(
            static fn (\BackedEnum|string $value): string => (string) self::enum($value),
            $values,
        );
    }

    /**
     * Renders an entity reference in the `<type>/<name>` form the API filters
     * and anchors on. A string is parsed first, so the tagged
     * `entity:<type>/<name>` that responses carry is accepted, and a string
     * that is no entity ref at all fails here instead of matching nothing.
     *
     * @throws InvalidArgumentException when a string is not of the form `<type>/<name>`
     */
    public static function ref(EntityRef|string|null $ref): ?string
    {
        return match (true) {
            $ref === null => null,
            $ref instanceof EntityRef => (string) $ref,
            default => (string) EntityRef::parse($ref),
        };
    }

    /**
     * The keyset page parameters every collection takes: `count` is only sent
     * when asked for, since it costs the server a full count.
     *
     * @return array<string,mixed>
     */
    public static function page(?int $limit, ?string $cursor, bool $count = false): array
    {
        return self::compact([
            'limit' => $limit,
            'cursor' => $cursor,
            'count' => $count ? true : null,
        ]);
    }

    /**
     * The tri-temporal read window: known time (`asOf`), system time
     * (`atInstant`), and the world-time bounds.
     *
     * @return array<string,mixed>
     */
    public static function temporal(?string $asOf, ?string $atInstant, ?string $validFrom, ?string $validUntil): array
    {
        return self::compact([
            'asOf' => $asOf,
            'atInstant' => $atInstant,
            'validFrom' => $validFrom,
            'validUntil' => $validUntil,
        ]);
    }

    /**
     * A JSON object field: an empty PHP array would encode as the list `[]`,
     * which the server rejects where it expects a map, so it is sent as `{}`.
     *
     * @param array<string,mixed>|null $map
     *
     * @return array<string,mixed>|\stdClass|null
     */
    public static function object(?array $map): array|\stdClass|null
    {
        return $map === [] ? new \stdClass() : $map;
    }
}
