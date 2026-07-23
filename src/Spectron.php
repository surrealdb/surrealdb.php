<?php

namespace SurrealDB;

use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Http\HttpConnection;
use SurrealDB\Http\PsrHttpClientProvider;
use SurrealDB\Spectron\Components\Documents;
use SurrealDB\Spectron\Components\Entities;
use SurrealDB\Spectron\Components\Keys;
use SurrealDB\Spectron\Components\Lifecycle;
use SurrealDB\Spectron\Components\Principals;
use SurrealDB\Spectron\Components\Scopes;
use SurrealDB\Spectron\Components\Sessions;
use SurrealDB\Spectron\Components\Traces;
use SurrealDB\Spectron\Exceptions\SpectronException;
use SurrealDB\Spectron\Options\AuditOptions;
use SurrealDB\Spectron\Options\ChatOptions;
use SurrealDB\Spectron\Options\ConsolidateOptions;
use SurrealDB\Spectron\Options\ContextOptions;
use SurrealDB\Spectron\Options\ElaborateOptions;
use SurrealDB\Spectron\Options\ForgetOptions;
use SurrealDB\Spectron\Options\FsckOptions;
use SurrealDB\Spectron\Options\InspectOptions;
use SurrealDB\Spectron\Options\RecallOptions;
use SurrealDB\Spectron\Options\ReflectOptions;
use SurrealDB\Spectron\Options\RememberManyOptions;
use SurrealDB\Spectron\Options\RememberOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\RetryPolicy;
use SurrealDB\Spectron\SpectronOptions;
use SurrealDB\Spectron\SpectronRuntime;
use SurrealDB\Spectron\Streaming\ChatChunk;
use SurrealDB\Spectron\Streaming\ChatStreamParser;
use SurrealDB\Spectron\Transport;

/**
 * Typed client for the public Spectron API: memory writes and recall, document
 * ingestion, sessions, entities, lifecycle, traces, and scope administration.
 *
 * The client is pinned to a single `context`; every call targets
 * `/api/v1/{context}/…`. Its public surface mirrors the `@surrealdb/spectron`
 * JS SDK: the JS options objects map to readonly options classes of the same
 * name, constructed with named arguments, so `client.recall(query, { k: 5 })`
 * becomes `$client->recall($query, new RecallOptions(k: 5))`.
 */
final class Spectron
{
    private readonly HttpConnectionInterface $connection;

    private readonly Transport $transport;

    /** Spectron context id this client calls. */
    public readonly string $contextId;

    /** Document ingestion, retrieval, corpus search, and the keyword graph. */
    public readonly Documents $documents;

    /** Entity records, attributes, relations, and attribute history. */
    public readonly Entities $entities;

    /** Conversation sessions for this context. */
    public readonly Sessions $sessions;

    /** Expiry and decay sweeps. */
    public readonly Lifecycle $lifecycle;

    /** Retrieval trace tooling. */
    public readonly Traces $traces;

    /** Principals and their scope grants. */
    public readonly Principals $principals;

    /** The scope tree. */
    public readonly Scopes $scopes;

    /** Self-service API keys for this context. */
    public readonly Keys $keys;

    /**
     * @param string|null $onBehalfOf principal id every request is issued on behalf of
     *                                (the `X-Spectron-On-Behalf-Of` delegation header);
     *                                usually set via {@see onBehalfOf()} instead
     */
    public function __construct(
        private readonly SpectronOptions $options,
        ?HttpConnectionInterface $connection = null,
        ?string $onBehalfOf = null,
    ) {
        $runtime = $options->runtime ?? SpectronRuntime::sync();
        $this->connection = $connection ?? new HttpConnection(new PsrHttpClientProvider(
            $options->httpClient ?? $runtime->httpClient,
            $options->requestFactory,
            $options->streamFactory,
        ));
        $this->contextId = $options->context;
        $this->transport = new Transport(
            $this->connection,
            rtrim($options->endpoint, '/'),
            $options->authentication(),
            new RetryPolicy($options->maxRetries, $runtime->scheduler),
            $onBehalfOf,
        );
        $this->documents = new Documents($this->transport, $this->contextId);
        $this->entities = new Entities($this->transport, $this->contextId);
        $this->sessions = new Sessions($this->transport, $this->contextId);
        $this->lifecycle = new Lifecycle($this->transport, $this->contextId);
        $this->traces = new Traces($this->transport, $this->contextId);
        $this->principals = new Principals($this->transport, $this->contextId);
        $this->scopes = new Scopes($this->transport, $this->contextId);
        $this->keys = new Keys($this->transport, $this->contextId);
    }

    /**
     * Returns a client that issues every request on behalf of `$principalId`,
     * sending the `X-Spectron-On-Behalf-Of` delegation header. Requires the
     * `manage` grant. The original client is left unchanged.
     */
    public function onBehalfOf(string $principalId): self
    {
        if ($principalId === '') {
            throw new \InvalidArgumentException('onBehalfOf requires a principal id.');
        }

        return new self($this->options, $this->connection, $principalId);
    }

    /**
     * Liveness probe for the API (`GET /api/v1/health`).
     *
     * @throws SpectronException when the service is unhealthy or unreachable
     */
    public function health(): void
    {
        $this->transport->requestJson('GET', '/api/v1/health');
    }

