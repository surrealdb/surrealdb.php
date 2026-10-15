<?php

namespace SurrealDB\SDK\Codec;

use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Codec\Cbor\CborWriter;

final class CborSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        return CborWriter::encode(CborValueMapper::encode($data));
    }
}
