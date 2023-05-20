<?php

namespace Surreal\Responses;

use Surreal\Model\SurrealModel;

/**
 * @template T of SurrealModel
 */
class ApiQueryResponse
{
	protected array|object $response;

	/**
	 * @var QueryResponse<T>[]
	 */
	protected ?array            $responses = null;
	protected ?ApiErrorResponse $error     = null;

	/**
	 * @var class-string|null
	 */
	protected ?string $modelClassFqn = null;

	/**
	 * @param array|object      $response
	 * @param class-string|null $modelClassFqn
	 */
	public function __construct(array|object $response, ?string $modelClassFqn = null)
	{
		$this->response      = $response;
		$this->modelClassFqn = $modelClassFqn;

		if (!is_array($response) && $response?->code !== 200) {
			$this->error = new ApiErrorResponse($response);

			return;
		}

		$this->responses = array_map(fn($response) => new QueryResponse($response, $this->modelClassFqn), $response);
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
		return json_encode($this->response);
	}

	public function hasError()
	{
		return $this->error !== null;
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


}
