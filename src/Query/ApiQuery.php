<?php

namespace SurrealDB\SDK\Query;

use SurrealDB\SDK\Api\ApiResponse;
use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Exceptions\SurrealException;
use SurrealDB\SDK\Exceptions\UnsuccessfulApiException;
use SurrealDB\SDK\Types\None;
use function is_array;

/**
 * Invokes a user-defined API endpoint (`DEFINE API`) through SurrealQL's
 * `api::invoke`, so it works over every transport. Build one through
 * {@see \SurrealDB\SDK\Api\SurrealApi}.
 *
 * `execute()` returns the full {@see ApiResponse}; after {@see self::value()}
 * it returns only the body and throws {@see UnsuccessfulApiException} on a
 * non-2xx status.
 *
 * A {@see None} body (the default) is left out of the request; `null` is sent
 * as an explicit SurrealQL `NULL`.
 *
 * @template TBody
 * @extends QueryBuilder<ApiResponse<TBody>|TBody>
 */
final class ApiQuery extends QueryBuilder
{
    public private(set) bool $value = false;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    public function __construct(
        ?QueryExecutor $executor,
        public private(set) string $path,
        public private(set) string $method = 'get',
        public private(set) mixed $body = new None(),
        public private(set) array $headers = [],
        public private(set) array $query = [],
    ) {
        parent::__construct($executor);
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function query(string $name, string $value): static
    {
        $this->query[$name] = $value;

        return $this;
    }

    /**
     * Return only the response body, throwing on a non-2xx status.
     */
    public function value(bool $value = true): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return ApiResponse<TBody>|TBody
     */
    public function execute(): mixed
    {
        $result = $this->dispatch()[0] ?? null;

        if (!is_array($result) || !isset($result['status'])) {
            throw new SurrealException('The API response is missing its status');
        }

        /** @var ApiResponse<TBody> $response */
        $response = ApiResponse::fromResult($result);

        if (!$this->value) {
            return $response;
        }

        if (!$response->isSuccessful()) {
            throw new UnsuccessfulApiException($this->path, $this->method, $response);
        }

        return $response->body;
    }

    protected function build(): BoundQuery
    {
        // Empty maps are left out: an empty PHP array would encode as a list.
        $request = ['method' => $this->method];

        if ($this->headers !== []) {
            $request['headers'] = $this->headers;
        }

        if ($this->query !== []) {
            $request['query'] = $this->query;
        }

        if (!$this->body instanceof None) {
            $request['body'] = $this->body;
        }

        $query = new BoundQuery('api::invoke(');
        $query->append($query->bind($this->path) . ', ' . $query->bind($request) . ')');

        return $query;
    }
}
