<?php

namespace Surreal\Core\Client;

use Beau\CborPHP\exceptions\CborException;
use CurlHandle;
use Exception;
use Surreal\Core\AbstractSurreal;
use Surreal\Core\Responses\{Types\RpcResponse};
use Surreal\Core\Responses\ResponseInterface;
use Surreal\Core\Responses\ResponseParser;
use Surreal\Core\Responses\Types\ImportResponse;
use Surreal\Core\Responses\Types\StringResponse;
use Surreal\Core\Results\{AuthResult, ImportResult, RpcResult, StringResult};
use Surreal\Core\Rpc\RpcMessage;
use Surreal\Core\Utils\ThingParser;
use Surreal\Curl\HttpContentType;
use Surreal\Curl\HttpHeader;
use Surreal\Curl\HttpMethod;
use Surreal\Curl\HttpStatus;
use Surreal\Exceptions\SurrealException;

class SurrealHTTP extends AbstractSurreal
{
    private int $incrementalId = 0;
    private ?CurlHandle $client;

    /**
     * @param string $host
     * @param array{namespace:string, database:string|null} $target
     * @param array $options - curl options.
     */
    public function __construct(
        string $host,
        array  $target = [],
        array  $options = []
    )
    {
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

        parent::__construct($host, $target);
    }

    /**
     * Returns the status of the server.
     * @returns int - http status code
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#status
     */
    public function status(): int
    {
        return $this->checkStatusCode("/status");
    }

    /**
     * Returns the health status of the server.
     * @returns int - http status code
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#health
     */
    public function health(): int
    {
        return $this->checkStatusCode("/health");
    }

    /**
     * Returns the version of the server.
     * @return string
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#version
     */
    public function version(): string
    {
        $response = $this->execute(
            endpoint: "/version",
            method: HttpMethod::GET,
            response: StringResponse::class,
            options: [
                CURLOPT_POSTFIELDS => RpcMessage::create("version")
                    ->setId($this->incrementalId++)
                    ->toCborString()
            ]
        );

        return StringResult::from($response);
    }

    /**
     * Returns auth information of the current session
     * @returns array
     * @throws Exception|SurrealException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#info
     */
    public function info(): array
    {
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setAuthorizationHeader()
            ->getHeaders();

        $response = $this->execute(
            endpoint: "/info",
            method: HttpMethod::GET,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers
            ]
        );

