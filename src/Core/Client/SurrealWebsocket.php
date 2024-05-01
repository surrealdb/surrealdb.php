<?php

namespace Surreal\Core\Client;

use Exception;
use Surreal\Cbor\CBOR;
use Surreal\Core\AbstractSurreal;
use Surreal\Core\Responses\Types\RpcResponse;
use Surreal\Core\Results\RpcResult;
use Surreal\Core\Rpc\RpcMessage;
use Surreal\Core\Utils\ThingParser;
use Surreal\Curl\HttpContentType;
use WebSocket\Client as WebsocketClient;
use WebSocket\Middleware\{CloseHandler, PingResponder};

class SurrealWebsocket extends AbstractSurreal
{
    private WebsocketClient $client;
    private int $incrementalId = 0;

    /**
     * @param string $host
     * @param array{namespace:string, database:string|null} $target
     * @throws Exception
     */
    public function __construct(
        string $host,
        array  $target = []
    )
    {
        $this->client = (new WebsocketClient($host))
            ->addMiddleware(new CloseHandler())
            ->addMiddleware(new PingResponder())
            ->addHeader("Sec-WebSocket-Protocol", "cbor")
            ->setTimeout(5);

        $this->client->connect();

        $this->use($target);

        parent::__construct($host, $target);
    }

    /**
     * Set the namespace and database to be used for the following queries in the current open connection.
     * @param array{namespace:string|null,database:string|null} $target
     * @return null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#use
     */
    public function use(array $target): null
    {
        // if this throws exception, the code after it will not run
        // So we are ensuring that the namespace and database are set correctly.
        $message = RpcMessage::create("use")->setParams([$target["namespace"], $target["database"]]);
        $result = $this->execute($message);

        parent::use($target);

        return $result;
    }

    /**
     * Check if the websocket connection is open.
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    /**
     * Set the timeout for the websocket connection in seconds.
     * @param int $seconds
     * @return void
     */
    public function setTimeout(int $seconds): void
    {
        $this->client->setTimeout($seconds);
    }

    /**
     * Set a new parameter.
     * @param string $param
     * @param string $value
     * @return null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#let
     */
    public function let(string $param, string $value): null
    {
        $message = RpcMessage::create("let")->setParams([$param, $value]);
        return $this->execute($message);
    }

    /**
     * Dismisses a previously set parameter
     * @param string $param
     * @return null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#unset
     */
    public function unset(string $param): null
    {
        $message = RpcMessage::create("unset")->setParams([$param]);
        return $this->execute($message);
    }

    /**
     * Query a raw SurrealQL query
     * @param string $sql
     * @param array|null $vars
     * @return mixed
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#query
     */
    public function query(string $sql, ?array $vars = null): mixed
    {
        $message = RpcMessage::create("query")->setParams([$sql, $vars]);
        return $this->execute($message);
    }

    /**
     * Signin with a root, namespace, database or scoped user.
     * @param array{NS:string|null,DB:string|null,SC:string|null} $data
     * @return string|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#signin
     */
    public function signin(array $data): ?string
    {
        $message = RpcMessage::create("signin")->setParams([$data]);
        return $this->execute($message);
    }

    /**
     * Signup a new scoped user
     * @param array{NS:string,DB:string,SC:string} $data
     * @return string|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#signup
     */
    public function signup(array $data): ?string
    {
        $message = RpcMessage::create("signup")->setParams([$data]);
        return $this->execute($message);
    }

    /**
     * Authenticates the current session with the given token
     * @param string $token
     * @return null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#authenticate
     */
    public function authenticate(string $token): null
    {
        $message = RpcMessage::create("authenticate")->setParams([$token]);
        return $this->execute($message);
    }

    /**
     * Returns auth information of the current session
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#info
     */
    public function info(): ?array
    {
        $message = RpcMessage::create("info");
        return $this->execute($message);
    }

