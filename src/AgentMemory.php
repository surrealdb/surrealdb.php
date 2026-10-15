<?php

namespace SurrealDB;

use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\AgentMemoryRuntime;
use SurrealDB\AgentMemory\Components\Documents;
use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Components\Facts;
use SurrealDB\AgentMemory\Components\Keys;
use SurrealDB\AgentMemory\Components\Lifecycle;
use SurrealDB\AgentMemory\Components\Principals;
use SurrealDB\AgentMemory\Components\Scopes;
use SurrealDB\AgentMemory\Components\Sessions;
use SurrealDB\AgentMemory\Components\Traces;
use SurrealDB\AgentMemory\Components\Uncertainty;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\StreamException;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Message;
use SurrealDB\AgentMemory\Options\AuditOptions;
use SurrealDB\AgentMemory\Options\ChatOptions;
use SurrealDB\AgentMemory\Options\ConsolidateOptions;
use SurrealDB\AgentMemory\Options\ContextOptions;
use SurrealDB\AgentMemory\Options\ElaborateOptions;
use SurrealDB\AgentMemory\Options\ForgetOptions;
use SurrealDB\AgentMemory\Options\FsckOptions;
use SurrealDB\AgentMemory\Options\InspectOptions;
use SurrealDB\AgentMemory\Options\LookupOptions;
use SurrealDB\AgentMemory\Options\RecallOptions;
use SurrealDB\AgentMemory\Options\ReflectOptions;
use SurrealDB\AgentMemory\Options\RememberManyOptions;
use SurrealDB\AgentMemory\Options\RememberOptions;
use SurrealDB\AgentMemory\Options\StateOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\RetryPolicy;
use SurrealDB\AgentMemory\Streaming\ChatChunk;
use SurrealDB\AgentMemory\Streaming\ChatStreamParser;
use SurrealDB\AgentMemory\Transport;
use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Http\HttpConnection;
use SurrealDB\Http\PsrHttpClientProvider;

/**
 * Typed client for the public SurrealDB Agent Memory API: memory writes,
 * recall, and lookup; document ingestion; sessions, entities, and the stored
 * fact families; uncertainty, lifecycle, and traces; and scope, principal,
 * and key administration.
 *
 * The client is pinned to a single `context`; every call targets
 * `/api/v1/{context}/…`. Options are readonly classes constructed with named
 * arguments, so `memory.recall(query, { k: 5 })` in the JS SDK becomes
 * `$memory->recall($query, new RecallOptions(k: 5))`. Collections come back as
 * a cursor-paginated {@see Page}; the `listAll()` and `all*()` collectors
 * return every row.
 *
 * ```php
 * $memory = new AgentMemory(new AgentMemoryOptions(
 *     endpoint: 'https://memory.example.com',
 *     context: 'acme_prod',
 *     apiKey: $apiKey,
 * ));
 *
 * $memory->remember('I just got promoted to CTO');
 * $hits = $memory->recall('What is my role?');
 * ```
 */
final class AgentMemory
{
    private readonly HttpConnectionInterface $connection;

    private readonly Transport $transport;

    /** Agent Memory context id this client calls. */
    public readonly string $contextId;

    /** Document ingestion, retrieval, corpus search, and the keyword graph. */
    public readonly Documents $documents;

    /** Entity records, history, graph neighbours, search, and rankings. */
    public readonly Entities $entities;

    /** The stored fact families: attributes, relations, and actions. */
    public readonly Facts $facts;

