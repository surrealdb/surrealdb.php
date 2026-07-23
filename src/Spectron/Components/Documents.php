<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\DocumentChunkOptions;
use SurrealDB\Spectron\Options\DocumentListOptions;
use SurrealDB\Spectron\Options\DocumentQueryOptions;
use SurrealDB\Spectron\Options\DocumentUploadOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

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
     * Uploads a document (multipart). Returns the ingestion handle.
     *
     * @return array<int|string,mixed>
     */
    public function upload(DocumentUploadOptions $options): array
    {
        return $this->transport->requestMultipart('POST', $this->base(), $options->toForm()) ?? [];
    }

    /**
     * Reprocesses an existing document with replacement bytes (multipart).
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
     * Metadata for one document.
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

    /**
     * Paginated text chunks.
     *
     * @return array<int|string,mixed>
     */
    public function chunks(string $documentId, ?DocumentChunkOptions $options = null): array
    {
        $path = $this->base() . '/' . Paths::encodeSegment($documentId) . '/chunks';

        return $this->transport->requestJson('GET', $path, $options?->toQuery() ?? []) ?? [];
    }

    /**
     * Lists documents with optional filters.
     *
     * @return array<int|string,mixed>
     */
    public function list(?DocumentListOptions $options = null): array
    {
        return $this->transport->requestJson('GET', $this->base(), $options?->toQuery() ?? []) ?? [];
    }

    /** Deletes a document. */
    public function delete(string $documentId): void
    {
        $this->transport->requestJson('DELETE', $this->base() . '/' . Paths::encodeSegment($documentId));
    }

    /**
     * Hybrid / vector / BM25 / graph search over the document corpus.
     *
     * @return array<int|string,mixed>
     */
    public function query(DocumentQueryOptions $options): array
    {
        return $this->transport->requestJson('POST', $this->base() . '/query', body: $options->toPayload()) ?? [];
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
