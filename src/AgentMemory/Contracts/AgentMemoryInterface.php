<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\StreamException;
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
use SurrealDB\AgentMemory\Streaming\ChatChunk;

/**
 * The Agent Memory client for one context: memory writes, recall, and
 * lookup; chat; maintenance and audit; and a sub-client for each resource
 * family. {@see AgentMemory} implements it over HTTP. Type code against this
 * interface to substitute a test double or a decorator.
 */
interface AgentMemoryInterface
{
    /** Agent Memory context id this client calls. */
    public string $contextId { get; }

    /** Document ingestion, retrieval, corpus search, and the keyword graph. */
    public DocumentsInterface $documents { get; }

    /** Entity records, history, graph neighbours, search, and rankings. */
    public EntitiesInterface $entities { get; }

    /** The stored fact families: attributes, relations, and actions. */
    public FactsInterface $facts { get; }

    /** Open questions recorded about memory, and their settlement. */
    public UncertaintyInterface $uncertainty { get; }

    /** Conversation sessions for this context. */
    public SessionsInterface $sessions { get; }

    /** Expiry and decay sweeps. */
    public LifecycleInterface $lifecycle { get; }

    /** Retrieval trace tooling. */
    public TracesInterface $traces { get; }

    /** Principals and their scope grants. */
    public PrincipalsInterface $principals { get; }

    /** The scope tree. */
    public ScopesInterface $scopes { get; }

    /** Self-service API keys for this context. */
    public KeysInterface $keys { get; }

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
    public function onBehalfOf(string $principalId): AgentMemoryInterface;

    /**
     * Liveness probe for the API (`GET /api/v1/health`).
     *
     * @throws AgentMemoryException when the service is unhealthy or unreachable
     */
    public function health(): void;

    /**
     * Persists facts from free-form text and/or caller-supplied triples
     * (`POST /facts`). Idempotent within a 30-second window. Without an
     * existing `sessionId` a session is created and returned as `sessionId`.
     *
     * @return array<int|string,mixed>
     */
    public function remember(?string $text = null, ?RememberOptions $options = null): array;

    /**
     * Persists facts from a batch of conversation messages (`POST /facts/batch`).
     * Idempotent within a 30-second window.
     *
     * @param list<Message|array<string,mixed>> $messages conversation messages ({@see Message}, or `role` + `content` arrays)
     *
     * @return array<int|string,mixed>
     */
    public function rememberMany(array $messages, ?RememberManyOptions $options = null): array;

    /**
     * Semantic recall over memory for this context (`POST /query`).
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does; a
     * retry after a lost response can record one more retrieval trace.
     *
     * @return array<int|string,mixed>
     */
    public function recall(string $query, ?RecallOptions $options = null): array;

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
    public function lookup(string $query, ?LookupOptions $options = null): array;

    /**
     * Forgets memory matching a natural-language query (`POST /forget`). Pass
     * `dryRun` to preview the count first.
     *
     * @return array<int|string,mixed>
     */
    public function forget(string $query, ?ForgetOptions $options = null): array;

    /**
     * Full chat round trip (`POST /chat`): returns the reply, its citations,
     * and the memory updates. For incremental tokens use {@see chatStream()}.
     *
     * @return array<int|string,mixed>
     */
    public function chat(string $message, ?ChatOptions $options = null): array;

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
    public function chatStream(string $message, ?ChatOptions $options = null): \Generator;

    /**
     * Retrieves LLM-facing context text for a query without a session
     * (`POST /context`).
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does; a
     * retry after a lost response can record one more retrieval trace.
     *
     * @return array<int|string,mixed>
     */
    public function context(string $query, ?ContextOptions $options = null): array;

    /**
     * Runs a reflection pass; may persist attributes when the options say so
     * (`POST /reflect`).
     *
     * @return array<int|string,mixed>
     */
    public function reflect(string $query, ?ReflectOptions $options = null): array;

    /**
     * Consolidates accumulated observations into durable facts
     * (`POST /consolidate`).
     *
     * @return array<int|string,mixed>
     */
    public function consolidate(?ConsolidateOptions $options = null): array;

    /**
     * Infers and emits new relation edges between entities (`POST /elaborate`),
     * from one named entity or across a sweep of the low-relation ones.
     *
     * @return array<int|string,mixed>
     */
    public function elaborate(ElaborateOptions $options): array;

    /**
     * Runs an integrity check over the memory store (`POST /fsck`).
     *
     * @return array<int|string,mixed>
     */
    public function fsck(?FsckOptions $options = null): array;

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
    public function inspect(EntityRef|string $ref, ?InspectOptions $options = null): array;

    /** A page of audit rows (one per trace) for write and recall activity (`GET /audit`). */
    public function audit(?AuditOptions $options = null): Page;

    /**
     * Every audit row matching `$options`, walking the pages in order
     * (`GET /audit`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor`
     */
    public function auditAll(?AuditOptions $options = null): array;

    /**
     * Structured memory state snapshot (`GET /state`). Each table is bounded;
     * `truncated` flags the ones that were cut.
     *
     * @return array<int|string,mixed>
     */
    public function state(?StateOptions $options = null): array;

    /**
     * Static and dynamic profile slices (`GET /profile`).
     *
     * @return array<int|string,mixed>
     */
    public function profile(): array;

    /**
     * The calling principal's identity and resolved grants (`GET /me`).
     *
     * @return array<int|string,mixed>
     */
    public function whoami(): array;
}