    /** Open questions recorded about memory, and their settlement. */
    public readonly Uncertainty $uncertainty;

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
        private readonly AgentMemoryOptions $options,
        ?HttpConnectionInterface $connection = null,
        ?string $onBehalfOf = null,
    ) {
        $runtime = $options->runtime ?? AgentMemoryRuntime::sync();
        $this->connection = $connection ?? new HttpConnection(new PsrHttpClientProvider(
            $options->httpClient ?? $runtime->httpClient,
            $options->requestFactory,
            $options->streamFactory,
        ));
        $this->contextId = $options->context;
        $this->transport = new Transport(
            $this->connection,
            rtrim($options->endpoint, '/'),
            $options->apiKey,
            new RetryPolicy($options->maxRetries, $runtime->scheduler),
            $onBehalfOf,
        );
        $this->documents = new Documents($this->transport, $this->contextId);
        $this->entities = new Entities($this->transport, $this->contextId);
        $this->facts = new Facts($this->transport, $this->contextId);
        $this->uncertainty = new Uncertainty($this->transport, $this->contextId);
        $this->sessions = new Sessions($this->transport, $this->contextId);
        $this->lifecycle = new Lifecycle($this->transport, $this->contextId);
        $this->traces = new Traces($this->transport, $this->contextId);
        $this->principals = new Principals($this->transport, $this->contextId);
        $this->scopes = new Scopes($this->transport, $this->contextId);
        $this->keys = new Keys($this->transport, $this->contextId);
    }

    /**
     * Returns a client that issues every request on behalf of `$principalId`,
     * sending the `X-Spectron-On-Behalf-Of` delegation header. The target
     * principal must allow delegation, and requests run with the intersection
     * of the caller's and the target's grants. The original client is left
     * unchanged.
     *
     * @throws InvalidArgumentException when the id is blank, alone or after a `principal:` prefix (the
     *                                  server would run the request as the caller), or holds a control character
     */
    public function onBehalfOf(string $principalId): self
    {
        return new self($this->options, $this->connection, $principalId);
    }

    /**
     * Liveness probe for the API (`GET /api/v1/health`).
     *
     * @throws AgentMemoryException when the service is unhealthy or unreachable
     */
    public function health(): void
    {
        $this->transport->requestJson('GET', '/api/v1/health');
    }

    /**
     * Persists facts from free-form text and/or caller-supplied triples
     * (`POST /facts`). Idempotent within a 30-second window. Without an
     * existing `sessionId` a session is created and returned as `sessionId`.
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
     * @param list<Message|array<string,mixed>> $messages conversation messages ({@see Message}, or `role` + `content` arrays)
     *
     * @return array<int|string,mixed>
     */
    public function rememberMany(array $messages, ?RememberManyOptions $options = null): array
    {
        $payload = [
            'messages' => array_map(
                static fn (Message|array $message): array => $message instanceof Message ? $message->toPayload() : $message,
                $messages,
            ),
        ] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/facts/batch', body: $payload, idempotent: true) ?? [];
    }

    /**
     * Semantic recall over memory for this context (`POST /query`).
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does; a
     * retry after a lost response can record one more retrieval trace.
     *
     * @return array<int|string,mixed>
     */
    public function recall(string $query, ?RecallOptions $options = null): array
    {
        Guard::notBlank($query, 'recall() requires a non-empty query.');

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/query', body: $payload, idempotent: true) ?? [];
    }

    /**
     * What this context knows about a subject, in one deterministic round trip
     * (`POST /lookup`). Every row returned is stored, not generated, so the
     * same question gets the same answer. `resolution.kind` says what the query
     * resolved to: `entity`, `ambiguous` (see `candidates`), `topic`, or
     * `empty` (see `nearest`). Sections are bounded and flag `truncated`; walk
     * {@see $facts} (`allEdgesOf()` for every relation of the subject) or
     * `$uncertainty->listAll()` for the rest.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does; a
     * retry after a lost response can record one more retrieval trace.
     *
     * @return array<int|string,mixed>
     */
    public function lookup(string $query, ?LookupOptions $options = null): array
    {
        if ($options?->subject === null) {
            Guard::notBlank($query, 'lookup() requires a non-empty query unless a subject is given.');
        }

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/lookup', body: $payload, idempotent: true) ?? [];
    }

    /**
     * Forgets memory matching a natural-language query (`POST /forget`). Pass
     * `dryRun` to preview the count first.
     *
     * @return array<int|string,mixed>
     */
    public function forget(string $query, ?ForgetOptions $options = null): array
    {
        Guard::notBlank($query, 'forget() requires a non-empty query.');

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/forget', body: $payload) ?? [];
    }

    /**
     * Full chat round trip (`POST /chat`): returns the reply, its citations,
     * and the memory updates. For incremental tokens use {@see chatStream()}.
     *
     * @return array<int|string,mixed>
     */
    public function chat(string $message, ?ChatOptions $options = null): array
    {
        Guard::notBlank($message, 'chat() requires a non-empty message.');

        $payload = ['message' => $message] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/chat', body: $payload) ?? [];
    }

    /**
     * Streaming chat round trip (`POST /chat` with `stream: true`): yields
     * {@see ChatChunk}s as the server emits them. The first chunk carries the
     * trace and session ids, each following chunk a `delta` of reply text, and
     * the terminal chunk (`done`) the full `reply`, `citations` and
     * `memoryUpdates` in its `raw` payload. Tokens arrive incrementally when
     * the underlying PSR-18 client streams response bodies.
     *
     * The message and options are checked on the call itself; the request is
     * sent when the generator is first iterated.
     *
     * @return \Generator<int,ChatChunk>
     *
     * @throws InvalidArgumentException when the message is blank
     * @throws StreamException          when the server reports a failure mid-stream
     */
    public function chatStream(string $message, ?ChatOptions $options = null): \Generator
    {
        Guard::notBlank($message, 'chatStream() requires a non-empty message.');

        return $this->streamChat(['message' => $message] + ($options?->toPayload() ?? []) + ['stream' => true]);
    }

    /**
     * Retrieves LLM-facing context text for a query without a session
     * (`POST /context`).
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does; a
     * retry after a lost response can record one more retrieval trace.
     *
     * @return array<int|string,mixed>
     */
    public function context(string $query, ?ContextOptions $options = null): array
    {
        Guard::notBlank($query, 'context() requires a non-empty query.');

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        return $this->transport->requestJson('POST', $this->base() . '/context', body: $payload, idempotent: true) ?? [];
    }

    /**
     * Runs a reflection pass; may persist attributes when the options say so
     * (`POST /reflect`).
     *
     * @return array<int|string,mixed>
     */
    public function reflect(string $query, ?ReflectOptions $options = null): array
    {
        Guard::notBlank($query, 'reflect() requires a non-empty query.');

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
     * Infers and emits new relation edges between entities (`POST /elaborate`),
     * from one named entity or across a sweep of the low-relation ones.
     *
     * @return array<int|string,mixed>
     */
    public function elaborate(ElaborateOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/elaborate', body: $options->toPayload()) ?? [];
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
     * Inspects one object by kind-tagged reference (`GET /inspect`):
     * `entity:<type>/<name>`, `attribute:<type>/<name>/<key>`,
     * `relation:<type>/<name>-[<label>]-><type>/<name>`,
     * `passage:<documentId>/<index>`, or `trace:<id>`. The refs that rows in
     * other responses carry (e.g. an attribute's `entity`) can be passed as is,
     * and an {@see EntityRef} is sent as its `entity:` ref.
     *
     * @return array<int|string,mixed>
     */
    public function inspect(EntityRef|string $ref, ?InspectOptions $options = null): array
    {
        $query = ['ref' => $ref instanceof EntityRef ? $ref->tagged() : $ref] + ($options?->toQuery() ?? []);

        return $this->transport->requestJson('GET', $this->base() . '/inspect', $query) ?? [];
    }

    /** A page of audit rows (one per trace) for write and recall activity (`GET /audit`). */
    public function audit(?AuditOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base() . '/audit', 'rows', $options?->toQuery() ?? []);
    }

    /**
     * Every audit row matching `$options`, walking the pages in order
     * (`GET /audit`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor`
     */
    public function auditAll(?AuditOptions $options = null): array
    {
        Guard::collectable('AgentMemory::auditAll()', 'AgentMemory::audit()', $options?->cursor);

        return $this->audit($options)->collect();
    }

    /**
     * Structured memory state snapshot (`GET /state`). Each table is bounded;
     * `truncated` flags the ones that were cut.
     *
     * @return array<int|string,mixed>
     */
    public function state(?StateOptions $options = null): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/state', $options?->toQuery() ?? []) ?? [];
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

    /**
     * @param array<string,mixed> $payload
     *
     * @return \Generator<int,ChatChunk>
     */
    private function streamChat(array $payload): \Generator
    {
        $response = $this->transport->requestStream('POST', $this->base() . '/chat', $payload);

        yield from (new ChatStreamParser())->parse($response->getBody());
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId);
    }
}
