<?php

namespace SurrealDB\Types;

/**
 * A SurrealQL record id held in its raw, unparsed string form (e.g.
 * `person:tobie`). Useful when the id should be passed through verbatim rather
 * than represented as separate table and id parts in a {@see RecordId}.
 */
final class StringRecordId extends Value
{
    public readonly string $id;

    /**
     * @param self|RecordId<string>|string $id
     */
    public function __construct(self|RecordId|string $id)
    {
        $this->id = match (true) {
            $id instanceof self => $id->id,
            $id instanceof RecordId => $id->escaped,
            default => $id,
        };
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    /**
     * Inline SurrealQL literal form: a `r"..."` record literal.
     */
    public function escape(): string
    {
        return 'r' . self::encodeString($this->id);
    }

    /**
     * @return array{'$recordIdString': string}
     */
    public function jsonSerialize(): array
    {
        return ['$recordIdString' => $this->id];
    }
}
