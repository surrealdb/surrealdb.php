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
use SurrealDB\AgentMemory\Contracts\AgentMemoryInterface;
use SurrealDB\AgentMemory\EntityRef;
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
use SurrealDB\AgentMemory\Schema\Schemas;
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
 * `/api/v1/{context}/…`. It follows the namespaces and method names of
 * `@surrealdb/memory`, with options as readonly classes built with named
 * arguments, so `memory.recall(query, { k: 5 })` becomes
 * `$memory->recall($query, new RecallOptions(k: 5))`. Collections come back as
 * a cursor-paginated {@see Page}; `listAll()` and the `all*()` collectors walk
 * every page.
 *
 * The methods are documented on {@see AgentMemoryInterface}, which code can
 * type against to substitute a test double or a decorator.
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
 *
 * @phpstan-import-type AuditRowJson from Schemas
 * @phpstan-import-type ChatResponseJson from Schemas
 * @phpstan-import-type ConsolidateResponseJson from Schemas
 * @phpstan-import-type ContextQueryResponseJson from Schemas
 * @phpstan-import-type ElaborateResponseJson from Schemas
 * @phpstan-import-type FactsBatchResponseJson from Schemas
 * @phpstan-import-type FactsResponseJson from Schemas
 * @phpstan-import-type ForgetResponseJson from Schemas
 * @phpstan-import-type FsckReportJson from Schemas
 * @phpstan-import-type InspectResponseJson from Schemas
 * @phpstan-import-type LookupResponseJson from Schemas
 * @phpstan-import-type ProfileResponseJson from Schemas
 * @phpstan-import-type QueryMemoryResponseJson from Schemas
 * @phpstan-import-type ReflectResponseJson from Schemas
 * @phpstan-import-type StateResponseJson from Schemas
 * @phpstan-import-type WhoamiJson from Schemas
 */
final class AgentMemory implements AgentMemoryInterface
{
    private readonly HttpConnectionInterface $connection;

    private readonly Transport $transport;

    public readonly string $contextId;

    public readonly Documents $documents;

    public readonly Entities $entities;

    public readonly Facts $facts;

    public readonly Uncertainty $uncertainty;

    public readonly Sessions $sessions;

    public readonly Lifecycle $lifecycle;

    public readonly Traces $traces;

    public readonly Principals $principals;

    public readonly Scopes $scopes;

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

    public function onBehalfOf(string $principalId): self
    {
        return new self($this->options, $this->connection, $principalId);
    }

    public function health(): void
    {
        $this->transport->requestJson('GET', '/api/v1/health');
    }

    public function remember(?string $text = null, ?RememberOptions $options = null): array
    {
        $payload = Payload::compact(['text' => $text]) + ($options?->toPayload() ?? []);

        /** @var FactsResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/facts', body: $payload, idempotent: true) ?? [];
    }

    public function rememberMany(array $messages, ?RememberManyOptions $options = null): array
    {
        $payload = [
            'messages' => array_map(
                static fn (Message|array $message): array => $message instanceof Message ? $message->toPayload() : $message,
                $messages,
            ),
        ] + ($options?->toPayload() ?? []);

        /** @var FactsBatchResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/facts/batch', body: $payload, idempotent: true) ?? [];
    }

    public function recall(string $query, ?RecallOptions $options = null): array
    {
        Guard::notBlank($query, 'recall() requires a non-empty query.');

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        /** @var QueryMemoryResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/query', body: $payload, idempotent: true) ?? [];
    }

    public function lookup(string $query, ?LookupOptions $options = null): array
    {
        if ($options?->subject === null) {
            Guard::notBlank($query, 'lookup() requires a non-empty query unless a subject is given.');
        }

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        /** @var LookupResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/lookup', body: $payload, idempotent: true) ?? [];
    }

    public function forget(string $query, ?ForgetOptions $options = null): array
    {
        Guard::notBlank($query, 'forget() requires a non-empty query.');

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        /** @var ForgetResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/forget', body: $payload) ?? [];
    }

    public function chat(string $message, ?ChatOptions $options = null): array
    {
        Guard::notBlank($message, 'chat() requires a non-empty message.');

        $payload = ['message' => $message] + ($options?->toPayload() ?? []);

        /** @var ChatResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/chat', body: $payload) ?? [];
    }

    public function chatStream(string $message, ?ChatOptions $options = null): \Generator
    {
        Guard::notBlank($message, 'chatStream() requires a non-empty message.');

        return $this->streamChat(['message' => $message] + ($options?->toPayload() ?? []) + ['stream' => true]);
    }

    public function context(string $query, ?ContextOptions $options = null): array
    {
        Guard::notBlank($query, 'context() requires a non-empty query.');

        $payload = ['query' => $query] + ($options?->toPayload() ?? []);

        /** @var ContextQueryResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/context', body: $payload, idempotent: true) ?? [];
    }

    public function reflect(string $query, ?ReflectOptions $options = null): array
    {
        Guard::notBlank($query, 'reflect() requires a non-empty query.');

        $payload = ['query' => $query] + ($options ?? new ReflectOptions())->toPayload();

        /** @var ReflectResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/reflect', body: $payload) ?? [];
    }

    public function consolidate(?ConsolidateOptions $options = null): array
    {
        /** @var ConsolidateResponseJson */
        return $this->transport->requestJson(
            'POST',
            $this->base() . '/consolidate',
            body: $options?->toPayload() ?? [],
        ) ?? [];
    }

    public function elaborate(ElaborateOptions $options): array
    {
        /** @var ElaborateResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/elaborate', body: $options->toPayload()) ?? [];
    }

    public function fsck(?FsckOptions $options = null): array
    {
        /** @var FsckReportJson */
        return $this->transport->requestJson(
            'POST',
            $this->base() . '/fsck',
            body: $options?->toPayload() ?? [],
        ) ?? [];
    }

    public function inspect(EntityRef|string $ref, ?InspectOptions $options = null): array
    {
        $query = ['ref' => $ref instanceof EntityRef ? $ref->tagged() : $ref] + ($options?->toQuery() ?? []);

        /** @var InspectResponseJson */
        return $this->transport->requestJson('GET', $this->base() . '/inspect', $query) ?? [];
    }

    public function audit(?AuditOptions $options = null): Page
    {
        /** @var Page<AuditRowJson> */
        return $this->transport->requestPage($this->base() . '/audit', 'rows', $options?->toQuery() ?? []);
    }

    public function auditAll(?AuditOptions $options = null): array
    {
        Guard::collectable('AgentMemory::auditAll()', 'AgentMemory::audit()', $options?->cursor);

        return $this->audit($options)->collect();
    }

    public function state(?StateOptions $options = null): array
    {
        /** @var StateResponseJson */
        return $this->transport->requestJson('GET', $this->base() . '/state', $options?->toQuery() ?? []) ?? [];
    }

    public function profile(): array
    {
        /** @var ProfileResponseJson */
        return $this->transport->requestJson('GET', $this->base() . '/profile') ?? [];
    }

    public function whoami(): array
    {
        /** @var WhoamiJson */
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
