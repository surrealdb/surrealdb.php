<?php

namespace SurrealDB\SDK\Transport\Amp;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use SurrealDB\SDK\Connection\ConnectionState;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\SessionState;
use SurrealDB\SDK\Contracts\TransportInterface;
use SurrealDB\SDK\Exceptions\HttpConnectionException;
use SurrealDB\SDK\Exceptions\UnexpectedServerResponseException;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;

/**
 * A non-blocking HTTP transport using amphp/http-client, suited to the Amp /
 * Revolt runtime where a blocking PSR-18 client would stall the event loop.
 *
 * Requires amphp/http-client and a running event loop.
 */
final class AmpHttpTransport implements TransportInterface
{
    private mixed $client = null;

    public function __construct(
        private readonly DriverContext $context,
        private readonly ConnectionState $state,
    ) {}

    public function open(): void
    {
        $this->client = HttpClientBuilder::buildDefault();
    }

    public function close(): void
    {
        $this->client = null;
    }

    public function isConnected(): bool
    {
        return $this->client !== null;
    }

    /**
     * @return RpcResponse<mixed>
     */
    public function send(RpcRequest $request): RpcResponse
    {
        if ($this->client === null) {
            $this->open();
        }

        $session = $this->state->session($request->session) ?? $this->state->rootSession;

        $ampRequest = new Request($this->state->endpoint->httpUri(), 'POST');
        $ampRequest->setBody($this->context->codec->serialize($request->toWire()));

        foreach ($this->headers($session) as $name => $value) {
            $ampRequest->setHeader($name, $value);
        }

        $response = $this->client->request($ampRequest);
        $status = $response->getStatus();
        $content = $response->getBody()->buffer();

        if ($status < 200 || $status >= 300) {
            throw new HttpConnectionException($content, $status, $response->getReason(), $content);
        }

        $decoded = $this->context->codec->deserialize($content);

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
