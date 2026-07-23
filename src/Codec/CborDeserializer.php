<?php

namespace SurrealDB\Codec;

use Beau\CborPHP\CborDecoder;
use SurrealDB\Codec\Cbor\CborValueMapper;

final class CborDeserializer implements DeserializerInterface
{
    public function deserialize(string $data): mixed
    {
        if ($data === '') {
            return null;
        }

        return CborValueMapper::decode(CborDecoder::decode($data));
    }
}
