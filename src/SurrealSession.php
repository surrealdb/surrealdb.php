<?php

namespace SurrealDB\SDK;

use SurrealDB\SDK\Auth\Credentials;
use SurrealDB\SDK\Auth\Token;
use SurrealDB\SDK\Auth\Tokens;
use SurrealDB\SDK\Exceptions\InvalidSessionException;
use SurrealDB\SDK\GraphQl\GraphQlResponse;
use SurrealDB\SDK\Live\LiveSubscription;
use SurrealDB\SDK\Live\UnmanagedLiveSubscription;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Protocol\NamespaceDatabase;
use SurrealDB\SDK\Query\LiveSelectQuery;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;

/**
 * A session on the server: its own namespace and database, variables,
 * authentication and live queries, with every query builder targeting it.
 *
 * {@see Surreal} is the default (root) session. Additional sessions come from
 * {@see Surreal::newSession()} or {@see self::forkSession()}, and transactions
 * from {@see self::beginTransaction()}. Sessions require a WebSocket
 * connection to SurrealDB 3.0 or later.
 *
 * Mirrors the JS SDK's `SurrealSession`.
 */
class SurrealSession extends SurrealQueryable
{
    /**
     * The session id, or null for the default session.
     */
    public function id(): ?string
    {
        return $this->sessionId;
    }

    /** The selected namespace, if any. */
    public function namespace(): ?string
    {
        return $this->connection->state()?->session($this->sessionId)?->namespace;
    }

    /** The selected database, if any. */
    public function database(): ?string
    {
        return $this->connection->state()?->session($this->sessionId)?->database;
    }

    /** The current access token, if authenticated. */
    public function accessToken(): ?string
    {
        return $this->connection->state()?->session($this->sessionId)?->auth->accessToken;
    }

    /**
     * Whether the session still exists: it has not been closed, and the
     * connection that owns it is still open.
     */
    public function isValid(): bool
    {
        return $this->connection->state()?->session($this->sessionId) !== null;
    }

    /**
     * Create a new session with a copy of this one's namespace, database,
     * variables and authentication.
     */
    public function forkSession(): SurrealSession
    {
        return new SurrealSession($this->connection, $this->connection->forkSession($this->sessionId));
    }

    /**
     * Close this session on the server. The default session cannot be closed;
     * close the connection instead.
     */
    public function closeSession(): void
    {
        if ($this->sessionId === null) {
            throw new InvalidSessionException(null);
        }

        $this->connection->destroySession($this->sessionId);
    }

    /**
     * Start an interactive transaction in this session. Queries made through
     * the returned object run inside it until it is committed or cancelled.
     */
    public function beginTransaction(): SurrealTransaction
    {
        return new SurrealTransaction(
            $this->connection,
            $this->sessionId,
            $this->connection->begin($this->sessionId),
        );
    }

    /**
     * Run a GraphQL request against the selected namespace and database
     * (SurrealDB 3.2 or later; the database needs `DEFINE CONFIG GRAPHQL`).
     * GraphQL runs its own transactions, so it is not offered on a
     * {@see SurrealTransaction}.
     *
     * @param array<string,mixed> $variables
     */
    public function graphql(string $query, array $variables = [], ?string $operationName = null): GraphQlResponse
    {
        return GraphQlResponse::fromResult(
            $this->connection->graphql($query, $variables, $operationName, $this->sessionId),
        );
    }

    /**
     * Define a session variable (the JS SDK's `set`).
     */
    public function set(string $name, mixed $value): void
    {
        $this->let($name, $value);
    }

    /**
     * Clear the selected namespace and database, the variables and the
     * authentication of this session.
     */
    public function reset(): void
    {
        $this->connection->reset($this->sessionId);
    }

    // =================================================================== //
    //  Session                                                            //
    // =================================================================== //

    public function use(?string $namespace, ?string $database = null): void
    {
        $this->connection->use(new NamespaceDatabase($namespace, $database), $this->sessionId);
    }

    public function let(string $name, mixed $value): void
    {
        $this->connection->set($name, $value, $this->sessionId);
    }

    public function unset(string $name): void
    {
        $this->connection->unset($name, $this->sessionId);
    }

    // =================================================================== //
    //  Authentication                                                     //
    // =================================================================== //

    /**
     * @param Credentials|array<string,mixed> $auth
     */
    public function signin(Credentials|array $auth): Tokens
    {
        return $this->connection->signin(
            $auth instanceof Credentials ? $auth->toArray() : $auth,
            $this->sessionId,
        );
    }

    /**
     * @param Credentials|array<string,mixed> $auth
     */
    public function signup(Credentials|array $auth): Tokens
    {
        return $this->connection->signup(
            $auth instanceof Credentials ? $auth->toArray() : $auth,
            $this->sessionId,
        );
    }

    public function authenticate(Token|string $token): void
    {
        $this->connection->authenticate((string) $token, $this->sessionId);
    }

    public function invalidate(): void
    {
        $this->connection->invalidate($this->sessionId);
    }

    /**
     * Start a managed live query (`LIVE SELECT`) on a table or record. Configure
     * it, then `execute()` it to get a subscription to iterate:
     *
     *     $live = $db->live(new Table('person'))->where('age > 18')->execute();
     *     foreach ($live as $message) { ... }
     *
     * A string is a table name.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     */
    public function live(RecordId|RecordIdRange|StringRecordId|Table|string $what): LiveSelectQuery
    {
        return new LiveSelectQuery($this->connection, $what, $this->sessionId);
    }

    /**
     * Subscribe to an existing live query by its id, e.g. one started by a
     * `LIVE SELECT` inside {@see self::run()}.
     */
    public function liveOf(Uuid|string $id): UnmanagedLiveSubscription
    {
        $this->connection->assertFeature(Features::liveQueries());

        return new UnmanagedLiveSubscription($this->connection, $id, $this->sessionId);
    }

    /**
     * Stop a live query by its id.
     */
    public function kill(Uuid|string $id): void
    {
        LiveSubscription::killOnServer($this->connection, $id, $this->sessionId);
    }
}
