<?php

namespace SurrealDB\SDK\Contracts;

/**
 * A full-duplex, frame-level transport (e.g. WebSocket). In addition to the
 * request/response {@see TransportInterface::send()}, it exposes raw frame
 * send/receive so the engine can multiplex many in-flight calls over one
 * connection and pump server-pushed live-query frames.
 *
 * Frames carry already-encoded bytes; encoding/decoding is the engine's job so
 * it can inspect response ids and route live messages.
 */
interface DuplexTransportInterface extends TransportInterface
{
    /** Send a single already-encoded frame. */
    public function sendFrame(string $payload): void;

    /**
     * Block for the next inbound frame's encoded bytes.
     *
     * @param float|null $timeout Seconds to wait, or null to block until a frame
     *                            arrives or the connection closes.
     * @return string|null The encoded bytes, or null when the connection closed.
     */
    public function receiveFrame(?float $timeout = null): ?string;
}
