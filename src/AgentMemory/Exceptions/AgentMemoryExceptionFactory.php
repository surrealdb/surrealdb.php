<?php

namespace SurrealDB\AgentMemory\Exceptions;

use SurrealDB\AgentMemory\RetryAfter;
use function in_array;
use function is_array;
use function is_string;

/**
 * Builds the typed {@see AgentMemoryException} for a failed API response,
 * parsing the error body and choosing the subclass by HTTP status (the PHP port
 * of the JS client's `errorForStatus`).
 */
final class AgentMemoryExceptionFactory
{
    /** Error-body members that are not carried over into `extensions`. */
    private const array RESERVED_FIELDS = ['status', 'title', 'detail', 'type', 'instance', 'message'];

    /**
     * @param array<string,string|list<string>> $headers response headers (for `Retry-After`)
     */
    public static function fromResponse(int $status, string $body, array $headers = []): AgentMemoryException
    {
        $title = 'Agent Memory request failed';
        $detail = null;
        $type = null;
        $instance = null;
        $extensions = [];

        $decoded = $body === '' ? null : json_decode($body, true);

        if (is_array($decoded) && !array_is_list($decoded)) {
            $candidate = $decoded['title'] ?? $decoded['message'] ?? null;

            if (is_string($candidate)) {
                $title = $candidate;
            }

            $detail = is_string($decoded['detail'] ?? null) ? $decoded['detail'] : null;
            $type = is_string($decoded['type'] ?? null) ? $decoded['type'] : null;
            $instance = is_string($decoded['instance'] ?? null) ? $decoded['instance'] : null;

            foreach ($decoded as $key => $value) {
                if (!in_array($key, self::RESERVED_FIELDS, true)) {
                    $extensions[$key] = $value;
                }
            }
        } elseif ($body !== '') {
            $detail = $body;
        }

        $retryAfter = RetryAfter::seconds($headers);

        $class = match (true) {
            $status >= 500 => ServerException::class,
            $status === 400, $status === 422 => ValidationException::class,
            $status === 401 => AuthException::class,
            $status === 403 => ScopeException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 415 => UnsupportedMediaTypeException::class,
            $status === 429 => RateLimitException::class,
            default => AgentMemoryException::class,
        };

        return new $class($title, $status, $detail, $type, $instance, $extensions, $retryAfter);
    }
}
