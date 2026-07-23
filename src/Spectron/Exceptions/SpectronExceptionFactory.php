<?php

namespace SurrealDB\Spectron\Exceptions;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Builds the typed {@see SpectronException} for a failed API response, parsing
 * the RFC 7807 problem-details body and choosing the subclass by HTTP status
 * (the PHP port of the JS client's `errorFromResponse`).
 */
final class SpectronExceptionFactory
{
    /** Problem-details members that are not carried over into `extensions`. */
    private const array RESERVED_FIELDS = ['status', 'title', 'detail', 'type', 'instance', 'message'];

    /**
     * @param array<string,string|list<string>> $headers response headers (for `Retry-After` on 429)
     */
    public static function fromResponse(int $status, string $body, array $headers = []): SpectronException
    {
        $title = 'Spectron request failed';
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

        if ($status >= 500) {
            return new ServerException($title, $status, $detail, $type, $instance, $extensions);
        }

        if ($status === 429) {
            return new RateLimitException(
                $title,
                $status,
                $detail,
                $type,
                $instance,
                $extensions,
                self::retryAfter($headers),
            );
        }

        return match ($status) {
            400, 422 => new ValidationException($title, $status, $detail, $type, $instance, $extensions),
            401 => new AuthException($title, $status, $detail, $type, $instance, $extensions),
            403 => new ScopeException($title, $status, $detail, $type, $instance, $extensions),
            404 => new NotFoundException($title, $status, $detail, $type, $instance, $extensions),
            default => new SpectronException($title, $status, $detail, $type, $instance, $extensions),
        };
    }

    /**
     * @param array<string,string|list<string>> $headers
     */
    private static function retryAfter(array $headers): ?int
    {
        foreach ($headers as $name => $values) {
            if (strcasecmp($name, 'Retry-After') !== 0) {
                continue;
            }

            $raw = is_array($values) ? ($values[0] ?? '') : $values;

            return is_numeric($raw) ? (int) $raw : null;
        }

        return null;
    }
}
