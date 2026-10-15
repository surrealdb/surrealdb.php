<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * Keyword graph helpers for the document corpus.
 *
 * @phpstan-import-type DocumentKeywordJson from Schemas
 * @phpstan-import-type KeywordDetailJson from Schemas
 * @phpstan-import-type KeywordJson from Schemas
 * @phpstan-import-type KeywordSearchResponseJson from Schemas
 */
interface DocumentKeywordsInterface
{
    /**
     * A page of keywords, optionally filtered and sorted.
     *
     * @return Page<KeywordJson>
     */
    public function list(?KeywordListOptions $options = null): Page;

    /**
     * Every keyword matching `$options`, walking the pages in order
     * (`GET /documents/keywords`).
     *
     * @return list<KeywordJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?KeywordListOptions $options = null): array;

    /**
     * Vector search over keyword embeddings.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return KeywordSearchResponseJson
     */
    public function search(KeywordSearchOptions $options): array;

    /**
     * Gets one keyword by its normalised form.
     *
     * @return KeywordDetailJson
     */
    public function get(string $normalised): array;

    /**
     * Keywords linked to a document.
     *
     * @return list<DocumentKeywordJson>
     */
    public function forDocument(string $documentId): array;
}
