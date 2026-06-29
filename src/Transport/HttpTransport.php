<?php

namespace SurrealDB\SDK\Transport;

use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\SessionState;
use SurrealDB\SDK\Contracts\TransportInterface;
use SurrealDB\SDK\Exceptions\UnexpectedServerResponseException;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use function is_array;

/**
 * The default request/response transport: each call is an individual PSR-18
 * HTTP POST to the `/rpc` endpoint. Reads per-session namespace, database, and
 * bearer token from the connection state to build request headers — the
 * static-credential half of auth injection (cf. JS `fetchSurreal`).
 */
final class HttpTransport implements TransportInterface
{
    private readonly SurrealHttp $http;

    private bool $open = false;

    public function __construct(
        private readonly DriverContext $context,
        private readonly ConnectionState $state,
    ) {
        $this->http = new SurrealHttp(new HttpClientResolver($context));
    }

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }

    /**
     * @return RpcResponse<mixed>
     */
    public function send(RpcRequest $request): RpcResponse
    {
        $session = $this->state->session($request->session) ?? $this->state->rootSession;
        $body = $this->context->codec->serialize($request->toWire());

        $response = $this->http->request(
            $this->state->endpoint->httpUri(),
            'POST',
            $body,
            $this->headers($session),
        );

        $decoded = $this->context->codec->deserialize((string) $response->getBody());

        if (!is_array($decoded)) {
            throw new UnexpectedServerResponseException($decoded);
        }

        return RpcResponse::fromWire($decoded);
    }

    /**
     * @return array<string,string>
     */
    private function headers(SessionState $session): array
    {
        $headers = [
            'Content-Type' => $this->context->format->contentType(),
            'Accept' => $this->context->format->contentType(),
        ];

        if ($session->namespace !== null) {
            $headers['Surreal-NS'] = $session->namespace;
        }

        if ($session->database !== null) {
            $headers['Surreal-DB'] = $session->database;
        }

        if ($session->auth->accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $session->auth->accessToken;
        }

        return $headers;
    }
}
