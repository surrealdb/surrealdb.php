<?php

namespace SurrealDB\SDK;

use SurrealDB\SDK\Api\SurrealApi;
use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Contracts\StreamingQueryExecutor;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Query\AuthQuery;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Query\CreateQuery;
use SurrealDB\SDK\Query\DeleteQuery;
use SurrealDB\SDK\Query\InsertQuery;
use SurrealDB\SDK\Query\Query;
use SurrealDB\SDK\Query\RelateQuery;
use SurrealDB\SDK\Query\RunQuery;
use SurrealDB\SDK\Query\SelectQuery;
use SurrealDB\SDK\Query\UpdateQuery;
use SurrealDB\SDK\Query\UpsertQuery;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;

/**
 * Everything that runs queries: raw SurrealQL and the fluent statement
 * builders. Queries run in this object's session, and inside its transaction
 * when it is a {@see SurrealTransaction}.
 *
 * Mirrors the JS SDK's `SurrealQueryable`.
 */
abstract class SurrealQueryable implements StreamingQueryExecutor
{
    public function __construct(
        protected readonly ConnectionController $connection,
        protected readonly ?string $sessionId = null,
    ) {}

    /**
     * The transaction queries run in, or null outside a transaction.
     */
    protected function transactionId(): ?string
    {
        return null;
    }

    // =================================================================== //
    //  Queries                                                            //
    // =================================================================== //

    /**
     * Execute a bound query, returning one result per statement.
     *
     * @return list<mixed>
     */
    public function query(BoundQuery $query): array
    {
        $results = [];

        foreach ($this->connection->query($query, $this->sessionId, $this->transactionId()) as $chunk) {
            $results[] = $chunk->resultOrThrow();
        }

        return $results;
    }

    /**
     * Execute a bound query, yielding each statement's result (or failure)
     * as it is read. Prefer {@see Query::stream()} or {@see Query::responses()}.
     *
     * @return iterable<\SurrealDB\SDK\Protocol\QueryChunk<mixed>>
     */
    public function stream(BoundQuery $query): iterable
    {
        return $this->connection->query($query, $this->sessionId, $this->transactionId());
    }

    /**
     * Wrap raw, possibly multi-statement SurrealQL in a {@see Query}, to
     * `collect()`, `stream()` or inspect its `responses()`:
     *
     *     foreach ($db->statements('SELECT * FROM person; SELECT * FROM post')->stream() as $frame) {
     *         if ($frame instanceof ValueFrame) { ... }
     *     }
     *
     * @param array<string,mixed> $bindings
     *
     * @return Query<mixed>
     */
    public function statements(string $surql, array $bindings = []): Query
    {
        return new Query($this, new BoundQuery($surql, $bindings));
    }

    /**
     * Run an ISO GQL graph query (SurrealDB 3.2 or later), returning one
     * result per statement:
     *
     *     $db->gql('MATCH (a:person)-[:knows]->(b:person) WHERE a.name = $name RETURN b.name AS friend', ['name' => 'Tobie']);
     *
     * @param array<string,mixed> $variables
     *
     * @return list<mixed>
     */
    public function gql(string $query, array $variables = []): array
    {
        $results = [];

        foreach ($this->connection->gql($query, $variables, $this->sessionId, $this->transactionId()) as $chunk) {
            $results[] = $chunk->resultOrThrow();
        }

        return $results;
    }

    /**
     * Execute raw SurrealQL with optional bindings.
     *
     * @param array<string,mixed> $bindings
     *
     * @return list<mixed>
     */
    public function run(string $surql, array $bindings = []): array
    {
        return $this->query(new BoundQuery($surql, $bindings));
    }

    // =================================================================== //
    //  Fluent statement builders                                          //
    // =================================================================== //

    /**
     * Begin a `SELECT` against a table or record. A string is a table name.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     *
     * @return SelectQuery<mixed>
     */
    public function select(RecordId|RecordIdRange|StringRecordId|Table|string $what): SelectQuery
    {
        return new SelectQuery($this, $what);
    }

    /**
     * Begin a `CREATE` for a table or record. A string is a table name. A
     * record range is not a valid `CREATE` target.
     *
     * @param RecordId<string>|StringRecordId|Table<string>|string $what
     *
     * @return CreateQuery<mixed>
     */
    public function create(RecordId|StringRecordId|Table|string $what): CreateQuery
    {
        return new CreateQuery($this, $what);
    }

    /**
     * Begin an `UPDATE` for a table or record. A string is a table name.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     *
     * @return UpdateQuery<mixed>
     */
    public function update(RecordId|RecordIdRange|StringRecordId|Table|string $what): UpdateQuery
    {
        return new UpdateQuery($this, $what);
    }

    /**
     * Begin an `UPSERT` for a table or record. A string is a table name.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     *
     * @return UpsertQuery<mixed>
     */
    public function upsert(RecordId|RecordIdRange|StringRecordId|Table|string $what): UpsertQuery
    {
        return new UpsertQuery($this, $what);
    }

    /**
     * Begin a `DELETE` for a table or record (defaults to `RETURN BEFORE`). A
     * string is a table name.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     *
     * @return DeleteQuery<mixed>
     */
    public function delete(RecordId|RecordIdRange|StringRecordId|Table|string $what): DeleteQuery
    {
        return new DeleteQuery($this, $what);
    }

    /**
     * Begin an `INSERT`. Pass records directly, or a target table plus records.
     *
     * @param Table<string>|array<string,mixed>|list<array<string,mixed>>|object $tableOrData a target table, or the record(s) to insert
     * @param array<string,mixed>|list<array<string,mixed>>|object|null $data
     *
     * @return InsertQuery<mixed>
     */
    public function insert(
        array|object $tableOrData,
        array|object|null $data = null,
    ): InsertQuery {
        if ($tableOrData instanceof Table) {
            return new InsertQuery($this, $tableOrData, $data ?? []);
        }

        return new InsertQuery($this, null, $tableOrData);
    }

    /**
     * Begin a `RELATE`, creating one or many graph edges.
     *
     * @param RecordId<string>|list<RecordId<string>> $from
     * @param RecordId<string>|Table<string> $edge
     * @param RecordId<string>|list<RecordId<string>> $to
     * @param array<string,mixed>|object|null $data
     *
     * @return RelateQuery<mixed>
     */
    public function relate(
        RecordId|array $from,
        RecordId|Table $edge,
        RecordId|array $to,
        array|object|null $data = null,
    ): RelateQuery {
        $relate = new RelateQuery($this, $from, $edge, $to);

        if ($data !== null) {
            $relate->content($data);
        }

        return $relate;
    }

    /**
     * Invoke a SurrealQL/SurrealML function (`fn::*`, `ml::*`, built-ins).
     *
     * Named `call()` because {@see self::run()} already executes raw SurrealQL.
     *
     * @param list<mixed> $args
     *
     * @return RunQuery<mixed>
     */
    public function call(
        string $name,
        ?string $version = null,
        array $args = [],
    ): RunQuery {
        return new RunQuery($this, $name, $version, $args);
    }

    /**
     * Call user-defined API endpoints (`DEFINE API`), optionally under a path
     * prefix: `$db->api()->get('/users')->execute()`.
     */
    public function api(string $prefix = ''): SurrealApi
    {
        $this->connection->assertFeature(Features::api());

        return new SurrealApi($this, $prefix);
    }

    /**
     * Select the currently-authenticated record (`SELECT * FROM ONLY $auth`).
     *
     * @return AuthQuery<mixed>
     */
    public function auth(): AuthQuery
    {
        return new AuthQuery($this);
    }
}
