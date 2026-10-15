<?php

namespace SurrealDB\SDK\Codec\Cbor;

/**
 * Marks an array to be encoded as a CBOR map even when it is empty or a list,
 * which a plain PHP array cannot express.
 */
final readonly class CborMap
{
    /**
     * @param array<array-key,mixed> $entries
     */
    public function __construct(public array $entries = []) {}
}
