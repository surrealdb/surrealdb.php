<?php

namespace SurrealDB\SDK\Codec;

use Beau\CborPHP\CborDecoder;
use Beau\CborPHP\exceptions\CborException;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Exceptions\SerializationException;

final class CborDeserializer implements DeserializerInterface
{
    /**
     * @throws SerializationException when the data is not CBOR the SDK can read
     */
    public function deserialize(string $data): mixed
    {
        if ($data === '') {
            return null;
        }

        try {
            // Byte strings stay apart from text, and anything that is not
            // exactly one value with text or int keys is refused.
            $decoded = CborDecoder::decode($data, byteStrings: true, strict: true);
        } catch (CborException $error) {
            throw new SerializationException('Serialization', 'Malformed CBOR: ' . $error->getMessage());
        }

        return CborValueMapper::decode($decoded);
    }
}
