<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Exceptions\UnsupportedMediaTypeException;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Transport;

/** Document ingestion, retrieval, and corpus search. */
final class Documents
{
    /** Keyword graph for the document corpus. */
    public readonly DocumentKeywords $keywords;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {
        $this->keywords = new DocumentKeywords($transport, $contextId);
    }

    /**
     * Uploads a document (multipart). Ingestion is asynchronous: the returned
     * handle starts `queued`; poll {@see get()} until its `status` is `ready`.
     *
     * @return array<int|string,mixed>
     *
     * @throws UnsupportedMediaTypeException when the server has no parser for the file's media type
     */
    public function upload(DocumentUploadOptions $options): array
    {
        return $this->transport->requestMultipart('POST', $this->base(), $options->toForm()) ?? [];
    }

    /**
     * Reprocesses an existing document with replacement bytes (multipart). The
     * document keeps its scope; a `scopes` option is ignored.
     *
     * @return array<int|string,mixed>
     */
    public function reprocess(string $documentId, DocumentUploadOptions $options): array
    {
        $path = $this->base() . '/' . Paths::encodeSegment($documentId);

        return $this->transport->requestMultipart('PUT', $path, $options->toForm())
            ?? ['id' => $documentId, 'status' => 'queued', 'contentHash' => '', 'deduplicated' => false];
    }

    /**
     * Metadata for one document, including its pipeline `status`.
     *
     * @return array<int|string,mixed>
     */
    public function get(string $documentId): array
    {
        return $this->transport->requestJson('GET', $this->base() . '/' . Paths::encodeSegment($documentId)) ?? [];
    }

    /** Raw document bytes. */
    public function raw(string $documentId): string
    {
        return $this->transport->requestBytes('GET', $this->base() . '/' . Paths::encodeSegment($documentId) . '/raw');
    }

    /** A page of one document's text chunks. */
    public function chunks(string $documentId, ?PageOptions $options = null): Page
    {
        $path = $this->base() . '/' . Paths::encodeSegment($documentId) . '/chunks';

        return $this->transport->requestPage($path, 'chunks', $options?->toQuery() ?? []);
    }

    /** A page of documents, optionally filtered by status or MIME type. */
    public function list(?DocumentListOptions $options = null): Page
    {
        return $this->transport->requestPage($this->base(), 'documents', $options?->toQuery() ?? []);
    }

    /** Deletes a document. */
    public function delete(string $documentId): void
    {
        $this->transport->requestJson('DELETE', $this->base() . '/' . Paths::encodeSegment($documentId));
    }

    /**
     * Hybrid / vector / BM25 / graph search over the document corpus.
     *
     * Retried like a read, with an `Idempotency-Key`, as the JS client does.
     *
     * @return array<int|string,mixed>
     */
    public function query(DocumentQueryOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/query', body: $options->toPayload(), idempotent: true) ?? [];
    }

    /**
     * Recomputes derived document↔keyword and document↔document links.
     *
     * @return array<int|string,mixed>
     */
    public function recomputeLinks(): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/recompute-links', body: []) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/documents';
    }
}
