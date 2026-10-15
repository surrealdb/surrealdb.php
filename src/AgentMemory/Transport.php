<?php

namespace SurrealDB\AgentMemory;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryExceptionFactory;
use SurrealDB\AgentMemory\Exceptions\ConnectionException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Exceptions\HttpConnectionException;
use function is_array;
use function is_bool;
use function strlen;

/**
 * Performs authenticated Agent Memory HTTP requests over the SDK-wide
 * {@see HttpConnectionInterface}: JSON encode/decode, query strings, retries
 * with back-off, idempotency keys, delegation headers, cursor pages, multipart
 * uploads, and server-sent-event streams. Failed statuses are mapped to the
 * typed {@see AgentMemoryException} hierarchy.
 */
final class Transport
{
    /**
     * Delegation header. The wire name predates the Agent Memory rebrand and is
     * what the server still reads.
     */
    private const string ON_BEHALF_OF_HEADER = 'X-Spectron-On-Behalf-Of';

    /** The optional kind tag the server strips from a delegation target. */
    private const string PRINCIPAL_PREFIX = 'principal:';

    /**
     * Header values are checked before any request: PSR-7 would reject a
     * control character with an exception of its own, and a blank delegation
     * target would make the server run requests as the caller.
     *
     * @param string      $endpoint   API endpoint origin without trailing slash
     * @param string      $apiKey     context API key (`sp-…`), sent as `Authorization: Bearer <key>`
     * @param string|null $onBehalfOf principal id sent as the `X-Spectron-On-Behalf-Of` header
     *
     * @throws InvalidArgumentException when the API key is blank, `$onBehalfOf` is blank alone or after a
     *                                  `principal:` prefix, or either holds a control character
     */
    public function __construct(
        private readonly HttpConnectionInterface $connection,
        private readonly string $endpoint,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly RetryPolicy $retry,
        private readonly ?string $onBehalfOf = null,
    ) {
        Guard::notBlank($this->apiKey, 'Agent Memory API key is required.');
        self::assertNoControlCharacters($this->apiKey, 'The Agent Memory API key cannot contain control characters such as line breaks.');

        if ($this->onBehalfOf !== null) {
            $principal = trim($this->onBehalfOf);
            Guard::notBlank(
                str_starts_with($principal, self::PRINCIPAL_PREFIX) ? substr($principal, strlen(self::PRINCIPAL_PREFIX)) : $principal,
                'onBehalfOf requires a principal id.',
            );
            self::assertNoControlCharacters($this->onBehalfOf, 'onBehalfOf cannot contain control characters such as line breaks.');
        }
    }

    /**
     * A copy of this transport that issues every request on behalf of
     * `$principalId` (adds the `X-Spectron-On-Behalf-Of` header).
     *
     * @throws InvalidArgumentException when the id is blank or holds a control character
     */
    public function withOnBehalfOf(string $principalId): self
    {
        return new self($this->connection, $this->endpoint, $this->apiKey, $this->retry, $principalId);
    }

    /**
     * Sends a JSON request and decodes the JSON response.
     *
     * @param array<string,mixed>|null $query      query parameters; `null` values are skipped
     * @param array<mixed>|null        $body       JSON payload; an empty array encodes as `{}`
     * @param bool                     $idempotent marks the request safe to retry like a `GET` (adds an `Idempotency-Key`)
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
     * The transport as `var_dump()` and `print_r()` show it, with the API key redacted.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return array_replace(get_object_vars($this), ['apiKey' => '[redacted]']);
    }

    /**
     * @param array<string,string> $headers carry the API key, so they are kept out of stack traces
     */
    private function send(string $method, string $url, ?string $body, #[\SensitiveParameter] array $headers, bool $idempotent): ResponseInterface
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
            'User-Agent' => UserAgent::value(),
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        if ($this->onBehalfOf !== null) {
            $headers[self::ON_BEHALF_OF_HEADER] = $this->onBehalfOf;
        }

        return $headers;
    }

    /**
     * @throws InvalidArgumentException with `$message` when `$value` holds a C0 control character or DEL
     */
    private static function assertNoControlCharacters(string $value, string $message): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($message);
        }
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
