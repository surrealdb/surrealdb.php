<?php

namespace SurrealDB\Http;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\Contracts\HttpClientProviderInterface;
use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Exceptions\HttpConnectionException;
use function sprintf;
use function strlen;

/**
 * The default {@see HttpConnectionInterface}: performs one PSR-18 exchange and
 * throws {@see HttpConnectionException} on any non-2xx response. Shared by the
 * SurrealDB HTTP transport (RPC + raw import/export) and the Agent Memory client.
 */
final class HttpConnection implements HttpConnectionInterface
{
    public function __construct(private readonly HttpClientProviderInterface $provider) {}

    /**
     * @param array<string,string> $headers
     */
    public function request(string $url, string $method, ?string $body, array $headers): ResponseInterface
    {
        $response = $this->send($url, $method, $body, $headers);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw self::failure($response);
        }

        return $response;
    }

    /**
     * Perform the exchange and return the response whatever its status, for
     * callers that read error details from the body themselves.
     *
     * @param array<string,string> $headers
     */
    public function send(string $url, string $method, ?string $body, array $headers): ResponseInterface
    {
        $request = $this->provider->requestFactory()->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->provider->streamFactory()->createStream($body));
        }

        return $this->provider->client()->sendRequest($request);
    }

    /**
     * Read the whole response body. A body that ends before the length the
     * response declared broke off: some clients (Guzzle without curl) pass
     * it off as complete, and an export cut short would read as a whole one.
     */
    public static function body(ResponseInterface $response): string
    {
        $content = (string) $response->getBody();
        $declared = $response->getHeaderLine('Content-Length');

        // A body the client decompressed has another length than declared.
        $encoded = $response->hasHeader('Content-Encoding') || $response->hasHeader('X-Encoded-Content-Encoding');

        if (!$encoded && ctype_digit($declared) && strlen($content) < (int) $declared) {
            throw new HttpConnectionException(
                sprintf('The response ended after %d of its %d bytes.', strlen($content), (int) $declared),
                $response->getStatusCode(),
                $response->getReasonPhrase(),
            );
        }

        return $content;
    }

    /**
     * @param string|null $content the response body, when the caller has
     *                             already consumed it from the stream
     */
    public static function failure(ResponseInterface $response, ?string $message = null, ?string $content = null): HttpConnectionException
    {
        $content ??= self::body($response);

        return new HttpConnectionException(
            $message ?? ($content !== '' ? $content : $response->getReasonPhrase()),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
            $content,
            $response->getHeaders(),
        );
    }
}
