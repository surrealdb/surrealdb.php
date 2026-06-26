<?php

namespace SurrealDB\SDK\Enum;

enum CodecEnum: string
{
    case CBOR = "cbor"; // main codec for surrealdb which we want to use it for now
    case JSON = "json"; // zero-dependency default shipped with the SDK
    case GRPC = "grpc"; // supported in surrealdb, we can ignore it for now
    case SQON = "sqon"; // not supported yet / nor a package that is available yet

    /**
     * The HTTP `Content-Type` / `Accept` header for this wire format.
     */
    public function contentType(): string
    {
        return match ($this) {
            self::CBOR => 'application/cbor',
            self::JSON => 'application/json',
            self::GRPC => 'application/grpc',
            self::SQON => 'application/surrealdb',
        };
    }

    /**
     * The WebSocket subprotocol negotiated for this wire format.
     */
    public function subprotocol(): string
    {
        return $this->value;
    }
}
