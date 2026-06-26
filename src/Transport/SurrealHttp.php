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
        $request = $this->resolver->requestFactory()->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->resolver->streamFactory()->createStream($body));
        }

        $response = $this->resolver->client()->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $content = (string) $response->getBody();

            throw new HttpConnectionException(
                $content !== '' ? $content : $response->getReasonPhrase(),
                $status,
                $response->getReasonPhrase(),
                $content,
            );
        }

        return $response;
    }
}
