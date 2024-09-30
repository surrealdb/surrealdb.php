<?php

namespace Surreal\Core\Engines;

use Exception;
use Surreal\Cbor\CBOR;
use Surreal\Core\AbstractEngine;
use Surreal\Core\Curl\HttpContentFormat;
use Surreal\Core\Responses\Types\RpcResponse;
use Surreal\Core\Results\RpcResult;
use Surreal\Core\RpcMessage;
use WebSocket\Client as WebsocketClient;
use WebSocket\Middleware\{CloseHandler, PingResponder};

class WsEngine extends AbstractEngine
{
    private WebsocketClient $client;
    private int $incrementalId = 0;

    /**
     * @param string $host
     * @throws Exception
     */
    public function __construct(string $host)
    {
        $this->client = (new WebsocketClient($host))
            ->addMiddleware(new CloseHandler())
            ->addMiddleware(new PingResponder())
            ->addHeader("Sec-WebSocket-Protocol", "cbor")
            ->setTimeout(5);

        parent::__construct($host);
    }

    /**
     * Set the timeout for the websocket connection in seconds.
     * @param int $seconds
     * @return void
     */
    public function setTimeout(int $seconds): void
    {
        $this->client->setTimeout($seconds);
    }

    /**
     * Check if the websocket connection is open.
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    /**
     * Closes the websocket connection
     * @return bool
     */
    public function close(): bool
    {
        return $this->client->close()->getCloseStatus() === 1000;
    }

    /**
     * Executes the given message and returns the result
     * @param RpcMessage $message
     * @return mixed
     * @throws Exception
     */
    public function rpc(RpcMessage $message): mixed
    {
        $id = $this->incrementalId++;
        $payload = $message->setId($id)->toCborString();

        $this->client->binary($payload);

        // This reads the response from the websocket
        // Blocking the main thread until the response is received.
        // This ensures that the response is received in the order it was sent.

        while ($result = $this->client->receive()) {
            $content = $result->getContent();

            if ($content === "") {
                continue;
            }

            $content = CBOR::decode($content);

            if ($content["id"] === $id) {
                $response = RpcResponse::from($content, HttpContentFormat::CBOR, 200);
                return RpcResult::from($response);
            }
        }

        throw new Exception("No response received");
    }

    /**
     * Get the timeout for the websocket connection in seconds.
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->client->getTimeout();
    }

    /**
     * @throws Exception
     */
    public function connect(): void
    {
        $this->client->connect();

        if (!$this->client->isConnected()) {
            throw new Exception("Failed to connect to the websocket server");
        }
    }
}