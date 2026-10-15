<?php

namespace SurrealDB\AgentMemory\Options;

use Psr\Http\Message\StreamInterface;
use SurrealDB\AgentMemory\Components\Documents;
use SurrealDB\AgentMemory\DocumentFile;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\MimeType;
use SurrealDB\AgentMemory\MultipartFormData;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\Scope;

/** Options for {@see Documents::upload()} and {@see Documents::reprocess()}. */
final readonly class DocumentUploadOptions
{
    /**
     * @param string|StreamInterface|DocumentFile        $file             the bytes to upload (required). A DocumentFile also brings its
     *                                                                     filename and media type; a stream is read whole, from the start
     *                                                                     when it is seekable, as the request is built
     * @param string|null                                $contentType      MIME type of the file part; it wins over a DocumentFile's own type.
     *                                                                     When neither gives one it is inferred from the filename's extension;
     *                                                                     with no recognised extension none is sent, and the server sniffs
     *                                                                     the bytes (text is ingested as `text/plain`, unrecognised binary is
     *                                                                     refused with 415)
     * @param string|null                                $filename         filename for the multipart `file` part; it wins over a DocumentFile's.
     *                                                                     Defaults to `upload`
     * @param string|null                                $title            human-readable document title
     * @param string|null                                $source           source label for the document
     * @param string|array<int,string|list<string>>|null $scopes           DNF scope selector tagging the document (outer OR, inner AND);
     *                                                                     upload only: a reprocess keeps the document's scope
     * @param list<string>|null                          $labels           descriptive `key=value` labels stamped onto the document and its chunks
     * @param string|null                                $observedAt       RFC 3339 time the content was observed: back-dates the facts
     *                                                                     extracted from the document
     * @param string|null                                $observedAtHeader name of a front-matter field to read the observed time from
     *                                                                     (ignored when `observedAt` is set)
     */
    public function __construct(
        public string|StreamInterface|DocumentFile $file,
        public ?string $contentType = null,
        public ?string $filename = null,
        public ?string $title = null,
        public ?string $source = null,
        public string|array|null $scopes = null,
        public ?array $labels = null,
        public ?string $observedAt = null,
        public ?string $observedAtHeader = null,
    ) {}

    /**
     * @internal the filename sent on the file part: `filename`, else the
     * DocumentFile's, else `upload`
     */
    public function resolvedFilename(): string
    {
        return $this->filename ?? ($this->file instanceof DocumentFile ? $this->file->filename : DocumentFile::DEFAULT_FILENAME);
    }

    /**
     * @internal the media type sent on the file part: `contentType`, else the
     * DocumentFile's, else inferred from the resolved filename, else `null` so
     * the server sniffs the bytes. Declaring `application/octet-stream`
     * instead would make the server refuse even plain text, since it has no
     * parser for that type.
     */
    public function resolvedContentType(): ?string
    {
        return $this->contentType
            ?? ($this->file instanceof DocumentFile ? $this->file->contentType : null)
            ?? MimeType::fromFilename($this->resolvedFilename());
    }

    /**
     * @internal the multipart form for `POST /documents` / `PUT /documents/{id}`
     *
     * @throws AgentMemoryException     when the metadata cannot be encoded as JSON (e.g. invalid UTF-8)
     * @throws InvalidArgumentException when a stream `file` cannot be read, or the content type contains a line break
     */
    public function toForm(): MultipartFormData
    {
        $form = new MultipartFormData();
        $metadata = Payload::compact([
            'title' => $this->title,
            'source' => $this->source,
            'scopes' => Scope::normalise($this->scopes),
            'labels' => $this->labels,
            'observedAt' => $this->observedAt,
            'observedAtHeader' => $this->observedAtHeader,
        ]);

        // The server accepts the parts in either order; metadata first keeps
        // the request readable and works with older servers, which required it.
        if ($metadata !== []) {
            try {
                $form->addField('metadata', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (\JsonException $e) {
                throw new AgentMemoryException('Failed to encode the document metadata', 0, $e->getMessage(), previous: $e);
            }
        }

        $form->addFile('file', $this->resolvedFilename(), $this->contents(), $this->resolvedContentType());

        return $form;
    }

    /**
     * @throws InvalidArgumentException when a stream `file` cannot be read
     */
    private function contents(): string
    {
        return match (true) {
            $this->file instanceof DocumentFile => $this->file->contents,
            $this->file instanceof StreamInterface => DocumentFile::fromStream($this->file)->contents,
            default => $this->file,
        };
    }
}
