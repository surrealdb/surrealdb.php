<?php

namespace SurrealDB\Contracts;

use SurrealDB\Auth\Tokens;
use SurrealDB\Connection\ConnectionState;
use SurrealDB\Live\LiveMessage;
use SurrealDB\Protocol\FeatureSet;
use SurrealDB\Protocol\NamespaceDatabase;
use SurrealDB\Protocol\QueryChunk;
use SurrealDB\Protocol\VersionInfo;
use SurrealDB\Query\BoundQuery;

/**
 * The communication contract between the SDK and a SurrealDB datastore.
 *
 * Combines the protocol operations (the SurrealDB RPC surface) with the engine
 * lifecycle (`open`/`close`/`ready`), feature discovery, and lifecycle event
 * subscription. Mirrors the JS SDK `SurrealEngine` (which composes
 * `SurrealProtocol` and an event publisher).
 */
interface EngineInterface
{
    /** Features supported by this engine implementation. */
    public function features(): FeatureSet;

    // ----------------------------------------------------------------- //
    //  Lifecycle                                                         //
    // ----------------------------------------------------------------- //

    public function open(ConnectionState $state): void;

    public function close(): void;

    /** Called once sessions are restored so any queued calls may be flushed. */
    public function ready(): void;

    /**
     * Subscribe to a lifecycle event: `connected`, `reconnecting`,
     * `disconnected`, or `error`.
     *
     * @return \Closure the unsubscribe callback
     */
    public function subscribe(string $event, callable $listener): \Closure;

    // ----------------------------------------------------------------- //
    //  Connection operations                                             //
    // ----------------------------------------------------------------- //

    public function health(): void;

    public function version(): VersionInfo;

    /** @return list<string> */
    public function sessions(): array;

    public function attach(string $session): void;

    public function detach(string $session): void;

    // ----------------------------------------------------------------- //
    //  Session operations                                                //
    // ----------------------------------------------------------------- //

    public function use(?NamespaceDatabase $what, ?string $session): NamespaceDatabase;

    /** @param array<string,mixed> $auth */
    public function signup(array $auth, ?string $session): Tokens;

    /** @param array<string,mixed> $auth */
    public function signin(array $auth, ?string $session): Tokens;

    public function authenticate(string $token, ?string $session): void;

    public function set(string $name, mixed $value, ?string $session): void;

    public function unset(string $name, ?string $session): void;

    public function refresh(Tokens $tokens, ?string $session): Tokens;

    public function revoke(Tokens $tokens, ?string $session): void;

    public function invalidate(?string $session): void;

    public function reset(?string $session): void;

    // ----------------------------------------------------------------- //
    //  Transaction operations                                            //
    // ----------------------------------------------------------------- //

    public function begin(?string $session): string;

    public function commit(string $txn, ?string $session): void;

    public function cancel(string $txn, ?string $session): void;

    // ----------------------------------------------------------------- //
    //  Data management operations                                        //
    // ----------------------------------------------------------------- //

    public function importSql(string $data): void;

    /** @param array<string,mixed> $options */
    public function exportSql(array $options): string;

    // ----------------------------------------------------------------- //
    //  Query operations                                                  //
    // ----------------------------------------------------------------- //

    /** @return iterable<QueryChunk<mixed>> */
    public function query(BoundQuery $query, ?string $session, ?string $txn = null): iterable;

    /** @return iterable<LiveMessage<mixed>> */
    public function liveQuery(string $id): iterable;
}
