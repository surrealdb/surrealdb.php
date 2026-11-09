<?php

namespace SurrealDB\SDK\Codec;

use Beau\CborPHP\CborEncoder;
use Beau\CborPHP\exceptions\CborException;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Exceptions\SerializationException;
use function get_debug_type;

final class CborSerializer implements SerializerInterface
{
    /**
     * @throws SerializationException for a value SurrealDB has no type for
     */
    public function serialize(mixed $data): string
    {
        // The encoder's replacer gets what the mapper left as it is: a value
        // SurrealDB has no type for. It is not static: the encoder binds it.
        $refuse = function (mixed $key, mixed $value): never {
            throw new SerializationException(
                'Serialization',
                'Cannot encode a value of type ' . get_debug_type($value) . ' as CBOR.',
            );
        };

        try {
            return CborEncoder::encode(CborValueMapper::encode($data), $refuse);
        } catch (CborException $error) {
            throw new SerializationException('Serialization', $error->getMessage());
        }
    }
}
