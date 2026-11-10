<?php

namespace SurrealDB\SDK\Codec;

use Beau\CborPHP\Cbor;
use Beau\CborPHP\exceptions\CborException;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Exceptions\SerializationException;

final class CborDeserializer implements DeserializerInterface
{
    /**
     * The decoder, configured once. Byte strings stay apart from text, and
     * anything that is not exactly one value with text or int keys, each
     * once, is refused; nesting stops at 512 levels.
     */
    private static ?Cbor $cbor = null;

    /**
     * @throws SerializationException when the data is not CBOR the SDK can read
     */
    public function deserialize(string $data): mixed
    {
        if ($data === '') {
            return null;
        }

        try {
            $decoded = (self::$cbor ??= new Cbor(byteStrings: true, strict: true))->decode($data);
        } catch (CborException $error) {
            throw new SerializationException('Serialization', 'Malformed CBOR: ' . $error->getMessage());
        }

        return CborValueMapper::decode($decoded);
    }
}
