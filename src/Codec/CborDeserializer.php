<?php

namespace SurrealDB\SDK\Codec;

use SurrealDB\SDK\Codec\Cbor\CborReader;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;

final class CborDeserializer implements DeserializerInterface
{
    public function deserialize(string $data): mixed
    {
        if ($data === '') {
            return null;
        }

        return CborValueMapper::decode(CborReader::decode($data));
    }
}
