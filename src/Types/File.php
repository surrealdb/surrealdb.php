<?php

namespace SurrealDB\SDK\Types;

use function ord;
use function strlen;

/**
 * A SurrealQL `file` value: a reference to a file stored in a bucket (e.g.
 * `bucket:/path/to/file`). Mirrors the JS SDK's `FileRef`.
 */
final class File extends Value
{
    public readonly string $bucket;
    public readonly string $key;

    public function __construct(string $bucket, string $key)
    {
        $this->bucket = $bucket;
        $this->key = str_starts_with($key, '/') ? $key : '/' . $key;
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $this->bucket === $other->bucket && $this->key === $other->key;
    }

    public function __toString(): string
    {
        return self::escapeInner($this->bucket, true) . ':' . self::escapeInner($this->key, false);
    }

    /**
     * Inline SurrealQL literal form: `f"..."`.
     */
    public function escape(): string
    {
        return 'f' . self::encodeString($this->__toString());
    }

    /**
     * @return array{'$file': array{bucket: string, key: string}}
     */
    public function jsonSerialize(): array
    {
        return ['$file' => ['bucket' => $this->bucket, 'key' => $this->key]];
    }

    /**
     * Backslash-escape characters that are not safe in a file identifier. The
     * forward slash is only escaped within the bucket name.
     */
    private static function escapeInner(string $value, bool $escapeSlash): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $code = ord($char);

            $isSafe = ($code >= 48 && $code <= 57)   // 0-9
                || ($code >= 65 && $code <= 90)       // A-Z
                || ($code >= 97 && $code <= 122)      // a-z
                || $code === 95                        // _
                || $code === 45                        // -
                || $code === 46                        // .
                || (!$escapeSlash && $code === 47);    // /

            $result .= $isSafe ? $char : '\\' . $char;
        }

        return $result;
    }
}
