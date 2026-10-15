<?php

namespace SurrealDB\Types;

use JsonSerializable;

/**
 * A range bound whose value is included in the range (inclusive endpoint).
 *
 * @template T
 */
final class BoundIncluded implements \JsonSerializable
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
     * @return array{'$boundIncluded': T}
     */
    public function jsonSerialize(): array
    {
        return ['$boundIncluded' => $this->value];
    }
}
