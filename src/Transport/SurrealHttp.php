<?php

namespace SurrealDB\SDK\Transport;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\SDK\Exceptions\HttpConnectionException;
use function sprintf;
use function strlen;

/**
 * Performs a single PSR-18 HTTP exchange against a SurrealDB endpoint, throwing
 * {@see HttpConnectionException} on non-2xx responses. Shared by the HTTP
 * transport and the raw import/export operations.
 */
final class SurrealHttp
{
    public function __construct(private readonly HttpClientResolver $resolver) {}

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
        $request = $this->resolver->requestFactory()->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->resolver->streamFactory()->createStream($body));
        }

        return $this->resolver->client()->sendRequest($request);
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
        );
    }
}
