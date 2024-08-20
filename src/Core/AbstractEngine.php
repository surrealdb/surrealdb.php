<?php

namespace Surreal\Core;

abstract class AbstractEngine
{
    public readonly string $host;

    public function __construct(
        string $host
    ) { $this->host = $host; }

    /**
     * Connects to the engine.
     * @return void
     */
    abstract public function connect(): void;

    /**
     * Closes the connection to the engine.
     * @return bool
     */
    abstract public function close(): bool;

    /**
     * Sends a rpc request.
     * @param RpcMessage $message
     */
    abstract public function rpc(RpcMessage $message): mixed;

    /**
     * Set the timeout for the requests in seconds.
     * @param int $seconds
     * @return void
     */
    abstract public function setTimeout(int $seconds): void;

    /**
     * Get the timeout for the requests in seconds.
     * @return int
     */
    abstract public function getTimeout(): int;
}