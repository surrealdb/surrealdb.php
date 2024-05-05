<?php

namespace Surreal\Core\Engines;

use Beau\CborPHP\exceptions\CborException;
use CurlHandle;
use Exception;
use Surreal\Cbor\Types\None;
use Surreal\Core\AbstractEngine;
use Surreal\Core\Curl\HttpContentFormat;
use Surreal\Core\Curl\HttpMethod;
use Surreal\Core\Curl\HttpStatus;
use Surreal\Core\Responses\{Types\RpcResponse};
use Surreal\Core\Responses\ResponseInterface;
use Surreal\Core\Responses\ResponseParser;
use Surreal\Core\Responses\Types\ImportResponse;
use Surreal\Core\Responses\Types\StringResponse;
use Surreal\Core\Results\{ImportResult, RpcResult, StringResult};
use Surreal\Core\RpcMessage;
use Surreal\Core\Traits\SurrealTrait;
use Surreal\Exceptions\SurrealException;

class HttpEngine extends AbstractEngine
{
    use SurrealTrait;

    private int $incrementalId = 0;
    private ?CurlHandle $client;

    /**
     * @param string $host
     * @param array $options - curl options.
     */
    public function __construct(
        string $host,
        array  $options = []
    )
    {
        if (str_ends_with($host, "/rpc")) {
            $host = substr($host, 0, -4);
        }

        // initialize the curl client.
        $this->client = curl_init();

        curl_setopt($this->client, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->client, CURLOPT_TIMEOUT, 5);
        curl_setopt($this->client, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        switch (parse_url($host, PHP_URL_SCHEME)) {
            case 'http':
                curl_setopt($this->client, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($this->client, CURLOPT_SSL_VERIFYHOST, false);
                break;
            case 'https':
                curl_setopt($this->client, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($this->client, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($this->client, CURLOPT_SSLVERSION, CURL_SSLVERSION_MAX_TLSv1_1);
                break;
        }

        curl_setopt_array($this->client, $options);

        parent::__construct($host);
    }

    /**
     * Builds the header for the request.
     * @param HttpContentFormat $type
     * @param HttpContentFormat $accept
     * @return array
     */
    private function buildHeader(
        HttpContentFormat $type = HttpContentFormat::CBOR,
        HttpContentFormat $accept = HttpContentFormat::CBOR
    ): array
    {
        $headers = [];

        // set the content type and accept headers.
        $headers[] = "Content-Type: " . $type->value;
        $headers[] = "Accept: " . $accept->value;

        // Set the surreal headers
        if (($token = $this->getToken()) !== null) {
            $headers[] = "Authorization: Bearer $token";
        }

        if (($namespace = $this->getNamespace()) !== null) {
            $headers[] = "Surreal-NS: $namespace";
        }

        if (($database = $this->getDatabase()) !== null) {
            $headers[] = "Surreal-DB: $database";
        }

        return $headers;
    }

    /**
     * Returns the status of the server.
     * @returns int - http status code
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#status
     */
    public function status(): int
    {
        $this->baseExecute("/status", HttpMethod::GET);
        return curl_getinfo($this->client, CURLINFO_RESPONSE_CODE);
    }

    /**
     * Returns the health status of the server.
     * @returns int - http status code
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#health
     */
    public function health(): int
    {
        $this->baseExecute("/health", HttpMethod::GET);
        return curl_getinfo($this->client, CURLINFO_RESPONSE_CODE);
    }

    /**
     * Makes the current session invalid
     * @param string $content - content inside a .surql file.
     * @param string $username
     * @param string $password
     * @return array|null - Array of SingleRecordResponse
     * @throws SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#import
     */
    public function import(string $content, string $username, string $password): ?array
    {
        $response = $this->execute(
            endpoint: "/import",
            method: HttpMethod::POST,
            response: ImportResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $this->buildHeader(type: HttpContentFormat::UTF8),
                CURLOPT_POSTFIELDS => $content,
                CURLOPT_USERPWD => "$username:$password"
            ]
        );

        return ImportResult::from($response);
    }

    /**
     * Returns an exported content of the current selected database as string.
     * @param string $username
     * @param string $password
     * @return string - exported content
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#export
     */
    public function export(string $username, string $password): string
    {
        $response = $this->execute(
            endpoint: "/export",
            method: HttpMethod::GET,
            response: StringResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $this->buildHeader(accept: HttpContentFormat::UTF8),
                CURLOPT_USERPWD => "$username:$password"
            ]
        );

        return StringResult::from($response);
    }

    /**
     * Import a machine learning model into the database. When username and password aren't provided.
     * It uses the token from the current session.
     * @param string $content - content inside a .surml file.
     * @param string|null $username
     * @param string|null $password
     * @return mixed
     * @throws SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#ml-import
     */
    public function importML(string $content, ?string $username = null, ?string $password = null): mixed
    {
        $response = $this->execute(
            endpoint: "/ml/import",
            method: HttpMethod::POST,
            response: ImportResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $this->buildHeader(type: HttpContentFormat::UTF8),
                CURLOPT_POSTFIELDS => $content,
                CURLOPT_USERPWD => "$username:$password"
            ]
        );

        return ImportResult::from($response);
    }

    /**
     * Export a machine learning model from the database.
     * @param string $name
     * @param string $version
     * @param string|null $username
     * @param string|null $password
     * @return string
     * @throws SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#ml-export
     */
    public function exportML(
        string  $name,
        string  $version,
        ?string $username = null,
        ?string $password = null
    ): string
    {
        $response = $this->execute(
            endpoint: "/ml/export/$name/$version",
            method: HttpMethod::GET,
            response: StringResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $this->buildHeader(accept: HttpContentFormat::UTF8),
                CURLOPT_USERPWD => "$username:$password"
            ]
        );

        return StringResult::from($response);
    }

