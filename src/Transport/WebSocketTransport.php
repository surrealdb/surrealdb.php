<?php

namespace SurrealDB\SDK\Transport;

use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\Endpoint;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Contracts\WebSocketClientInterface;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Exceptions\UnexpectedServerResponseException;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use function is_array;

/**
 * The default duplex transport: wraps a {@see WebSocketClientInterface} and
 * negotiates the codec subprotocol. The engine drives correlation and live
 * routing through {@see sendFrame()}/{@see receiveFrame()}; {@see send()} is a
 * convenience for the non-multiplexed round trip.
 */
final class WebSocketTransport implements DuplexTransportInterface
{
    public function __construct(
        private readonly DriverContext $context,
        private readonly Endpoint $endpoint,
        private readonly WebSocketClientInterface $client,
    ) {}

    public function open(): void
    {
        $this->client->connect($this->endpoint->uri, [$this->context->format->subprotocol()]);
    }

    public function close(): void
    {
        $this->client->close();
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    public function sendFrame(string $payload): void
    {
        $this->client->send($payload, $this->context->format !== CodecEnum::JSON);
    }

    public function receiveFrame(?float $timeout = null): ?string
    {
        return $this->client->receive($timeout);
    }

    /**
     * @return RpcResponse<mixed>
     */
    public function send(RpcRequest $request): RpcResponse
    {
        $this->sendFrame($this->context->codec->serialize($request->toWire()));

        while (($frame = $this->receiveFrame()) !== null) {
            $decoded = $this->context->codec->deserialize($frame);

            if (is_array($decoded) && ($decoded['id'] ?? null) === $request->id) {
                return RpcResponse::fromWire($decoded);
            }
        }

        throw new UnexpectedServerResponseException();
    }
}
