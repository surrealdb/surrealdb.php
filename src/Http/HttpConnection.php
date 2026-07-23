<?php

namespace SurrealDB\Http;

use Psr\Http\Message\ResponseInterface;
use SurrealDB\Contracts\HttpClientProviderInterface;
use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Exceptions\HttpConnectionException;

/**
 * The default {@see HttpConnectionInterface}: performs one PSR-18 exchange and
 * throws {@see HttpConnectionException} on any non-2xx response. Shared by the
 * SurrealDB HTTP transport (RPC + raw import/export) and the Spectron client.
 */
final class HttpConnection implements HttpConnectionInterface
{
    public function __construct(private readonly HttpClientProviderInterface $provider) {}

    /**
     * @param array<string,string> $headers
     */
    public function request(string $url, string $method, ?string $body, array $headers): ResponseInterface
    {
        $request = $this->provider->requestFactory()->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->provider->streamFactory()->createStream($body));
        }

        $response = $this->provider->client()->sendRequest($request);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $content = (string) $response->getBody();

            throw new HttpConnectionException(
                $content !== '' ? $content : $response->getReasonPhrase(),
                $status,
                $response->getReasonPhrase(),
                $content,
                $response->getHeaders(),
            );
        }

        return $response;
    }
}
