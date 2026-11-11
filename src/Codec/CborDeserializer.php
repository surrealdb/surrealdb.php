<?php

namespace SurrealDB\SDK\Codec;

use Beau\CborPHP\Cbor;
use Beau\CborPHP\exceptions\CborException;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Codec\Cbor\Tags\SurrealTags;
use SurrealDB\SDK\Exceptions\SerializationException;

final class CborDeserializer implements DeserializerInterface
{
    /**
     * The decoder, configured once. The tag handlers and the reviver read
     * the SurrealDB value types, and refuse any other tag. Byte strings stay
     * apart from text, and anything that is not exactly one value with text
     * or int keys, each once, is refused; nesting stops at 512 levels.
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
            $decoded = self::cbor()->decode($data);
        } catch (CborException $error) {
            // A tag handler's own error, which cbor.php wraps with its offset.
            if ($error->getPrevious() instanceof SerializationException) {
                throw $error->getPrevious();
            }

            throw new SerializationException('Serialization', 'Malformed CBOR: ' . $error->getMessage());
        }

        return CborValueMapper::decode($decoded);
    }

    private static function cbor(): Cbor
    {
        return self::$cbor ??= new Cbor(
            tags: SurrealTags::decoders(),
            reviver: SurrealTags::reviver(),
            byteStrings: true,
            strict: true,
        );
    }
}
