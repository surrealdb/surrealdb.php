<?php

namespace SurrealDB\Exceptions;

use SurrealDB\Rpc\RpcError;

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
        $kind = $error->kind ?? self::kindFromCode($error->code);

        return match ($kind) {
            'Validation' => new ValidationException($kind, $error->message, $error->code, $error->details),
            'Configuration' => new ConfigurationException($kind, $error->message, $error->code, $error->details),
            'Thrown' => new ThrownException($kind, $error->message, $error->code, $error->details),
            'Query' => new QueryException($kind, $error->message, $error->code, $error->details),
            'Serialization' => new SerializationException($kind, $error->message, $error->code, $error->details),
            'NotAllowed' => new NotAllowedException($kind, $error->message, $error->code, $error->details),
            'NotFound' => new NotFoundException($kind, $error->message, $error->code, $error->details),
            'AlreadyExists' => new AlreadyExistsException($kind, $error->message, $error->code, $error->details),
            'Internal' => new InternalException($kind, $error->message, $error->code, $error->details),
            default => new self($kind, $error->message, $error->code, $error->details),
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
