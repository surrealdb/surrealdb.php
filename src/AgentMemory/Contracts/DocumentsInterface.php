<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Exceptions\UnsupportedMediaTypeException;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * Document ingestion, retrieval, and corpus search.
 *
 * @phpstan-import-type ChunkJson from Schemas
 * @phpstan-import-type DocumentJson from Schemas
 * @phpstan-import-type QueryResponseJson from Schemas
 * @phpstan-import-type RecomputeLinksResponse from Schemas
 * @phpstan-import-type UploadResponse from Schemas
 */
interface DocumentsInterface
{
    /** Keyword graph for the document corpus. */
    public DocumentKeywordsInterface $keywords { get; }

    /**
     * Uploads a document (multipart). Ingestion is asynchronous: the returned
     * handle starts `queued`; poll {@see get()} until its `status` is `ready`.
     *
     * @return UploadResponse
     *
     * @throws UnsupportedMediaTypeException when the server has no parser for the file's media type
     */
    public function upload(DocumentUploadOptions $options): array;

    /**
     * Reprocesses an existing document with replacement bytes (multipart). The
     * document keeps its scope; a `scopes` option is ignored.
     *
     * @return UploadResponse
     */
    public function reprocess(string $documentId, DocumentUploadOptions $options): array;

    /**
     * Metadata for one document, including its pipeline `status`.
     *
     * @return DocumentJson
     */
    public function get(string $documentId): array;

    /** Raw document bytes. */
    public function raw(string $documentId): string;

    /**
     * A page of one document's text chunks.
     *
     * @return Page<ChunkJson>
     */
    public function chunks(string $documentId, ?PageOptions $options = null): Page;

    /**
     * Every chunk of the document, walking the pages in order
     * (`GET /documents/{id}/chunks`). With `$max` the walk stops after the
     * page that reaches it, so up to one page more can come back.
     *
     * @param int|null $max stop collecting once this many chunks are in (at least 1)
     *
     * @return list<ChunkJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`, or `$max` is below 1
     */
    public function allChunks(string $documentId, ?PageOptions $options = null, ?int $max = null): array;

    /**
     * A page of documents, optionally filtered by status or MIME type.
     *
     * @return Page<DocumentJson>
     */
    public function list(?DocumentListOptions $options = null): Page;

    /**
     * Every document matching `$options`, walking the pages in order
     * (`GET /documents`).
     *
     * @return list<DocumentJson>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?DocumentListOptions $options = null): array;

    /**
     * How many documents match, without fetching them: one request for a
     * single row with `count: true`. Only the filters (`status`, `mimeType`)
     * are read; a response without `totalSize` counts the rows it carries.
     */
    public function count(?DocumentListOptions $options = null): int;

    /** Deletes a document. */
    public function delete(string $documentId): void;

    /**
     * Hybrid / vector / BM25 / graph search over the document corpus.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return QueryResponseJson
     */
    public function query(DocumentQueryOptions $options): array;

    /**
     * Recomputes derived document↔keyword and document↔document links.
     *
     * @return RecomputeLinksResponse
     */
    public function recomputeLinks(): array;
}
