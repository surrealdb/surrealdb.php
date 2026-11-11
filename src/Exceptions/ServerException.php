<?php

namespace SurrealDB\SDK\Exceptions;

use SurrealDB\SDK\Rpc\RpcError;

/**
 * The base class for all errors reported by the SurrealDB server.
 *
 * Mirrors the JS SDK `ServerError` tree: the structured `kind` allows
 * matching against known categories while unknown kinds from newer servers
 * pass through on this base class.
 */
class ServerException extends SurrealException
{
    /**
     * @param array<string,mixed>|null $details
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        int $code = 0,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * Build the most specific exception subclass for the given RPC error.
     */
    public static function fromRpc(RpcError $error): self
    {
        return self::create(
            $error->kind ?? self::kindFromCode($error->code),
            $error->message,
            $error->code,
            $error->details,
        );
    }

    /**
     * Build the most specific exception subclass for the given error kind.
     *
     * @param array<string,mixed>|null $details
     */
    public static function create(string $kind, string $message, int $code = 0, ?array $details = null): self
    {
        return match ($kind) {
            'Validation' => new ValidationException($kind, $message, $code, $details),
            'Configuration' => new ConfigurationException($kind, $message, $code, $details),
            'Thrown' => new ThrownException($kind, $message, $code, $details),
            'Query' => new QueryException($kind, $message, $code, $details),
            'Serialization' => new SerializationException($kind, $message, $code, $details),
            'NotAllowed' => new NotAllowedException($kind, $message, $code, $details),
            'NotFound' => new NotFoundException($kind, $message, $code, $details),
            'AlreadyExists' => new AlreadyExistsException($kind, $message, $code, $details),
            'Internal' => new InternalException($kind, $message, $code, $details),
            default => new self($kind, $message, $code, $details),
        };
    }

    private static function kindFromCode(int $code): string
    {
        return match ($code) {
            -32600, -32602, -32700 => 'Validation',
            -32601 => 'NotFound',
            default => 'Internal',
        };
    }
}
