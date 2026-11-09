<?php

namespace SurrealDB\Tests\Fakes;

use SurrealDB\SDK\Contracts\DuplexTransportInterface;
use SurrealDB\SDK\Rpc\RpcRequest;
use SurrealDB\SDK\Rpc\RpcResponse;
use function str_contains;

/**
 * Wraps a real transport and can lose the socket just before a reply
 * arrives, while the request that reply answers is still waiting.
 */
final class DroppingTransport implements DuplexTransportInterface
{
    /** Lose the socket instead of returning the next reply. */
    public bool $dropOnNextReply = false;

    /** Arm {@see self::$dropOnNextReply} once a payload containing this is sent. */
    public ?string $dropAfterSending = null;

    /**
     * Throw this instead of returning the next reply, as a client does for a
     * socket that fails mid-read rather than at the end of the stream.
     */
    public ?\Throwable $throwOnNextReply = null;

    public function __construct(private readonly DuplexTransportInterface $inner) {}

    public function open(): void
    {
        $this->inner->open();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function send(RpcRequest $request): RpcResponse
    {
        return $this->inner->send($request);
    }

    public function sendFrame(string $payload): void
    {
        if ($this->dropAfterSending !== null && str_contains($payload, $this->dropAfterSending)) {
            $this->dropAfterSending = null;
            $this->dropOnNextReply = true;
        }

        $this->inner->sendFrame($payload);
    }

    public function receiveFrame(?float $timeout = null): ?string
    {
        $frame = $this->inner->receiveFrame($timeout);

        if ($frame !== null && $this->throwOnNextReply !== null) {
            $error = $this->throwOnNextReply;
            $this->throwOnNextReply = null;

            throw $error;
        }

        if ($frame !== null && $this->dropOnNextReply) {
            $this->dropOnNextReply = false;
            $this->inner->close();

            return null;
        }

        return $frame;
    }
}
