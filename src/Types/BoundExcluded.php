<?php

namespace SurrealDB\Types;

use JsonSerializable;

/**
 * A range bound whose value is excluded from the range (exclusive endpoint).
 *
 * @template T
 */
final class BoundExcluded implements \JsonSerializable
{
    /** @var T */
    public mixed $value;

    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        $this->value = $value;
    }

    /**
     * @return array{'$boundExcluded': T}
     */
    public function jsonSerialize(): array
    {
        return ['$boundExcluded' => $this->value];
    }
}
