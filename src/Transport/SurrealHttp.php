<?php

namespace SurrealDB\SDK\Transport;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\SDK\Exceptions\HttpConnectionException;

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
     * @param string|null $content the response body, when the caller has
     *                             already consumed it from the stream
     */
    public static function failure(ResponseInterface $response, ?string $message = null, ?string $content = null): HttpConnectionException
    {
        $content ??= (string) $response->getBody();

        return new HttpConnectionException(
            $message ?? ($content !== '' ? $content : $response->getReasonPhrase()),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
            $content,
        );
    }
}
