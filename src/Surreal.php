<?php

namespace Surreal;

use Beau\CborPHP\exceptions\CborException;
use Composer\Semver\Semver;
use Exception;
use Surreal\Cbor\Types\None;
use Surreal\Cbor\Types\RecordId;
use Surreal\Cbor\Types\Table;
use Surreal\Core\AbstractEngine;
use Surreal\Core\Engines\HttpEngine;
use Surreal\Core\Engines\WsEngine;
use Surreal\Core\RpcMessage;
use Surreal\Core\Utils\Helpers;
use Surreal\Exceptions\SurrealException;

final class Surreal
{
    const SUPPORTED_SURREALDB_VERSION_RANGE = ">= 1.4.2 < 2.0.0";

    /**
     * @param AbstractEngine $engine
     * @var AbstractEngine|null
     */
    private ?AbstractEngine $engine;

    /**
     * Use the given namespace and database for the following queries in the current open connection.
     * @param array{namespace:string|null,database:string|null} $target
     * @return None
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#use
     */
    public function use(array $target): None
    {
        $message = RpcMessage::create("use")->setParams(Helpers::parseTarget($target));
        return $this->engine->rpc($message);
    }

    /**
     * Append a new parameter to the current session.
     * @param string $name
     * @param mixed $value
     * @return null
     * @throws CborException
     * @throws SurrealException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#let
     */
    public function let(string $name, mixed $value): null
    {
        $message = RpcMessage::create("let")->setParams([$name, $value]);
        return $this->engine->rpc($message);
    }

    /**
     * Unset a parameter from the current session.
     * @param string $name
     * @return null
     * @throws CborException
     * @throws SurrealException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#unset
     */
    public function unset(string $name): null
    {
        $message = RpcMessage::create("unset")->setParams([$name]);
        return $this->engine->rpc($message);
    }

    /**
     * Returns auth information of the current session
     * @returns array
     * @throws Exception|SurrealException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#info
     */
    public function info(): None|array
    {
        $message = RpcMessage::create("info");
        return $this->engine->rpc($message);
    }

    /**
     * Returns the version of the remote surreal database.
     * @return string
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#version
     */
    public function version(): string
    {
        $message = RpcMessage::create("version");
        return $this->engine->rpc($message);
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
        $message = RpcMessage::create("query")->setParams([$query, $params]);
        return $this->engine->rpc($message);
    }

    /**
     * Selects a record or the whole table.
     * @param RecordId|string $thing
     * @return mixed
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#select
     */
    public function select(RecordId|string $thing): mixed
    {
        $message = RpcMessage::create("select")->setParams([$thing]);
        return $this->engine->rpc($message);
    }

    /**
     * Creates a new record in a table.
     * @param RecordId|string $thing
     * @param mixed $data
     * @return object|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#create
     */
    public function create(RecordId|string $thing, mixed $data): ?array
    {
        $message = RpcMessage::create("create")->setParams([$thing, $data]);
        return $this->engine->rpc($message);
    }

    /**
     * Reads a record from a table.
     * @param RecordId|string $thing
     * @param mixed $data
     * @return array|null
     * @throws SurrealException
     * @throws CborException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#read
     */
    public function update(RecordId|string $thing, mixed $data): ?array
    {
        $message = RpcMessage::create("update")->setParams([$thing, $data]);
        return $this->engine->rpc($message);
    }

    /**
     * Selectively updates a record inside a table with the given data.
     * @param RecordId|string $thing
     * @param mixed $data
     * @return array|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#merge
     */
    public function merge(RecordId|string $thing, mixed $data): ?array
    {
        $message = RpcMessage::create("merge")->setParams([$thing, $data]);
        return $this->engine->rpc($message);
    }

    /**
     * Patches a specified column inside a record with the given value.
     * @param RecordId|string $thing
     * @param array<array{op:string,path:string,value:mixed}> $data
     * @param bool $diff
     * @return array|null
     * @throws CborException|SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#patch
     */
    public function patch(RecordId|string $thing, array $data, bool $diff = false): ?array
    {
        $message = RpcMessage::create("patch")->setParams([$thing, $data, $diff]);
        return $this->engine->rpc($message);
    }

    /**
     * Inserts one or multiple records into a table.
     * @param string $table
     * @param array $data
     * @return array|null
     * @throws CborException|SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#insert
     */
    public function insert(string $table, array $data): ?array
    {
        $message = RpcMessage::create("insert")->setParams([$table, $data]);
        return $this->engine->rpc($message);
    }

    /**
     * Deletes a record from a table.
     * @param RecordId|string $thing
     * @return array|null
     * @throws CborException|SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#delete
     */
    public function delete(RecordId|string $thing): ?array
    {
        $message = RpcMessage::create("delete")->setParams([$thing]);
        return $this->engine->rpc($message);
    }

    /**
     * Signin with a root, namespace, database or scoped user.
     * @param array{namespace:string|null,database:string|null,scope:string|null} $data
     * @return string|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#signin
     */
    public function signin(array $data): ?string
    {
        $data = Helpers::processAuthVariables($data);
        $message = RpcMessage::create("signin")->setParams([$data]);
        return $this->engine->rpc($message);
    }

    /**
     * Signup a new scoped user.
     * @param array{namespace:string|null,database:string|null,scope:string|null} $data
     * @return string|null
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#signup
     */
    public function signup(array $data): ?string
    {
        $message = RpcMessage::create("signup")->setParams([$data]);
        return $this->engine->rpc($message);
    }

