<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\SDK\Codec\Codec;
use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;

/**
 * An in-memory duplex transport. Each sent frame is answered by the configured
 * responder (keyed by method); unsolicited frames can be injected to simulate
 * live notifications.
 */
final class FakeDuplexTransport implements DuplexTransportInterface
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    /** @var list<string> */
    private array $inbox = [];

    private bool $connected = false;

    /** @var \Closure(string, array<int,mixed>, ?string): ?array<string,mixed> */
    private \Closure $responder;

    public function __construct(
        \Closure $responder,
        private readonly Codec $codec = new Codec(
            new \SurrealDB\SDK\Codec\JsonSerializer(),
            new \SurrealDB\SDK\Codec\JsonDeserializer(),
        ),
    ) {
        $this->responder = $responder;
    }

    public function open(): void
    {
        $this->connected = true;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function sendFrame(string $payload): void
    {
        $message = $this->codec->deserialize($payload);

        if (!is_array($message)) {
            return;
        }

        $this->sent[] = $message;

        $response = ($this->responder)($message['method'] ?? '', $message['params'] ?? [], $message['id'] ?? null);

        if ($response !== null) {
            $this->inbox[] = $this->codec->serialize($response);
        }
    }

    public function receiveFrame(?float $timeout = null): ?string
    {
        return $this->inbox === [] ? null : array_shift($this->inbox);
    }

    public function send(RpcRequest $request): RpcResponse
    {
        $this->sendFrame($this->codec->serialize($request->toWire()));
        $frame = $this->receiveFrame();

        return $frame !== null
            ? RpcResponse::fromWire((array) $this->codec->deserialize($frame))
            : new RpcResponse($request->id);
    }

    /**
     * Inject an unsolicited frame (e.g. a live-query notification).
     *
     * @param array<string,mixed> $frame
     */
    public function pushIncoming(array $frame): void
    {
        $this->inbox[] = $this->codec->serialize($frame);
    }
}