    /**
     * Makes the current session invalid
     * @return null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#invalidate
     */
    public function invalidate(): null
    {
        $message = RpcMessage::create("invalidate");
        return $this->execute($message);
    }

    /**
     * Select a whole table or a single record from a table
     * @param string $thing
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#select
     */
    public function select(string $thing): ?array
    {
        $thing = ThingParser::from($thing)->value;
        $message = RpcMessage::create("select")->setParams([$thing]);
        return $this->execute($message);
    }

    /**
     * Inserts one or multiple records into a table
     * @param string $table
     * @param array $data
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#insert
     */
    public function insert(string $table, array $data): ?array
    {
        $table = ThingParser::from($table)->getTable();
        $message = RpcMessage::create("insert")->setParams([$table, $data]);
        return $this->execute($message);
    }

    /**
     * Creates a new record inside a table with the given data
     * @param string $thing
     * @param array $data
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#create
     */
    public function create(string $thing, array $data): ?array
    {
        $thing = ThingParser::from($thing)->value;
        $message = RpcMessage::create("create")->setParams([$thing, $data]);
        return $this->execute($message);
    }

    /**
     * Updates a record inside a table with the given data. When you don't want to overwrite the record, use merge instead.
     * @param string $thing
     * @param array $data
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#update
     */
    public function update(string $thing, array $data): ?array
    {
        $thing = ThingParser::from($thing)->value;
        $message = RpcMessage::create("update")->setParams([$thing, $data]);
        return $this->execute($message);
    }

    /**
     * Selectively updates a record inside a table with the given data.
     * @param string $thing
     * @param array $data
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#merge
     */
    public function merge(string $thing, array $data): ?array
    {
        $thing = ThingParser::from($thing)->value;
        $message = RpcMessage::create("merge")->setParams([$thing, $data]);
        return $this->execute($message);
    }

    /**
     * Patches a specified column inside a record with the given value.
     * @param string $thing
     * @param array<array{op:string,path:string,value:mixed}> $data
     * @param bool $diff
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#patch
     */
    public function patch(string $thing, array $data, bool $diff = false): ?array
    {
        $thing = ThingParser::from($thing)->value;
        $message = RpcMessage::create("patch")->setParams([$thing, $data, $diff]);
        return $this->execute($message);
    }

    /**
     * Removes a table or a single record from a table
     * @param string $thing
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#delete
     */
    public function delete(string $thing): ?array
    {
        $thing = ThingParser::from($thing)->value;
        $message = RpcMessage::create("delete")->setParams([$thing]);
        return $this->execute($message);
    }

    /**
     * Runs a surrealdb function with the given arguments
     * @param string $func
     * @param string|null $version
     * @param mixed ...$args
     * @return mixed
     * @throws Exception
     */
    public function run(string $func, ?string $version, ...$args): mixed
    {
        $message = RpcMessage::create("run")->setParams([$func, $version, $args]);
        return $this->execute($message);
    }

    /**
     * Closes the websocket connection
     * @return void
     */
    public function close(): void
    {
        $this->client->close();
    }

    /**
     * Executes the given message and returns the result
     * @param RpcMessage $message
     * @return mixed
     * @throws Exception
     */
    private function execute(RpcMessage $message): mixed
    {
        $id = $this->incrementalId++;
        $payload = $message->setId($id)->toCborString();

        $this->client->binary($payload);

        // This reads the response from the websocket
        // Blocking the main thread until the response is received.
        // This ensures that the response is received in the order it was sent.

        while ($result = $this->client->receive()) {
            $content = $result->getContent();

            if ($content === "") {
                continue;
            }

            $content = CBOR::decode($content);

            if ($content["id"] === $id) {
                $response = RpcResponse::from($content, HttpContentType::CBOR, 200);
                return RpcResult::from($response);
            }
        }

        throw new Exception("No response received");
    }

    /**
     * Get the timeout for the websocket connection in seconds.
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->client->getTimeout();
    }
}