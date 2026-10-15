<?php

namespace SurrealDB\SDK\Api;

use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Query\ApiQuery;

/**
 * Calls user-defined API endpoints (`DEFINE API`) under an optional path
 * prefix, with default headers sent on every request. Obtain one from
 * {@see \SurrealDB\SDK\Surreal::api()}.
 */
final class SurrealApi
{
    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $prefix = '',
    ) {}

    /**
     * Set a default header for every request, or remove it with null.
     */
    public function header(string $name, ?string $value): static
    {
        if ($value === null) {
            unset($this->headers[$name]);
        } else {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     *
     * @return ApiQuery<mixed>
     */
    public function invoke(string $path, string $method = 'get', mixed $body = null, array $headers = [], array $query = []): ApiQuery
    {
        return new ApiQuery(
            $this->executor,
            self::normalizePath($this->prefix, $path),
            strtolower($method),
            $body,
            [...$this->headers, ...$headers],
            $query,
        );
    }

    /** @return ApiQuery<mixed> */
    public function get(string $path): ApiQuery
    {
        return $this->invoke($path, 'get');
    }

    /** @return ApiQuery<mixed> */
    public function post(string $path, mixed $body = null): ApiQuery
    {
        return $this->invoke($path, 'post', $body);
    }

    /** @return ApiQuery<mixed> */
    public function put(string $path, mixed $body = null): ApiQuery
    {
        return $this->invoke($path, 'put', $body);
    }

    /** @return ApiQuery<mixed> */
    public function patch(string $path, mixed $body = null): ApiQuery
    {
        return $this->invoke($path, 'patch', $body);
    }

    /** @return ApiQuery<mixed> */
    public function delete(string $path, mixed $body = null): ApiQuery
    {
        return $this->invoke($path, 'delete', $body);
    }

    /** @return ApiQuery<mixed> */
    public function trace(string $path, mixed $body = null): ApiQuery
    {
        return $this->invoke($path, 'trace', $body);
    }

    /**
     * Join the prefix and path into one absolute path, collapsing slashes.
     */
    public static function normalizePath(string $prefix, string $path): string
    {
        $segments = array_filter([...explode('/', $prefix), ...explode('/', $path)], static fn (string $part): bool => $part !== '');

        return '/' . implode('/', $segments);
    }
}
