<?php

namespace SurrealDB\Spectron\Options;

use Psr\Http\Message\StreamInterface;
use SurrealDB\Spectron\Components\Documents;
use SurrealDB\Spectron\MultipartFormData;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;

/** Options for {@see Documents::upload()} and {@see Documents::reprocess()}. */
final readonly class DocumentUploadOptions
{
    /**
     * @param string|StreamInterface                     $file        binary content (required)
     * @param string|null                                $contentType MIME type for the file part; defaults to `application/octet-stream`
     * @param string|null                                $filename    filename for the multipart `file` part
     * @param string|null                                $title       human-readable document title (recorded in the `metadata` part)
     * @param string|null                                $source      source label for the document (recorded in the `metadata` part)
     * @param string|array<int,string|list<string>>|null $scopes      DNF scope selector tagging the document (outer OR, inner AND)
     * @param list<string>|null                          $labels      descriptive `key=value` labels stamped onto the document and its chunks
     */
    public function __construct(
        public string|StreamInterface $file,
        public ?string $contentType = null,
        public ?string $filename = null,
        public ?string $title = null,
        public ?string $source = null,
        public string|array|null $scopes = null,
        public ?array $labels = null,
    ) {}

    /**
     * @internal the multipart form for `POST /documents` / `PUT /documents/{id}`
     */
    public function toForm(): MultipartFormData
    {
        $form = new MultipartFormData();
        $metadata = Payload::compact([
            'title' => $this->title,
            'source' => $this->source,
            'scopes' => Scope::normalise($this->scopes),
            'labels' => $this->labels,
        ]);

        // The server reads multipart fields in declaration order, so the
        // metadata part must precede the file part.
        if ($metadata !== []) {
            $form->addField('metadata', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        $form->addFile(
            'file',
            $this->filename ?? 'upload',
            $this->file instanceof StreamInterface ? (string) $this->file : $this->file,
            $this->contentType ?? 'application/octet-stream',
        );

        return $form;
    }
}
