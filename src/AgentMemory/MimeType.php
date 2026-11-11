<?php

namespace SurrealDB\AgentMemory;

/**
 * Infers the media type of an upload that declares none, from its filename's
 * extension.
 *
 * The server refuses a media type it has no parser for (`415`), and sniffs a
 * file part that declares no type: text is ingested as `text/plain`, and
 * other bytes are refused. So only the extensions the server ingests are
 * listed. For any other filename, {@see fromFilename()} returns `null`, the
 * part is sent without a `Content-Type`, and the server sniffs the bytes.
 * Declaring `application/octet-stream` instead would make the server refuse
 * even plain text. `@surrealdb/memory` does not infer types.
 *
 * @internal
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
