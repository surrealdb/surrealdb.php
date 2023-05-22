<?php

namespace Surreal\Responses;

use Exception;
use Surreal\Client;
use Surreal\Model\SurrealModel;

/**
 * This class represents an individual query response item
 * This would contain:
 * - result[]
 * - status
 * - time
 * etc
 *
 * @template T of SurrealModel
 */
class QueryResponse
{
	/**
	 * @var T[]|null
	 */
	private mixed   $result = null;
	private ?string $status = null;
	private ?string $time   = null;

	/**
	 * @param                   $response
	 * @param class-string|null $modelClassFqn
	 * @param bool              $isDirectResults
	 *
	 * @throws Exception
	 */
	public function __construct($response, ?string $modelClassFqn = null, bool $isDirectResults = false)
	{
		if (!is_object($response)) {
			$this->result = [$response];

			return;
		}

		$this->status = $response->status ?? null;
		$this->time   = $response->time ?? null;

		if (!isset($response->result) && !$isDirectResults) {
			return;
		}

		if ($isDirectResults) {
			$this->status = 'OK';
		}

		if (!$modelClassFqn) {
			$this->result = $response->result;

			return;
		}

		$serializer = Client::getSerializer();
		$result     = array_map(fn($item) => $serializer->deserialize($item, $modelClassFqn), wrap($isDirectResults ? $response : $response->result));

		$this->result = $result;
	}

	/**
	 * @return array|string|object|null
	 */
	public function getResult(): mixed
	{
		return $this->result;
	}

	/**
	 * @return string|null
	 */
	public function getStatus(): ?string
	{
		return $this->status;
	}

	/**
	 * @return string|null
	 */
	public function getTime(): ?string
	{
		return $this->time;
	}

	/**
	 * @return T|null
	 */
	public function first(): object|array|string|null
	{
		return $this->result[0] ?? null;
	}

	/**
	 * @return T[]
	 */
	public function all(): array
	{
		return $this->result ?? [];
	}

	public function __toString(): string
	{
		return json_encode([
			'result' => $this->result,
			'status' => $this->status,
			'time'   => $this->time,
		]);
	}


}


