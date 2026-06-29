<?php

namespace SurrealDB\SDK\Transport\Swoole;

use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\WebSocket\Frame;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\Endpoint;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Exceptions\UnexpectedServerResponseException;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use function is_array;

/**
 * A duplex WebSocket transport using OpenSwoole's native coroutine HTTP client.
 * `recv()` cooperatively yields the coroutine while waiting for a frame.
 *
 * Requires the OpenSwoole extension and must run within a coroutine context.
 */
final class SwooleWebSocketTransport implements DuplexTransportInterface
{
    private ?Client $client = null;

    public function __construct(
        private readonly DriverContext $context,
        private readonly Endpoint $endpoint,
    ) {}

    public function open(): void
    {
        $secure = $this->endpoint->scheme === 'wss';
        $port = $this->endpoint->port ?? ($secure ? 443 : 80);

        $client = new Client($this->endpoint->host, $port, $secure);
        $client->set(['websocket_mask' => true, 'timeout' => -1]);
        $client->setHeaders(['Sec-WebSocket-Protocol' => $this->context->format->subprotocol()]);

        $path = $this->endpoint->path !== '' ? $this->endpoint->path : '/';

        if (!$client->upgrade($path)) {
            throw new SurrealException('OpenSwoole WebSocket upgrade failed (errCode ' . $client->errCode . ')');
        }

        $this->client = $client;
    }

    public function close(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    public function isConnected(): bool
    {
        return $this->client !== null && (bool) $this->client->connected;
    }

    public function sendFrame(string $payload): void
    {
        if ($this->client === null) {
            throw new SurrealException('WebSocket is not connected');
        }

        $opcode = $this->context->format === CodecEnum::JSON ? 0x1 : 0x2;
        $this->client->push($payload, $opcode);
    }

    public function receiveFrame(?float $timeout = null): ?string
    {
        if ($this->client === null) {
            return null;
        }

        $frame = $this->client->recv($timeout ?? -1.0);

        if ($frame instanceof Frame) {
            return (string) $frame->data;
        }

        return null;
    }

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
