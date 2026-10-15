<?php

namespace SurrealDB\SDK;

use Psr\Log\NullLogger;
use SurrealDB\SDK\Api\SurrealApi;
use SurrealDB\SDK\Auth\Credentials;
use SurrealDB\SDK\Auth\Token;
use SurrealDB\SDK\Auth\Tokens;
use SurrealDB\SDK\Codec\Codec;
use SurrealDB\SDK\Codec\CborDeserializer;
use SurrealDB\SDK\Codec\CborSerializer;
use SurrealDB\SDK\Codec\JsonDeserializer;
use SurrealDB\SDK\Codec\JsonSerializer;
use SurrealDB\SDK\Connection\ConnectionController;
use SurrealDB\SDK\Connection\ConnectionStatus;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Connection\Endpoint;
use SurrealDB\SDK\Contracts\QueryExecutor;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Events\EventDispatcher;
use SurrealDB\SDK\Exceptions\ConfigurationException;
use SurrealDB\SDK\Exceptions\UnavailableFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedFeatureException;
use SurrealDB\SDK\Live\LiveSubscription;
use SurrealDB\SDK\Live\UnmanagedLiveSubscription;
use SurrealDB\SDK\Protocol\Feature;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Protocol\NamespaceDatabase;
use SurrealDB\SDK\Query\AuthQuery;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Query\CreateQuery;
use SurrealDB\SDK\Query\DeleteQuery;
use SurrealDB\SDK\Query\InsertQuery;
use SurrealDB\SDK\Query\LiveSelectQuery;
use SurrealDB\SDK\Query\RelateQuery;
use SurrealDB\SDK\Query\RunQuery;
use SurrealDB\SDK\Query\SelectQuery;
use SurrealDB\SDK\Query\UpdateQuery;
use SurrealDB\SDK\Query\UpsertQuery;
use SurrealDB\SDK\Scheduler\SyncScheduler;
use SurrealDB\SDK\Telemetry\NullMeter;
use SurrealDB\SDK\Telemetry\NullTracer;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\RecordIdRange;
use SurrealDB\SDK\Types\StringRecordId;
use SurrealDB\SDK\Types\Table;
use SurrealDB\SDK\Types\Uuid;

/**
 * The primary entry point: connect to SurrealDB, manage the session, and run
 * queries. Connection orchestration is delegated to the {@see ConnectionController};
 * the fluent query builders (companion plan) execute through the
 * {@see QueryExecutor} contract this class implements.
 */
final class Surreal implements QueryExecutor
{
	private readonly ConnectionController $connection;

	public function __construct(?DriverOptions $options = null)
	{
		$this->connection = new ConnectionController(
			self::buildContext($options ?? new DriverOptions()),
		);
	}

	// =================================================================== //
	//  Connection                                                         //
	// =================================================================== //

	public function connect(
		string|Endpoint $url,
		?ConnectOptions $options = null,
	): void {
		$endpoint = $url instanceof Endpoint ? $url : Endpoint::parse($url);

		$this->connection->connect($endpoint, $options ?? new ConnectOptions());
	}

	public function close(): void
	{
		$this->connection->disconnect();
	}

	public function status(): ConnectionStatus
	{
		return $this->connection->status();
	}

	public function isConnected(): bool
	{
		return $this->connection->status() === ConnectionStatus::Connected;
	}

	public function health(): void
	{
		$this->connection->health();
	}

	public function version(): string
	{
		return $this->connection->version()->version;
	}

	public function isFeatureSupported(Feature $feature): bool
	{
		try {
			$this->connection->assertFeature($feature);

			return true;
		} catch (UnsupportedFeatureException | UnavailableFeatureException) {
			return false;
		}
	}

	/**
	 * Subscribe to a high-level connection event: `connecting`, `connected`,
	 * `reconnecting`, `disconnected`, `error`, `auth`, or `using`.
	 *
	 * @return \Closure the unsubscribe callback
	 */
	public function subscribe(string $event, callable $listener): \Closure
	{
		return $this->connection->subscribe($event, $listener);
	}

	// =================================================================== //
	//  Session                                                            //
	// =================================================================== //

	public function use(?string $namespace, ?string $database = null): void
	{
		$this->connection->use(new NamespaceDatabase($namespace, $database));
	}

