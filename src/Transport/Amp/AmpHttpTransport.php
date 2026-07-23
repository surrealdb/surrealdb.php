<?php

namespace SurrealDB\Transport\Amp;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use SurrealDB\Connection\ConnectionState;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\SessionState;
use SurrealDB\Contracts\TransportInterface;
use SurrealDB\Exceptions\HttpConnectionException;
use SurrealDB\Exceptions\UnexpectedServerResponseException;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use function is_array;

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