        return RpcResult::from($response);
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
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_TEXT)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->getHeaders();

        $response = $this->execute(
            endpoint: "/import",
            method: HttpMethod::POST,
            response: ImportResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
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
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_TEXT)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->getHeaders();

        $response = $this->execute(
            endpoint: "/export",
            method: HttpMethod::GET,
            response: StringResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERPWD => "$username:$password"
            ]
        );

        return StringResult::from($response);
    }

    /**
     * Singin with a root, namespace, database or scoped user.
     * @param array{NS:string|null,DB:string|null,SC:string|null} $data
     * @return string|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#signin
     */
    public function signin(array $data): ?string
    {
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->getHeaders();

        $payload = RpcMessage::create("signin")
            ->setId($this->incrementalId++)
            ->setParams([$data])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return AuthResult::from($response);
    }

    /**
     * Signup a new scoped user.
     * @param array{NS:string,DB:string,SC:string} $data
     * @return string|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#signup
     */
    public function signup(array $data): ?string
    {
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->getHeaders();

        $payload = RpcMessage::create("signup")
            ->setId($this->incrementalId++)
            ->setParams([$data])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return AuthResult::from($response);
    }

    /**
     * Selects a record or the whole table.
     * @param string $thing
     * @return mixed
     * @throws CborException|SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#select
     */
    public function select(string $thing): mixed
    {
        $thing = ThingParser::from($thing)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("select")
            ->setId($this->incrementalId++)
            ->setParams([$thing])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Creates a new record in a table.
     * @param string $thing
     * @param mixed $data
     * @return object|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#create
     */
    public function create(string $thing, mixed $data): ?array
    {
        $table = ThingParser::from($thing)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("create")
            ->setId($this->incrementalId++)
            ->setParams([$table, $data])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Updates a record inside a table with the given data. When you don't want to overwrite the record, use merge instead.
     * @param string $thing
     * @param mixed $data
     * @return object|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#update
     */
    public function update(string $thing, mixed $data): ?array
    {
        $thing = ThingParser::from($thing)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("update")
            ->setId($this->incrementalId++)
            ->setParams([$thing, $data])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Selectively updates a record inside a table with the given data.
     * @param string $thing
     * @param mixed $data
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#merge
     */
    public function merge(string $thing, mixed $data): ?array
    {
        $thing = ThingParser::from($thing)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("merge")
            ->setId($this->incrementalId++)
            ->setParams([$thing, $data])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Inserts one or multiple records into a table.
     * @param string $table
     * @param array|mixed $data
     * @return array|null
     * @throws CborException|SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#insert
     */
    public function insert(string $table, array $data): ?array
    {
        $table = ThingParser::from($table)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("insert")
            ->setId($this->incrementalId++)
            ->setParams([$table, $data])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Deletes a table or a single record from a table.
     * @param string $thing
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#delete
     */
    public function delete(string $thing): ?array
    {
        $thing = ThingParser::from($thing)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("delete")
            ->setId($this->incrementalId++)
            ->setParams([$thing])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Query a raw SurrealQL query
     * @param string $query
     * @param array $params
     * @return array|null
     * @throws CborException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#query
     */
    public function query(string $query, array $params = []): ?array
    {
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("query")
            ->setId($this->incrementalId++)
            ->setParams([$query, $params])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Patches a specified column inside a record with the given value.
     * @param string $thing
     * @param array<array{op:string,path:string,value:mixed}> $data
     * @param bool $diff
     * @return array|null
     * @throws CborException|SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#patch
     */
    public function patch(string $thing, array $data, bool $diff = false): ?array
    {
        $thing = ThingParser::from($thing)->value;

        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("patch")
            ->setId($this->incrementalId++)
            ->setParams([$thing, $data, $diff])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
    }

    /**
     * Runs a surrealdb function with the given arguments
     * @param string $func
     * @param string|null $version
     * @param mixed ...$args
     * @return mixed
     * @throws CborException|SurrealException|Exception
     */
    public function run(string $func, ?string $version, ...$args): mixed
    {
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_CBOR)
            ->setNamespaceHeader(false)
            ->setDatabaseHeader(false)
            ->setScopeHeader(false)
            ->setAuthorizationHeader()
            ->getHeaders();

        $payload = RpcMessage::create("run")
            ->setId($this->incrementalId++)
            ->setParams([$func, $version, $args])
            ->toCborString();

        $response = $this->execute(
            endpoint: "/rpc",
            method: HttpMethod::POST,
            response: RpcResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        return RpcResult::from($response);
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
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_TEXT)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true);

        if ($username === null && $password === null) {
            $headers = $headers->setAuthorizationHeader();
        }

        $response = $this->execute(
            endpoint: "/ml/import",
            method: HttpMethod::POST,
            response: ImportResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers->getHeaders(),
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
        $headers = HttpHeader::create($this)
            ->setAcceptHeader(HttpHeader::TYPE_CBOR)
            ->setContentTypeHeader(HttpHeader::TYPE_TEXT)
            ->setNamespaceHeader(true)
            ->setDatabaseHeader(true)
            ->getHeaders();

        $response = $this->execute(
            endpoint: "/ml/export/$name/$version",
            method: HttpMethod::GET,
            response: StringResponse::class,
            options: [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERPWD => "$username:$password"
            ]
        );

        return StringResult::from($response);
    }

    /**
     * Closes the client connection.
     * @throws Exception
     */
    public function close(): void
    {
        if ($this->client === null) {
            throw new Exception("The database connection is already closed.");
        }

        $this->auth->setToken(null);

        curl_close($this->client);
        $this->client = null;
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

        $type = $type ? HttpContentType::from($type) : HttpContentType::UTF8;
        $result = ResponseParser::parse($type, $body);

        /** @var $response ResponseInterface */
        return $response::from($result, $type, $status);
    }

    /**
     * Executes a request without expecting a response.
     * uses the health, status endpoints.
     * @throws Exception
     */
    private function checkStatusCode(string $endpoint): int
    {
        $this->baseExecute($endpoint, HttpMethod::GET);
        return curl_getinfo($this->client, CURLINFO_RESPONSE_CODE);
    }
}
