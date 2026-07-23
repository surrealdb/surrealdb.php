<?php

namespace SurrealDB\Spectron;

/**
 * Idempotency-key derivation for safe write retries.
 *
 * Mirrors the reference clients: the key is a SHA-256 digest of the request
 * method, path, body, and a 30-second time bucket. Identical writes replayed
 * within the same bucket collapse to a single server-side effect, which makes
 * the `/facts` and `/facts/batch` writes safe to retry.
 */
final class IdempotencyKey
{
    private const int BUCKET_SECONDS = 30;

    /**
     * Computes the idempotency key for a write request.
     *
     * @param string   $method     HTTP method (e.g. `POST`)
     * @param string   $path       request path including the context prefix
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
