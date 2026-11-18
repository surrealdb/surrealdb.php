<?php

namespace SurrealDB\AgentMemory;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;

/**
 * Builds a `multipart/form-data` request body. Parts are emitted in insertion
 * order. The server accepts the `metadata` and `file` parts in either order;
 * uploads still add `metadata` first, which older servers required.
 *
 * @internal
 */
final class MultipartFormData
{
    /**
     * Characters that cannot appear raw inside a quoted `name`/`filename`
     * parameter, percent-encoded as the WHATWG form-data algorithm does. A raw
     * CR or LF would end the header line and let a filename inject headers or
     * bytes into the part; `"` would end the quoted value; and `\` is encoded
     * too because the server's parser treats `\"` as an escaped quote, so a
     * value ending in a backslash would swallow its closing quote.
     */
    private const array PARAMETER_ESCAPES = ['"' => '%22', '\\' => '%5C', "\r" => '%0D', "\n" => '%0A'];

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

    /**
     * @param string|null $contentType the part's media type, or `null` to send none and let
     *                                 the server sniff the bytes
     */
    public function addFile(string $name, string $filename, string $contents, ?string $contentType): void
    {
        if ($contentType !== null && strpbrk($contentType, "\r\n") !== false) {
            throw new InvalidArgumentException('A multipart Content-Type cannot contain line breaks.');
        }

        $this->parts[] = 'Content-Disposition: form-data; name="' . self::quote($name) . '"; filename="'
            . self::quote($filename) . "\"\r\n"
            . ($contentType === null ? '' : "Content-Type: {$contentType}\r\n")
            . "\r\n"
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
        return strtr($value, self::PARAMETER_ESCAPES);
    }
}
