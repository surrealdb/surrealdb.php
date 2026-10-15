<?php

namespace SurrealDB\AgentMemory;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use function strlen;

/**
 * A document to upload: its bytes with their filename and media type, carried
 * together the way a JS `File` carries them. Pass it as the `file` of a
 * {@see DocumentUploadOptions}:
 *
 *     $memory->documents->upload(new DocumentUploadOptions(DocumentFile::fromPath('handbook.pdf')));
 *
 * The filename defaults to `upload`. The media type is the one given, else
 * the client's for an uploaded file, else the one {@see MimeType} infers from
 * the filename's extension, else none, so the server sniffs the bytes. As with
 * a `File`'s type, it is fixed when the object is created.
 *
 * The bytes are read into memory on creation, as `@surrealdb/memory` buffers
 * an upload. Empty contents are allowed here; the server refuses them with a
 * 400.
 */
final readonly class DocumentFile
{
    /** The filename sent when none is given. */
    public const string DEFAULT_FILENAME = 'upload';

    /**
     * What browsers declare for a file of a type they do not recognise. The
     * server refuses it with a 415, so a client type of this counts as none.
     */
    private const string UNKNOWN_CLIENT_TYPE = 'application/octet-stream';

    /**
     * @param string      $contents    the bytes to upload
     * @param string      $filename    the filename of the multipart `file` part
     * @param string|null $contentType the media type of the part, or `null` to send none
     *
     * @throws InvalidArgumentException when `$contentType` contains a line break
     */
    private function __construct(
        public string $contents,
        public string $filename,
        public ?string $contentType,
    ) {
        if ($contentType !== null && strpbrk($contentType, "\r\n") !== false) {
            throw new InvalidArgumentException('A document content type cannot contain line breaks.');
        }
    }

    /**
     * @param string|null $filename    defaults to `upload`
     * @param string|null $contentType defaults to the type inferred from the filename
     *
     * @throws InvalidArgumentException when `$contentType` contains a line break
     */
    public static function fromString(string $contents, ?string $filename = null, ?string $contentType = null): self
    {
        return self::create($contents, $filename, $contentType);
    }

    /**
     * Reads the file at `$path`.
     *
     * @param string|null $filename    defaults to the basename of `$path`
     * @param string|null $contentType defaults to the type inferred from the filename
     *
     * @throws InvalidArgumentException when `$path` is not a readable file, or `$contentType` contains a line break
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read the document file \"{$path}\".");
        }

        return self::create($contents, $filename ?? basename($path), $contentType);
    }

    /**
     * Reads the whole stream, from the start when it is seekable.
     *
     * @param string|null $filename    defaults to `upload`
     * @param string|null $contentType defaults to the type inferred from the filename
     *
     * @throws InvalidArgumentException when the stream cannot be read, or `$contentType` contains a line break
     */
    public static function fromStream(StreamInterface $stream, ?string $filename = null, ?string $contentType = null): self
    {
        return self::create(self::read($stream), $filename, $contentType);
    }

    /**
     * Reads a file uploaded to a PSR-7 server request, keeping the client's
     * filename and media type unless they are given. A client type of
     * `application/octet-stream` counts as none, so the type is inferred from
     * the filename instead.
     *
     * @throws InvalidArgumentException when the upload failed, its stream was moved or cannot be read, or the
     *                                  content type contains a line break
     */
    public static function fromUploadedFile(
        UploadedFileInterface $file,
        ?string $filename = null,
        ?string $contentType = null,
    ): self {
        $error = $file->getError();

        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException("Cannot read the uploaded document: upload error {$error}.");
        }

        try {
            $stream = $file->getStream();
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException('Cannot read the uploaded document: ' . $e->getMessage(), previous: $e);
        }

        $clientType = self::nonBlank($file->getClientMediaType());

        return self::create(
            self::read($stream),
            self::nonBlank($filename) ?? $file->getClientFilename(),
            self::nonBlank($contentType) ?? ($clientType === self::UNKNOWN_CLIENT_TYPE ? null : $clientType),
        );
    }

    /**
     * What `var_dump()` shows: the bytes are left out, as they can run to
     * megabytes.
     *
     * @return array{filename: string, contentType: string|null, size: int}
     */
    public function __debugInfo(): array
    {
        return ['filename' => $this->filename, 'contentType' => $this->contentType, 'size' => strlen($this->contents)];
    }

    private static function create(string $contents, ?string $filename, ?string $contentType): self
    {
        $filename = self::nonBlank($filename) ?? self::DEFAULT_FILENAME;

        return new self($contents, $filename, self::nonBlank($contentType) ?? MimeType::fromFilename($filename));
    }

    /**
     * @throws InvalidArgumentException when the stream is not readable, or fails while being read
     */
    private static function read(StreamInterface $stream): string
    {
        if (!$stream->isReadable()) {
            throw new InvalidArgumentException('Cannot read the document stream: it is not readable.');
        }

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            return $stream->getContents();
        } catch (\RuntimeException $e) {
            throw new InvalidArgumentException('Cannot read the document stream: ' . $e->getMessage(), previous: $e);
        }
    }

    private static function nonBlank(?string $value): ?string
    {
        return $value === null || Guard::isBlank($value) ? null : $value;
    }
}
