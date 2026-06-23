<?php

namespace SurrealDB\SDK\Codec;

use Beau\CborPHP\CborDecoder;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;

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