    /**
     * Create a relation between two records. The data parameter is optional.
     * @param RecordId|string $from
     * @param Table|string $table
     * @param RecordId|string $to
     * @param array|null $data
     * @return array{id:RecordId, in:RecordId, out:RecordId}|null
     * @since SurrealDB-v1.5.0
     */
    public function relate(RecordId|string $from, Table|string $table, RecordId|string $to, ?array $data = null): ?array
    {
        $message = RpcMessage::create("relate")->setParams([$from, $table, $to, $data]);
        return $this->engine->rpc($message)[0];
    }

    /**
     * Runs a defined SurrealQL function.
     * @param string $function
     * @param string|null $version
     * @param array|null $params
     * @return mixed
     * @since SurrealDB-v1.5.0
     */
    public function run(string $function, ?string $version = null, ?array $params = null): mixed
    {
        $message = RpcMessage::create("run")->setParams([$function, $version, $params]);
        return $this->engine->rpc($message);
    }

    /**
     * Authenticate the current session with a token.
     * @param string|null $token
     * @return string|None
     * @throws CborException
     * @throws SurrealException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#authenticate
     */
    public function authenticate(?string $token): string|None
    {
        $message = RpcMessage::create("authenticate")->setParams([$token]);
        return $this->engine->rpc($message);
    }

    /**
     * This method will invalidate the user's session for the current connection
     * @return None
     * @throws CborException
     * @throws SurrealException
     * @see https://surrealdb.com/docs/surrealdb/integration/rpc#invalidate
     */
    public function invalidate(): None
    {
        $message = RpcMessage::create("invalidate");
        return $this->engine->rpc($message);
    }

    /**
     * Makes the current session invalid
     * This method is only supported for HTTP connections.
     * @param string $content - content inside a .surql file.
     * @param string $username
     * @param string $password
     * @return array|null - Array of SingleRecordResponse
     * @throws SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#import
     */
    public function import(string $content, string $username, string $password): ?array
    {
        if ($this->engine instanceof HttpEngine) {
            return $this->engine->import($content, $username, $password);
        }

        throw new Exception("Import is only supported for HTTP connections.");
    }

    /**
     * Returns an exported content of the current selected database as string.
     * This method is only supported for HTTP connections.
     * @param string $username
     * @param string $password
     * @return string - exported content
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#export
     */
    public function export(string $username, string $password): string
    {
        if ($this->engine instanceof HttpEngine) {
            return $this->engine->export($username, $password);
        }

        throw new Exception("Export is only supported for HTTP connections.");
    }

    /**
     * Import a machine learning model into the database. When username and password aren't provided.
     * It uses the token from the current session. This method is only supported for HTTP connections.
     * @param string $content - content inside a .surml file.
     * @param string|null $username
     * @param string|null $password
     * @return mixed
     * @throws SurrealException|Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#ml-import
     */
    public function importML(string $content, ?string $username = null, ?string $password = null): mixed
    {
        if ($this->engine instanceof HttpEngine) {
            return $this->engine->importML($content, $username, $password);
        }

        throw new Exception("ML Import is only supported for HTTP connections.");
    }

    /**
     * Export a machine learning model from the database.
     * This method is only supported for HTTP connections.
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
        if ($this->engine instanceof HttpEngine) {
            return $this->engine->exportML($name, $version, $username, $password);
        }

        throw new Exception("ML Export is only supported for HTTP connections.");
    }

    /**
     * Returns the status code of the current connection.
     * @return int
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#status
     */
    public function status(): int
    {
        if ($this->engine instanceof HttpEngine) {
            return $this->engine->status();
        } else if ($this->engine instanceof WsEngine) {
            return $this->engine->isConnected() ? 200 : 500;
        }

        return 500;
    }

    /**
     * This HTTP RESTfull endpoint checks whether the database server and storage engine are running.
     * The endpoint returns a 200 status code on success and a 500 status code on failure.
     * @return int - status code
     * @throws Exception
     * @see https://surrealdb.com/docs/surrealdb/integration/http#health
     */
    public function health(): int
    {
        if ($this->engine instanceof HttpEngine) {
            return $this->engine->health();
        }

        throw new Exception("Health check is only supported for HTTP connections.");
    }

    /**
     * Connect to the remote Surreal database. Throws an error if the connection fails.
     * @param string $host
     * @param array{
     *     namespace:string|null,
     *     database:string|null,
     *     versionCheck:bool|null
     * }|null $options
     * @return void
     * @throws Exception
     */
    public function connect(string $host, ?array $options = null): void
    {
        $this->engine = match (parse_url($host, PHP_URL_SCHEME)) {
            "http", "https" => new HttpEngine($host),
            "ws", "wss" => new WsEngine($host),
            default => throw new Exception("Unsupported protocol"),
        };

        $this->engine->connect();

        if ($options) {
            $this->use($options);
        }

        if(!array_key_exists("versionCheck", $options) || $options["versionCheck"] !== false) {
            $versionRange = Surreal::SUPPORTED_SURREALDB_VERSION_RANGE;
            $version = $this->version();

            // remove the prefix "surrealdb-" from the version
            $version = str_replace("surrealdb-", "", $version);

            if (!Semver::satisfies($version, $versionRange)) {
                throw new Exception("Unsupported SurrealDB version. Supported version range: $versionRange");
            }
        }
    }

    /**
     * Closes the connection.
     * @return bool
     */
    public function disconnect(): bool
    {
        return $this->engine->disconnect();
    }

    /**
     * Set the timeout for the requests in seconds.
     * @param int $seconds
     * @return void
     */
    public function setTimeout(int $seconds): void
    {
        $this->engine->setTimeout($seconds);
    }

    /**
     * Retrieve the current timeout for the requests in seconds.
     * @return int - seconds
     */
    public function getTimeout(): int
    {
        return $this->engine->getTimeout();
    }
}