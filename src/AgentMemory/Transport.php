<?php

namespace SurrealDB\AgentMemory;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use SurrealDB\AgentMemory\Auth\AuthenticationInterface;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryExceptionFactory;
use SurrealDB\AgentMemory\Exceptions\ConnectionException;
use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Exceptions\HttpConnectionException;
use function is_array;
use function is_bool;

/**
 * Performs authenticated Agent Memory HTTP requests over the SDK-wide
 * {@see HttpConnectionInterface}: JSON encode/decode, query strings, retries
 * with back-off, idempotency keys, delegation headers, cursor pages, multipart
 * uploads, and server-sent-event streams. Failed statuses are mapped to the
 * typed {@see AgentMemoryException} hierarchy.
 */
final class Transport
{
    private const string USER_AGENT = 'surrealdb-agent-memory-php';

    /**
     * Delegation header. The wire name predates the Agent Memory rebrand and is
     * what the server still reads.
     */
    private const string ON_BEHALF_OF_HEADER = 'X-Spectron-On-Behalf-Of';

    /**
     * @param string $endpoint API endpoint origin without trailing slash
     */
    public function __construct(
        private readonly HttpConnectionInterface $connection,
        private readonly string $endpoint,
        private readonly AuthenticationInterface $auth,
        private readonly RetryPolicy $retry,
        private readonly ?string $onBehalfOf = null,
    ) {}

    /**
     * A copy of this transport that issues every request on behalf of
     * `$principalId` (adds the `X-Spectron-On-Behalf-Of` header).
     */
    public function withOnBehalfOf(string $principalId): self
    {
        return new self($this->connection, $this->endpoint, $this->auth, $this->retry, $principalId);
    }

    /**
     * Sends a JSON request and decodes the JSON response.
     *
     * @param array<string,mixed>|null $query      query parameters; `null` values are skipped
     * @param array<mixed>|null        $body       JSON payload; an empty array encodes as `{}`
     * @param bool                     $idempotent marks a write safe to retry (adds an `Idempotency-Key`)
     *
     * @return array<int|string,mixed>|null decoded body, or `null` for empty/204 responses
     */
    public function requestJson(
        string $method,
        string $path,
        ?array $query = null,
        ?array $body = null,
        bool $idempotent = false,
    ): ?array {
        $encoded = $body === null ? null : $this->encode($body);
        $headers = $this->headers('application/json');

        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($idempotent) {
            // Computed once, outside the retry loop, so every retry within the
            // 30s window collapses to a single server-side effect.
            $headers['Idempotency-Key'] = IdempotencyKey::compute($method, $path, $encoded ?? '');
        }

        return $this->decode($this->send($method, $this->url($path, $query), $encoded, $headers, $idempotent));
    }

    /**
     * Sends a JSON request and returns one member of the decoded response
     * object, such as the `matches` of an entity search.
     *
     * @param array<string,mixed>|null $query
     * @param array<mixed>|null        $body
     *
     * @return array<int|string,mixed> the member, or `[]` when the response has none
     */
    public function requestMember(string $method, string $path, string $member, ?array $query = null, ?array $body = null): array
    {
        $value = ($this->requestJson($method, $path, $query, $body) ?? [])[$member] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Fetches one page of a cursor-paginated collection. The returned
     * {@see Page} re-issues this same request with each `nextCursor`, so
     * {@see Page::next()} and {@see Page::autoPaginate()} keep every filter
     * the first page was asked with. `count` is the exception: the total is
     * only asked for on the first page, because every count costs the server
     * a full count of the filtered set.
     *
     * @param string              $itemsKey the envelope member holding the rows (e.g. `entities`)
     * @param array<string,mixed> $query    filters and page parameters; `null` values are skipped
     */
    public function requestPage(string $path, string $itemsKey, array $query = []): Page
    {
        $followUp = array_diff_key($query, ['cursor' => true, 'count' => true]);

        return Page::fromEnvelope(
            $this->requestJson('GET', $path, $query) ?? [],
            $itemsKey,
            fn (string $cursor): Page => $this->requestPage($path, $itemsKey, ['cursor' => $cursor] + $followUp),
        );
    }

    /**
     * Sends a request that returns raw bytes (e.g. document `raw`).
     *
     * @param array<string,mixed>|null $query
     */
    public function requestBytes(string $method, string $path, ?array $query = null): string
    {
        $response = $this->send($method, $this->url($path, $query), null, $this->headers('*/*'), false);

        return (string) $response->getBody();
    }

    /**
     * Sends a multipart request (e.g. document upload) and decodes the JSON
     * response. Multipart writes are never retried.
     *
     * @return array<int|string,mixed>|null
     */
    public function requestMultipart(string $method, string $path, MultipartFormData $form): ?array
    {
        $headers = $this->headers('application/json');
        $headers['Content-Type'] = $form->contentType();

        return $this->decode($this->send($method, $this->url($path, null), $form->body(), $headers, false));
    }

    /**
     * Opens a server-sent-event exchange (e.g. streaming `chat`). Streams are
     * not retried; the returned response carries the raw SSE body for the
     * caller to parse.
     *
     * @param array<mixed> $body
     */
    public function requestStream(string $method, string $path, array $body): ResponseInterface
    {
        $headers = $this->headers('text/event-stream');
        $headers['Content-Type'] = 'application/json';

        return $this->send($method, $this->url($path, null), $this->encode($body), $headers, false);
    }

    /**
     * @param array<string,string> $headers
     */
    private function send(string $method, string $url, ?string $body, array $headers, bool $idempotent): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $retryAfter = null;

            try {
                return $this->connection->request($url, $method, $body, $headers);
            } catch (HttpConnectionException $e) {
                if (!$this->retry->shouldRetry($method, $e->status, $attempt, $idempotent, $e->body)) {
                    throw AgentMemoryExceptionFactory::fromResponse($e->status, $e->body, $e->headers);
                }

                $retryAfter = RetryAfter::seconds($e->headers);
            } catch (ClientExceptionInterface $e) {
                if (!$this->retry->shouldRetry($method, null, $attempt, $idempotent)) {
                    throw new ConnectionException('Connection failed', 0, $e->getMessage(), previous: $e);
                }
            }

            $this->retry->backOff($attempt, $retryAfter);
            ++$attempt;
        }
    }

    /**
     * @param array<string,mixed>|null $query
     */
    private function url(string $path, ?array $query): string
    {
        $url = $this->endpoint . $path;
        $params = [];

        foreach ($query ?? [] as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $params[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $params === [] ? $url : $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string,string>
     */
    private function headers(string $accept): array
    {
        $headers = [
            'Accept' => $accept,
            'User-Agent' => self::USER_AGENT,
            ...$this->auth->headers(),
        ];

        if ($this->onBehalfOf !== null) {
            $headers[self::ON_BEHALF_OF_HEADER] = $this->onBehalfOf;
        }

        return $headers;
    }

    /**
     * @param array<mixed> $body
     */
    private function encode(array $body): string
    {
        try {
            return json_encode(
                $body === [] ? new \stdClass() : $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new AgentMemoryException('Failed to encode Agent Memory request payload', 0, $e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<int|string,mixed>|null
     */
    private function decode(ResponseInterface $response): ?array
    {
        $raw = (string) $response->getBody();

        if ($raw === '' || $response->getStatusCode() === 204) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AgentMemoryException(
                'Failed to decode Agent Memory response',
                $response->getStatusCode(),
                $e->getMessage(),
                previous: $e,
            );
        }

        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new AgentMemoryException('Agent Memory returned a non-object response', $response->getStatusCode());
        }

        return $data;
    }
}