    /**
     * @throws Exception
     */
    private function baseExecute(
        string     $endpoint,
        HttpMethod $method,
        array      $options = []
    ): void
    {
        if ($this->client === null) {
            throw new Exception("The curl client is not initialized.");
        }

        curl_setopt($this->client, CURLOPT_URL, $this->host . $endpoint);
        curl_setopt($this->client, CURLOPT_CUSTOMREQUEST, $method->value);

        curl_setopt_array($this->client, $options);

        // throwing an exception if the request fails.
        if (curl_exec($this->client) === false) {
            throw new Exception(curl_error($this->client));
        }
    }

    /**
     * @param string $endpoint
     * @param HttpMethod $method
     * @param string $response
     * @param array $options
     * @return ResponseInterface
     * @throws Exception
     */
    private function execute(
        string     $endpoint,
        HttpMethod $method,
        string     $response,
        array      $options = []
    ): ResponseInterface
    {
        $this->baseExecute($endpoint, $method, $options);

        // get the content type of the response.
        $status = curl_getinfo($this->client, CURLINFO_RESPONSE_CODE);

        if ($status == HttpStatus::BAD_GATEWAY) {
            throw new Exception("Surreal is currently unavailable.", HttpStatus::BAD_GATEWAY->value);
        }

        $type = curl_getinfo($this->client, CURLINFO_CONTENT_TYPE);
        $body = curl_multi_getcontent($this->client);

        $type = $type ? HttpContentFormat::from($type) : HttpContentFormat::UTF8;
        $result = ResponseParser::parse($type, $body);

        /** @var $response ResponseInterface */
        return $response::from($result, $type, $status);
    }

    /**
     * Checks if you can make use of the database.
     * @throws Exception
     */
    public function connect(): void
    {
        $status = $this->status();

        if ($status !== HttpStatus::OK->value) {
            throw new Exception("The server is not available.", $status);
        }

        // send a handshake request to the server.
        $health = $this->health();

        if ($health !== HttpStatus::OK->value) {
            throw new Exception("The server is not healthy.", $health);
        }
    }

    /**
     * @throws SurrealException|CborException|Exception
     */
    public function rpc(RpcMessage $message): mixed
    {
        if ($message->method === "use") {
            [$namespace, $database] = $message->params;

            $this->setNamespace($namespace);
            $this->setDatabase($database);

            return new None();
        } else if ($message->method === "let") {
            $this->let(...$message->params);
            return null;
        } else if ($message->method === "unset") {
            $this->unset($message->params[0]);
            return null;
        } else if ($message->method === "query") {
            $message->setParams([
                $message->params[0],
                [...$this->params, ...$message->params[1]]
            ]);
        }

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $this->buildHeader(),
                CURLOPT_POSTFIELDS => $message
                    ->setId($this->incrementalId++)
                    ->toCborString()
            ]
        );

        $response = RpcResult::from($response);

        switch ($message->method) {
            case "signin":
            case "signup":
                $this->setToken($response);
                break;
            case "authenticate":
                [$jwt] = $message->params;
                $this->setToken($jwt);
                break;
            case "invalidate":
                $this->setToken(null);
                break;
        }

        return $response;
    }

    /**
     * Closes the http connection
     * @return bool
     */
    public function disconnect(): bool
    {
        if ($this->client === null) {
            return false;
        }

        curl_close($this->client);
        $this->client = null;

        return true;
    }


    public function setTimeout(int $seconds): void
    {
        curl_setopt($this->client, CURLOPT_TIMEOUT, $seconds);
    }
}