<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Page;

/** Keyword graph helpers for the document corpus. */
interface DocumentKeywordsInterface
{
    /** A page of keywords, optionally filtered and sorted. */
    public function list(?KeywordListOptions $options = null): Page;

    /**
     * Every keyword matching `$options`, walking the pages in order
     * (`GET /documents/keywords`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?KeywordListOptions $options = null): array;

    /**
     * Vector search over keyword embeddings.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return array<int|string,mixed>
     */
    public function search(KeywordSearchOptions $options): array;

    /**
     * Gets one keyword by its normalised form.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $normalised): array;

    /**
     * Keywords linked to a document.
     *
     * @return list<array<string,mixed>>
     */
    public function forDocument(string $documentId): array;
}
