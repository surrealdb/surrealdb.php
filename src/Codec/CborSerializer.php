<?php

namespace SurrealDB\SDK\Codec;

use Beau\CborPHP\Cbor;
use Beau\CborPHP\exceptions\CborException;
use SurrealDB\SDK\Codec\Cbor\CborValueMapper;
use SurrealDB\SDK\Exceptions\SerializationException;
use function get_debug_type;

final class CborSerializer implements SerializerInterface
{
    private static ?Cbor $cbor = null;

    /**
     * @throws SerializationException for a value SurrealDB has no type for
     */
    public function serialize(mixed $data): string
    {
        try {
            return self::cbor()->encode(CborValueMapper::encode($data));
        } catch (CborException $error) {
            throw new SerializationException('Serialization', $error->getMessage());
        }
    }

    /**
     * The encoder, configured once. Its replacer gets what the mapper left as
     * it is: a value SurrealDB has no type for.
     */
    private static function cbor(): Cbor
    {
        return self::$cbor ??= new Cbor(replacer: static function (mixed $value): never {
            throw new SerializationException(
                'Serialization',
                'Cannot encode a value of type ' . get_debug_type($value) . ' as CBOR.',
            );
        });
    }
}
