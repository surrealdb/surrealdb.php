<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\KeywordListOptions;
use SurrealDB\Spectron\Options\KeywordSearchOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** Keyword graph helpers for the document corpus. */
final class DocumentKeywords
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /**
     * Lists keywords with optional filters and pagination.
     *
     * @return array<int|string,mixed>
     */
    public function list(?KeywordListOptions $options = null): array
    {
        return $this->transport->requestJson('GET', $this->base(), $options?->toQuery() ?? []) ?? [];
    }

    /**
     * Vector search over keyword embeddings.
     *
     * @return array<int|string,mixed>
     */
    public function search(KeywordSearchOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/search', body: $options->toPayload()) ?? [];
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

        return ($this->transport->requestJson('GET', $path) ?? [])['keywords'] ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/documents/keywords';
    }
}
