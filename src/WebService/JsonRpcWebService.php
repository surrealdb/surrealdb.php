<?php

namespace Surreal\WebService;

use Amp\ByteStream\StreamException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\ClosedException;
use Amp\Websocket\WebsocketMessage;
use Illuminate\Support\Collection;
use JsonException;
use Spatie\Url\Url;
use Surreal\Config\ConfigContract;

use Surreal\Exceptions\ConnectionFailureException;
use Surreal\Exceptions\FailedRpcResponseError;
use Surreal\Responses\ApiErrorResponse;
use Surreal\WebService\RpcMessages\RpcMessageContract;
use Throwable;
use function Amp\Websocket\Client\websocketConnector;
use function retry;

/**
 * Some logic is based on https://github.com/Laragear/Surreal/blob/master/src/Tcp/WebsocketClient.php
 * So credits to Laragear!
 */
class JsonRpcWebService extends BaseWebService implements WebServiceContract
{
	protected Url $url;

	protected ?WebsocketConnection $connection = null;

	protected array $pendingRequests = [];

	public function __construct(ConfigContract $config)
	{
		parent::__construct($config);

		$this->url = Url::fromString($this->config->getWsUrl())->withPath('/rpc');
	}

	/**
	 * @return void
	 * @throws ConnectionFailureException
	 */
	protected function connect(): void
	{
		$handshake = new WebsocketHandshake($this->url, [
			'Authorization' => 'Basic ' . base64_encode($this->config->getUsername() . ':' . $this->config->getPassword()),
			'NS'            => $this->config->getNamespace(),
			'DB'            => $this->config->getDb(),
		]);

		try {
			$this->connection = retry(3, static function () use ($handshake): WebsocketConnection {
				return websocketConnector()->connect($handshake);
			});
		} catch (Throwable $e) {
			throw new ConnectionFailureException('Not connected to SurrealDB', $e->getCode(), $e);
		}
	}

	/**
	 * @return WebServiceContract
	 * @throws ConnectionFailureException
	 */
	public function prepareRequest(): WebServiceContract
	{
		if (!$this->connection) {
			$this->connect();
		}

		return $this;
	}

	public function send(RpcMessageContract $message): Collection|ApiErrorResponse
	{
		if (isset($this->pendingRequests[$message->getId()])) {
			throw new \Exception('Duplicate request ID');
		}

		$this->pendingRequests[$message->getId()] = $message;

		try {
			$this->connection->send($message->toJson());
		} catch (ClosedException $e) {
			throw new ConnectionFailureException('Not connected to SurrealDB', $e->getCode(), $e);
		}

		return $this->getResults($message, $this->connection->receive());
	}

	protected function getResults(RpcMessageContract $sentMessage, WebsocketMessage $message): Collection|ApiErrorResponse
	{
		if (!$message->isReadable()) {
			throw new FailedRpcResponseError('The SurrealDB message is not readable.');
		}

		if (!$message->isText()) {
			throw new FailedRpcResponseError('The SurrealDB message is not text.');
		}

		try {
			$serverMessage = json_decode($message->buffer(), false, 512, JSON_THROW_ON_ERROR);

			if ($serverMessage->id !== $sentMessage->getId()) {
				throw (new FailedRpcResponseError('The SurrealDB message is not for the correct request.'))->setSentMessage($sentMessage);
			}

			unset($this->pendingRequests[$sentMessage->getId()]);
		} catch (JsonException|StreamException) {
			throw (new FailedRpcResponseError('The SurrealDB message is invalid.'))->setSentMessage($sentMessage);
		} catch (ClosedException $e) {
			throw new ConnectionFailureException('Not connected to SurrealDB', $e->getCode(), $e);
		}

		// If the response from the server is an error, throw a failed response.
		if (isset($serverMessage->error)) {
			return new ApiErrorResponse($serverMessage->error);
		}

		$items = wrap($serverMessage->result ?? []);
		return new Collection($items);
	}

	public function close(): void
	{
		if ($this->connection && !$this->connection->isClosed()) {
			$this->connection->close();
			$this->connection = null;
		}
	}

	public function __destruct()
	{
		$this->close();
	}

	public function shouldReconstructPerRequest(): bool
	{
		return false;
	}
}
