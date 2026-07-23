<?php

namespace SurrealDB\Codec;

/**
 * The default, zero-dependency serializer. Encodes RPC payloads as JSON, which
 * SurrealDB accepts over both the HTTP and WebSocket protocols.
 *
 * Swap in a CBOR serializer (via {@see SerializerInterface}) for the binary
 * protocol once a CBOR codec package is available.
 */
final class JsonSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
