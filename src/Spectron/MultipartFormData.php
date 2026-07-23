<?php

namespace SurrealDB\Spectron;

/**
 * Builds a `multipart/form-data` request body. Parts are emitted in insertion
 * order, which matters to the Spectron API: the server reads multipart fields
 * in declaration order, so a `metadata` part must be added before the `file`
 * part it describes.
 */
final class MultipartFormData
{
    private readonly string $boundary;

    /** @var list<string> */
    private array $parts = [];

    public function __construct(?string $boundary = null)
    {
        $this->boundary = $boundary ?? bin2hex(random_bytes(20));
    }

    public function addField(string $name, string $value): void
    {
        $this->parts[] = 'Content-Disposition: form-data; name="' . self::quote($name) . "\"\r\n\r\n" . $value;
    }

    public function addFile(string $name, string $filename, string $contents, string $contentType): void
    {
        $this->parts[] = 'Content-Disposition: form-data; name="' . self::quote($name) . '"; filename="'
            . self::quote($filename) . "\"\r\n"
            . "Content-Type: {$contentType}\r\n\r\n"
            . $contents;
    }

    /** The `Content-Type` header value carrying the boundary. */
    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    /** The full encoded body, including the closing boundary. */
    public function body(): string
    {
        $body = '';

        foreach ($this->parts as $part) {
            $body .= "--{$this->boundary}\r\n{$part}\r\n";
        }

        return $body . "--{$this->boundary}--\r\n";
    }

    private static function quote(string $value): string
    {
        return addcslashes($value, '"\\');
    }
}
