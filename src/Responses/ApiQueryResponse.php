<?php

namespace Surreal\Responses;

use Illuminate\Support\Collection;
use Surreal\Model\SurrealModel;

/**
 * @template T of SurrealModel
 */
class ApiQueryResponse
{
	protected Collection|ApiErrorResponse $rawResults;

	/**
	 * @var QueryResponse<T>[]
	 */
	protected ?array            $responses = null;
	protected ?ApiErrorResponse $error     = null;

	/**
	 * @var class-string|null
	 */
	protected ?string $modelClassFqn = null;

	public function __construct(Collection|ApiErrorResponse $results, ?string $modelClassFqn = null, bool $isDirectResults = false)
	{
		$this->rawResults    = $results;
		$this->modelClassFqn = $modelClassFqn;

		if ($results instanceof ApiErrorResponse) {
			$this->error = $results;

			return;
		}

		$this->responses = $results->map(fn($response) => new QueryResponse($response, $this->modelClassFqn, $isDirectResults))->toArray();
	}

	/**
	 * @return QueryResponse<T>[]
	 */
	public function getResponses(): array
	{
		return $this->responses;
	}

	public function hasResponses(): bool
	{
		return $this->responses !== null && count($this->responses) > 0;
	}

	/**
	 * @return QueryResponse<T>|null
	 */
	public function firstResult(): ?QueryResponse
	{
		return $this->responses[0] ?? null;
	}

	/**
	 * @return T|null
	 */
	public function first()
	{
		return $this->firstResult()?->first();
	}

	/**
	 * @return T[]
	 */
	public function all(): array
	{
		return $this->firstResult()?->all() ?? [];
	}

	public function __toString(): string
	{
		return json_encode($this->rawResults);
	}

	public function count(): int
	{
		return count($this->responses);
	}

	public function hasError(): bool
	{
		return $this->error !== null;
	}

	public function getError(): ?ApiErrorResponse
	{
		return $this->error;
	}

	public function getErrorDetails(): ?string
	{
		return $this->error?->details;
	}

	public function getErrorCode(): ?int
	{
		return $this->error?->code;
	}

	public function getErrorDescription(): ?string
	{
		return $this->error?->description;
	}

	public function getErrorInformation(): ?string
	{
		return $this->error?->information;
	}

	public function throw()
	{
		if (!$this->error) {
			return;
		}

		throw new \Exception($this->error->description, $this->error->code);
	}

	/**
	 * @return T[]
	 */
	public function getAllItems() : array
	{
		$items = [];

		foreach ($this->responses as $response) {
			$items = array_merge($items, $response->all());
		}

		return $items;
	}


}
