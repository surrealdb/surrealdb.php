<?php

namespace SurrealDB\AgentMemory;

/**
 * Media types for document uploads that declare none, keyed by file extension.
 *
 * The server refuses a media type it has no parser for (`415`) and refuses
 * undeclared bytes that do not look like text, so sending
 * `application/octet-stream` fails for anything binary. Only the extensions
 * the server ingests appear here (mirroring the reference JS client); anything
 * else falls through to `application/octet-stream`, which the server rejects
 * with a clear error rather than misparsing.
 */
final class MimeType
{
    private const array BY_EXTENSION = [
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'csv' => 'text/csv',
        'html' => 'text/html',
        'htm' => 'text/html',
        'json' => 'application/json',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'aac' => 'audio/aac',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
    ];

    /** The media type for `$filename`'s extension, or `null` when it is not one the server ingests. */
    public static function fromFilename(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::BY_EXTENSION[$extension] ?? null;
    }
}