    /**
     * Persists facts from free-form text and/or caller-supplied triples
     * (`POST /facts`). Idempotent within a 30-second window.
     *
     * @return array<int|string,mixed>
     */
    public function remember(?string $text = null, ?RememberOptions $options = null): array
    {
        $payload = Payload::compact(['text' => $text]) + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/facts', body: $payload, idempotent: true) ?? [];
    }

    /**
     * Persists facts from a batch of conversation messages (`POST /facts/batch`).
     * Idempotent within a 30-second window.
     *
     * @param list<array<string,mixed>> $messages conversation messages (`role` + `content`)
     *
     * @return array<int|string,mixed>
     */
    public function rememberMany(array $messages, ?RememberManyOptions $options = null): array
    {
        $payload = ['messages' => $messages] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/facts/batch', body: $payload, idempotent: true) ?? [];
    }

    /**
     * Semantic recall over memory for this context (`POST /query`).
     *
     * @return array<int|string,mixed>
     */
    public function recall(string $query, ?RecallOptions $options = null): array
    {
        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/query', body: $payload) ?? [];
    }

    /**
     * Forgets memory matching a natural-language query (`POST /forget`).
     *
     * @return array<int|string,mixed>
     */
    public function forget(string $query, ?ForgetOptions $options = null): array
    {
        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/forget', body: $payload) ?? [];
    }

    /**
     * Full chat round trip (`POST /chat`): returns the reply plus memory
     * updates. For incremental tokens use {@see chatStream()}.
     *
     * @return array<int|string,mixed>
     */
    public function chat(string $message, ?ChatOptions $options = null): array
    {
        $payload = ['message' => $message] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/chat', body: $payload) ?? [];
    }

    /**
     * Streaming chat round trip (`POST /chat` with `stream: true`): yields
     * {@see ChatChunk}s as the server emits them. The PHP equivalent of the JS
     * client's `chat(message, { stream: true })`; tokens arrive incrementally
     * when the underlying PSR-18 client streams response bodies.
     *
     * @return \Generator<int,ChatChunk>
     */
    public function chatStream(string $message, ?ChatOptions $options = null): \Generator
    {
        $payload = ['message' => $message] + ($options?->toPayload() ?? []) + ['stream' => true];

        $response = $this->transport->requestStream('POST', $this->base() . '/chat', $payload);

        yield from (new ChatStreamParser())->parse($response->getBody());
    }

    /**
     * Retrieves LLM-facing context text for a query without a session
     * (`POST /context`).
     *
     * @return array<int|string,mixed>
     */
    public function context(string $query, ?ContextOptions $options = null): array
    {
        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/context', body: $payload) ?? [];
    }

    /**
     * Runs a reflection pass; may persist attributes when the options say so
     * (`POST /reflect`).
     *
     * @return array<int|string,mixed>
     */
    public function reflect(string $query, ?ReflectOptions $options = null): array
    {
        $payload = ['query' => $query] + ($options ?? new ReflectOptions())->toPayload();

        return $this->transport->requestJson('POST', $this->base() . '/reflect', body: $payload) ?? [];
    }

    /**
     * Consolidates accumulated observations into durable facts
     * (`POST /consolidate`).
     *
     * @return array<int|string,mixed>
     */
    public function consolidate(?ConsolidateOptions $options = null): array
    {
        return $this->transport->requestJson(
            'POST',
            $this->base() . '/consolidate',
            body: $options?->toPayload() ?? [],
        ) ?? [];
    }

    /**
     * Infers and emits new relation edges between entities (`POST /elaborate`).
     *
     * @return array<int|string,mixed>
     */
    public function elaborate(?ElaborateOptions $options = null): array
    {
        return $this->transport->requestJson(
            'POST',
            $this->base() . '/elaborate',
            body: $options?->toPayload() ?? [],
        ) ?? [];
    }

    /**
     * Runs an integrity check over the memory store (`POST /fsck`).
     *
     * @return array<int|string,mixed>
     */
    public function fsck(?FsckOptions $options = null): array
    {
        return $this->transport->requestJson(
            'POST',
            $this->base() . '/fsck',
            body: $options?->toPayload() ?? [],
        ) ?? [];
    }

    /**
     * Inspects an entity, attribute, or trace by reference (`GET /inspect`).
     *
     * @return array<int|string,mixed>
     */
    public function inspect(string $ref, ?InspectOptions $options = null): array
    {
        $query = ['ref' => $ref] + ($options?->toQuery() ?? []);

        return $this->transport->requestJson('GET', $this->base() . '/inspect', $query) ?? [];
    }

    /**
     * Lists audit rows for write/recall activity (`GET /audit`).
     *
     * @return array<int|string,mixed>
     */
    public function audit(?AuditOptions $options = null): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/audit', $options?->toQuery() ?? []) ?? [];
    }

    /**
     * Structured memory state snapshot (`GET /state`).
     *
     * @return array<int|string,mixed>
     */
    public function state(): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/state') ?? [];
    }

    /**
     * Static and dynamic profile slices (`GET /profile`).
     *
     * @return array<int|string,mixed>
     */
    public function profile(): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/profile') ?? [];
    }

    /**
     * The calling principal's identity and resolved grants (`GET /me`).
     *
     * @return array<int|string,mixed>
     */
    public function whoami(): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/me') ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId);
    }
}
