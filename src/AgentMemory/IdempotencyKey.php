<?php

namespace SurrealDB\AgentMemory;

/**
 * Derives the `Idempotency-Key` sent with every request the client marks
 * idempotent. The key is the lowercase hex SHA-256 of the method, path, body,
 * and a 30-second time bucket, joined by NUL bytes. This is the same
 * derivation as `idempotencyKey()` in `@surrealdb/memory`, so both SDKs send
 * the same key for the same request.
 *
 * The server honours it on the `/facts` and `/facts/batch` writes, where a
 * replay within the bucket has a single effect. The read-only POSTs that are
 * also marked idempotent (`recall`, `lookup`, `context`, document and keyword
 * search, session context) send it too, and the server ignores it there.
 */
final class IdempotencyKey
{
    private const int BUCKET_SECONDS = 30;

    /**
     * Computes the idempotency key for a request.
     *
     * @param string   $method     HTTP method (e.g. `POST`)
     * @param string   $path       request path, without the endpoint or query string
     * @param string   $body       serialised request body (empty string when none)
     * @param int|null $nowSeconds current epoch seconds (injectable for tests)
     *
     * @return string a hex-encoded SHA-256 digest
     */
    public static function compute(string $method, string $path, string $body, ?int $nowSeconds = null): string
    {
        $bucket = intdiv($nowSeconds ?? time(), self::BUCKET_SECONDS);

        return hash('sha256', "{$method}\0{$path}\0{$body}\0{$bucket}");
    }
}
