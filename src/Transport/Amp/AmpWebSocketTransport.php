<?php

namespace SurrealDB\SDK\Transport\Amp;

use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\WebsocketMessage;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\Endpoint;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Exceptions\UnexpectedServerResponseException;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;

use function Amp\Websocket\Client\connect;
use function is_array;

/**
 * A duplex WebSocket transport using amphp/websocket-client (Amp v3 / Revolt).
 *
 * Requires amphp/websocket-client and a running event loop.
 */
final class AmpWebSocketTransport implements DuplexTransportInterface
{
    private mixed $connection = null;

    public function __construct(
        private readonly DriverContext $context,
        private readonly Endpoint $endpoint,
    ) {}

    public function open(): void
    {
        $handshake = (new WebsocketHandshake($this->endpoint->uri))
            ->withHeader('Sec-WebSocket-Protocol', $this->context->format->subprotocol());

        $this->connection = connect($handshake);
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && !$this->connection->isClosed();
    }

    public function sendFrame(string $payload): void
    {
        if ($this->connection === null) {
            throw new SurrealException('WebSocket is not connected');
        }

        if ($this->context->format === CodecEnum::JSON) {
            $this->connection->sendText($payload);
        } else {
            $this->connection->sendBinary($payload);
        }
    }

    public function receiveFrame(?float $timeout = null): ?string
    {
        $message = $this->connection?->receive();

        if ($message instanceof WebsocketMessage) {
            return $message->buffer();
        }

        return null;
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
