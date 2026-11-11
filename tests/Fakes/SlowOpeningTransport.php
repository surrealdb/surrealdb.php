<?php

namespace SurrealDB\Tests\Fakes;

use Revolt\EventLoop;
use SurrealDB\Codec\CborDeserializer;
use SurrealDB\Codec\CborSerializer;
use SurrealDB\Codec\Codec;
use SurrealDB\Contracts\DuplexTransportInterface;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/**
 * A transport for the Revolt event loop whose open() takes `$openDelay`
 * seconds, so other fibers run while it connects. It answers `version` and
 * nothing else.
 */
final class SlowOpeningTransport implements DuplexTransportInterface
{
    public bool $connected = false;

    /** @var list<string> */
    private array $inbox = [];

    private ?EventLoop\Suspension $reader = null;

    private readonly Codec $codec;

    public function __construct(private readonly float $openDelay = 0.0)
    {
        $this->codec = new Codec(new CborSerializer(), new CborDeserializer());
    }

    public function open(): void
    {
        if ($this->openDelay > 0) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay($this->openDelay, static fn () => $suspension->resume());
            $suspension->suspend();
        }

        $this->connected = true;
    }

    public function close(): void
    {
        $this->drop();
    }

    /** The server end goes away. */
    public function drop(): void
    {
        $this->connected = false;
        $reader = $this->reader;
        $this->reader = null;
        $reader?->resume(null);
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function sendFrame(string $payload): void
    {
        if (!$this->connected) {
            throw new \RuntimeException('Not connected.');
        }

        $message = (array) $this->codec->deserialize($payload);
        $reply = $this->codec->serialize([
            'id' => $message['id'] ?? null,
            'result' => ($message['method'] ?? null) === 'version' ? 'surrealdb-3.0.0' : null,
        ]);

        if ($this->reader !== null) {
            $reader = $this->reader;
            $this->reader = null;
            EventLoop::queue(static fn () => $reader->resume($reply));

            return;
        }

        $this->inbox[] = $reply;
    }

    public function receiveFrame(?float $timeout = null): ?string
    {
        if ($this->inbox !== []) {
            return array_shift($this->inbox);
        }

        if (!$this->connected) {
            return null;
        }

        $this->reader = EventLoop::getSuspension();

        /** @var string|null */
        return $this->reader->suspend();
    }

    public function send(RpcRequest $request): RpcResponse
    {
        throw new \LogicException('Not used by the WebSocket engine.');
    }
}
