<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\DocumentKeywordsInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Schema\Schemas;
use SurrealDB\AgentMemory\Transport;

/**
 * Implements {@see DocumentKeywordsInterface} over the Agent Memory HTTP API.
 *
 * @phpstan-import-type DocumentKeywordJson from Schemas
 * @phpstan-import-type KeywordDetailJson from Schemas
 * @phpstan-import-type KeywordJson from Schemas
 * @phpstan-import-type KeywordSearchResponseJson from Schemas
 */
final class DocumentKeywords implements DocumentKeywordsInterface
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    public function list(?KeywordListOptions $options = null): Page
    {
        /** @var Page<KeywordJson> */
        return $this->transport->requestPage($this->base(), 'keywords', $options?->toQuery() ?? []);
    }

    public function listAll(?KeywordListOptions $options = null): array
    {
        Guard::collectable('DocumentKeywords::listAll()', 'DocumentKeywords::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function search(KeywordSearchOptions $options): array
    {
        /** @var KeywordSearchResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/search', body: $options->toPayload(), idempotent: true) ?? [];
    }

    public function get(string $normalised): array
    {
        /** @var KeywordDetailJson */
        return $this->transport->requestJson('GET', $this->base() . '/' . Paths::encodeSegment($normalised)) ?? [];
    }

    public function forDocument(string $documentId): array
    {
        $path = Paths::contextApiPrefix($this->contextId) . '/documents/'
            . Paths::encodeSegment($documentId) . '/keywords';

        /** @var list<DocumentKeywordJson> */
        return $this->transport->requestMember('GET', $path, 'keywords');
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/documents/keywords';
    }
}
