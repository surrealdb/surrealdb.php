<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Keyword graph helpers for the document corpus. */
final class DocumentKeywords
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** A page of keywords, optionally filtered and sorted. */
    public function list(?KeywordListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'keywords', $options?->toQuery() ?? []);
    }

    /**
     * Every keyword matching `$options`, walking the pages in order
     * (`GET /documents/keywords`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?KeywordListOptions $options = null): array
    {
        Guard::collectable('DocumentKeywords::listAll()', 'DocumentKeywords::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    /**
     * Vector search over keyword embeddings.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return array<int|string,mixed>
     */
    public function search(KeywordSearchOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/search', body: $options->toPayload(), idempotent: true) ?? [];
    }

    /**
     * Gets one keyword by its normalised form.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $normalised): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/' . Paths::encodeSegment($normalised)) ?? [];
    }

    /**
     * Keywords linked to a document.
     *
     * @return list<array<string,mixed>>
     */
    public function forDocument(string $documentId): array
    {
        $path = Paths::contextApiPrefix($this->contextId) . '/documents/'
            . Paths::encodeSegment($documentId) . '/keywords';

        /** @var list<array<string,mixed>> */
        return $this->transport->requestMember('GET', $path, 'keywords');
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/documents/keywords';
    }
}
