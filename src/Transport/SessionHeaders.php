<?php

namespace SurrealDB\Transport;

use SurrealDB\Connection\SessionState;
use SurrealDB\Exceptions\InvalidValueException;
use SurrealDB\Rpc\RpcRequest;
use function in_array;
use function is_string;
use function sprintf;

/**
 * The headers that carry a session over HTTP: its namespace, database and
 * bearer token. Used by the HTTP transports and by import and export, which
 * go over HTTP on every engine.
 */
final class SessionHeaders
{
    /**
     * Requests that bring their own credentials. The server refuses a request
     * whose bearer token has expired before reading it, so sending the
     * session's token with these would stop it signing in again once that
     * token expired.
     */
    private const array SIGN_IN_METHODS = ['signin', 'signup', 'authenticate', 'refresh'];

    /**
     * @return array<string,string>
     */
    public static function forRequest(SessionState $session, RpcRequest $request): array
    {
        if (!in_array($request->method, self::SIGN_IN_METHODS, true)) {
            return self::of($session);
        }

        $headers = self::of($session, bearer: false);
        $token = $request->params[0] ?? null;

        // The token being authenticated is the bearer a proxy in front of
        // the server may ask for.
        if ($request->method === 'authenticate' && is_string($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * @return array<string,string>
     */
    public static function of(SessionState $session, bool $bearer = true): array
    {
        $headers = [];

        if ($session->namespace !== null) {
            $headers['Surreal-NS'] = self::carriable('namespace', $session->namespace);
        }

        if ($session->database !== null) {
            $headers['Surreal-DB'] = self::carriable('database', $session->database);
        }

        if ($bearer && $session->auth->accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $session->auth->accessToken;
        }

        return $headers;
    }

    /**
     * The server reads these headers as printable ASCII only. A name outside
     * that fails every request with a 401, and a line break cannot be sent
     * at all, so such a name is refused up front.
     *
     * @param 'namespace'|'database' $what
     */
    public static function carriable(string $what, string $name): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $name) !== 1) {
            throw new InvalidValueException(sprintf(
                'The %s name %s cannot be sent in an HTTP header, which takes printable ASCII only. Use a WebSocket connection for it.',
                $what,
                json_encode($name, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ));
        }

        return $name;
    }
}
