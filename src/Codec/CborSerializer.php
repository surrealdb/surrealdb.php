<?php

namespace SurrealDB\Codec;

use Beau\CborPHP\CborEncoder;
use SurrealDB\Codec\Cbor\CborValueMapper;

final class CborSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        return CborEncoder::encode(CborValueMapper::encode($data));
    }
}
