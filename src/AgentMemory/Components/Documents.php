<?php

namespace SurrealDB\AgentMemory\Components;

use SurrealDB\AgentMemory\Contracts\DocumentsInterface;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Schema\Schemas;
use SurrealDB\AgentMemory\Transport;

/**
 * Implements {@see DocumentsInterface} over the Agent Memory HTTP API.
 *
 * @phpstan-import-type ChunkJson from Schemas
 * @phpstan-import-type DocumentJson from Schemas
 * @phpstan-import-type QueryResponseJson from Schemas
 * @phpstan-import-type RecomputeLinksResponse from Schemas
 * @phpstan-import-type UploadResponse from Schemas
 */
final class Documents implements DocumentsInterface
{
    public readonly DocumentKeywords $keywords;

    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {
        $this->keywords = new DocumentKeywords($transport, $contextId);
    }

    public function upload(DocumentUploadOptions $options): array
    {
        /** @var UploadResponse */
        return $this->transport->requestMultipart('POST', $this->base(), $options->toForm()) ?? [];
    }

    public function reprocess(string $documentId, DocumentUploadOptions $options): array
    {
        $path = $this->base() . '/' . Paths::encodeSegment($documentId);

        /** @var UploadResponse */
        return $this->transport->requestMultipart('PUT', $path, $options->toForm())
            ?? ['id' => $documentId, 'status' => 'queued', 'contentHash' => '', 'deduplicated' => false];
    }

    public function get(string $documentId): array
    {
        /** @var DocumentJson */
        return $this->transport->requestJson('GET', $this->base() . '/' . Paths::encodeSegment($documentId)) ?? [];
    }

    public function raw(string $documentId): string
    {
        return $this->transport->requestBytes('GET', $this->base() . '/' . Paths::encodeSegment($documentId) . '/raw');
    }

    public function chunks(string $documentId, ?PageOptions $options = null): Page
    {
        $path = $this->base() . '/' . Paths::encodeSegment($documentId) . '/chunks';

        /** @var Page<ChunkJson> */
        return $this->transport->requestPage($path, 'chunks', $options?->toQuery() ?? []);
    }

    public function allChunks(string $documentId, ?PageOptions $options = null, ?int $max = null): array
    {
        Guard::collectable('Documents::allChunks()', 'Documents::chunks()', $options?->cursor, $options->count ?? false, $max);

        return $this->chunks($documentId, $options)->collect($max);
    }

    public function list(?DocumentListOptions $options = null): Page
    {
        /** @var Page<DocumentJson> */
        return $this->transport->requestPage($this->base(), 'documents', $options?->toQuery() ?? []);
    }

    public function listAll(?DocumentListOptions $options = null): array
    {
        Guard::collectable('Documents::listAll()', 'Documents::list()', $options?->cursor, $options->count ?? false);

        return $this->list($options)->collect();
    }

    public function count(?DocumentListOptions $options = null): int
    {
        $page = $this->list(new DocumentListOptions(status: $options?->status, mimeType: $options?->mimeType, limit: 1, count: true));

        return $page->totalSize ?? $page->count();
    }

    public function delete(string $documentId): void
    {
        $this->transport->requestJson('DELETE', $this->base() . '/' . Paths::encodeSegment($documentId));
    }

    public function query(DocumentQueryOptions $options): array
    {
        /** @var QueryResponseJson */
        return $this->transport->requestJson('POST', $this->base() . '/query', body: $options->toPayload(), idempotent: true) ?? [];
    }

    public function recomputeLinks(): array
    {
        /** @var RecomputeLinksResponse */
        return $this->transport->requestJson('POST', $this->base() . '/recompute-links', body: []) ?? [];
    }

    private function base(): string
    {
        return Paths::contextApiPrefix($this->contextId) . '/documents';
    }
}
