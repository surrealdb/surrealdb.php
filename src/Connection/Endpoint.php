<?php

namespace SurrealDB\SDK\Connection;

use SurrealDB\SDK\Exceptions\SurrealException;
use function in_array;

/**
 * A parsed, normalized SurrealDB endpoint. Remote schemes get a `/rpc` suffix
 * appended when missing, mirroring the JS `parseEndpoint`.
 */
final readonly class Endpoint
{
    private const array REMOTE_SCHEMES = ['ws', 'wss', 'http', 'https'];

    public function __construct(
        public string $uri,
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
    ) {}

    public static function parse(string $url): self
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SurrealException("Invalid endpoint URL: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        $host = $parts['host'];
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';

        if (in_array($scheme, self::REMOTE_SCHEMES, true) && !str_ends_with($path, '/rpc')) {
            $path = rtrim($path, '/') . '/rpc';
        }

        return new self(self::compose($scheme, $host, $port, $path), $scheme, $host, $port, $path);
    }

    /** The equivalent HTTP(S) scheme (ws -> http, wss -> https). */
    public function httpScheme(): string
    {
        return match ($this->scheme) {
            'ws' => 'http',
            'wss' => 'https',
            default => $this->scheme,
        };
    }

    /** The endpoint as an HTTP(S) URL (used by the HTTP transport). */
    public function httpUri(): string
    {
        return self::compose($this->httpScheme(), $this->host, $this->port, $this->path);
    }

    public function withPath(string $path): self
    {
        return new self(self::compose($this->scheme, $this->host, $this->port, $path), $this->scheme, $this->host, $this->port, $path);
    }

    /** The path with any trailing `/rpc` removed (the import/export base). */
    public function basePath(): string
    {
        return str_ends_with($this->path, '/rpc') ? substr($this->path, 0, -4) : $this->path;
    }

    public function httpUriWithPath(string $path): string
    {
        return self::compose($this->httpScheme(), $this->host, $this->port, $path);
    }

    private static function compose(string $scheme, string $host, ?int $port, string $path): string
    {
        $authority = $host . ($port !== null ? ":{$port}" : '');

        return "{$scheme}://{$authority}{$path}";
    }
}