	public function let(string $name, mixed $value): void
	{
		$this->connection->set($name, $value);
	}

	public function unset(string $name): void
	{
		$this->connection->unset($name);
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
		);
	}

	/**
	 * @param Credentials|array<string,mixed> $auth
	 */
	public function signup(Credentials|array $auth): Tokens
	{
		return $this->connection->signup(
			$auth instanceof Credentials ? $auth->toArray() : $auth,
		);
	}

	public function authenticate(Token|string $token): void
	{
		$this->connection->authenticate((string) $token);
	}

	public function invalidate(): void
	{
		$this->connection->invalidate();
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

		foreach ($this->connection->query($query) as $chunk) {
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

	/**
	 * Import SurrealQL through the HTTP `/import` endpoint (also when connected
	 * over WebSocket). SurrealDB 3.x requires the script to begin with
	 * `OPTION IMPORT;`, as {@see self::export()} output does.
	 *
	 * @throws \SurrealDB\SDK\Exceptions\ServerException for the first statement that failed
	 * @throws \SurrealDB\SDK\Exceptions\HttpConnectionException when the server rejected the import
	 */
	public function import(string $surql): void
	{
		$this->connection->importSql($surql);
	}

	/**
	 * Export the selected database as SurrealQL through the HTTP `/export`
	 * endpoint.
	 *
	 * @param array<string,mixed> $options export options, e.g. `['tables' => ['person'], 'records' => false]`
	 */
	public function export(array $options = []): string
	{
		return $this->connection->exportSql($options);
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
	 * Begin a `CREATE` for a table or record. A string is a table name.
     *
     * @param RecordId<string>|RecordIdRange<string>|StringRecordId|Table<string>|string $what
     *
     * @return CreateQuery<mixed>
	 */
	public function create(RecordId|RecordIdRange|StringRecordId|Table|string $what): CreateQuery
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
		return new LiveSelectQuery($this->connection, $what);
	}

	/**
	 * Subscribe to an existing live query by its id, e.g. one started by a
	 * `LIVE SELECT` inside {@see self::run()}.
	 */
	public function liveOf(Uuid|string $id): UnmanagedLiveSubscription
	{
		$this->connection->assertFeature(Features::liveQueries());

		return new UnmanagedLiveSubscription($this->connection, $id);
	}

	/**
	 * Stop a live query by its id.
	 */
	public function kill(Uuid|string $id): void
	{
		LiveSubscription::killOnServer($this->connection, $id);
	}

	/**
	 * Access the underlying controller for advanced operations (sessions,
	 * transactions, import/export).
	 */
	public function connection(): ConnectionController
	{
		return $this->connection;
	}

	private static function buildContext(DriverOptions $options): DriverContext
	{
		$counter = 0;
        $codec = self::resolveCodec($options);

		return new DriverContext(
			options: $options,
			codec: $codec,
			format: $options->format,
			events: $options->events ?? new EventDispatcher(),
			logger: $options->logger ?? new NullLogger(),
			scheduler: $options->scheduler ?? new SyncScheduler(),
			// By reference: an arrow function would capture a copy of the
			// counter, and every request would get id 1.
			uniqueId: static function () use (&$counter): string {
				return (string) ++$counter;
			},
			tracer: $options->tracer ?? new NullTracer(),
			meter: $options->meter ?? new NullMeter(),
		);
	}

    private static function resolveCodec(DriverOptions $options): Codec
    {
        $codec = $options->codec ?? match ($options->format) {
            CodecEnum::CBOR => Codec::cbor(),
            CodecEnum::JSON => Codec::json(),
            default => Codec::json(),
        };

        if ($options->format === CodecEnum::CBOR
            && ($codec->serializer instanceof JsonSerializer || $codec->deserializer instanceof JsonDeserializer)) {
            throw new ConfigurationException(
                'Configuration',
                'DriverOptions format CBOR requires a CBOR serializer and deserializer.',
            );
        }

        if ($options->format === CodecEnum::JSON
            && ($codec->serializer instanceof CborSerializer || $codec->deserializer instanceof CborDeserializer)) {
            throw new ConfigurationException(
                'Configuration',
                'DriverOptions format JSON cannot use a CBOR serializer or deserializer.',
            );
        }

        return $codec;
    }
}
