<?php

namespace SurrealDB;

use Psr\Log\NullLogger;
use SurrealDB\Auth\Credentials;
use SurrealDB\Auth\Token;
use SurrealDB\Auth\Tokens;
use SurrealDB\Codec\Codec;
use SurrealDB\Codec\CborDeserializer;
use SurrealDB\Codec\CborSerializer;
use SurrealDB\Codec\JsonDeserializer;
use SurrealDB\Codec\JsonSerializer;
use SurrealDB\Connection\ConnectionController;
use SurrealDB\Connection\ConnectionStatus;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Connection\Endpoint;
use SurrealDB\Contracts\QueryExecutor;
use SurrealDB\Enum\CodecEnum;
use SurrealDB\Events\EventDispatcher;
use SurrealDB\Exceptions\ConfigurationException;
use SurrealDB\Exceptions\UnavailableFeatureException;
use SurrealDB\Exceptions\UnsupportedFeatureException;
use SurrealDB\Live\LiveMessage;
use SurrealDB\Protocol\Feature;
use SurrealDB\Protocol\NamespaceDatabase;
use SurrealDB\Query\AuthQuery;
use SurrealDB\Query\BoundQuery;
use SurrealDB\Query\CreateQuery;
use SurrealDB\Query\DeleteQuery;
use SurrealDB\Query\InsertQuery;
use SurrealDB\Query\RelateQuery;
use SurrealDB\Query\RunQuery;
use SurrealDB\Query\SelectQuery;
use SurrealDB\Query\UpdateQuery;
use SurrealDB\Query\UpsertQuery;
use SurrealDB\Scheduler\SyncScheduler;
use SurrealDB\Telemetry\NullMeter;
use SurrealDB\Telemetry\NullTracer;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;

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

	// =================================================================== //
	//  Fluent statement builders                                          //
	// =================================================================== //

	/**
	 * Begin a `SELECT` against a table, record, or raw target.
     *
     * @param RecordId<string>|Table<string>|string $what
     *
     * @return SelectQuery<mixed>
	 */
	public function select(RecordId|Table|string $what): SelectQuery
	{
		return new SelectQuery($this, $what);
	}

	/**
	 * Begin a `CREATE` for a table or record.
     *
     * @param RecordId<string>|Table<string>|string $what
     *
     * @return CreateQuery<mixed>
	 */
	public function create(RecordId|Table|string $what): CreateQuery
	{
		return new CreateQuery($this, $what);
	}

	/**
	 * Begin an `UPDATE` for a table or record.
     *
     * @param RecordId<string>|Table<string>|string $what
     *
     * @return UpdateQuery<mixed>
	 */
	public function update(RecordId|Table|string $what): UpdateQuery
	{
		return new UpdateQuery($this, $what);
	}

	/**
	 * Begin an `UPSERT` for a table or record.
     *
     * @param RecordId<string>|Table<string>|string $what
     *
     * @return UpsertQuery<mixed>
	 */
	public function upsert(RecordId|Table|string $what): UpsertQuery
	{
		return new UpsertQuery($this, $what);
	}

	/**
	 * Begin a `DELETE` for a table or record (defaults to `RETURN BEFORE`).
     *
     * @param RecordId<string>|Table<string>|string $what
     *
     * @return DeleteQuery<mixed>
	 */
	public function delete(RecordId|Table|string $what): DeleteQuery
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
	 * Select the currently-authenticated record (`SELECT * FROM ONLY $auth`).
     *
     * @return AuthQuery<mixed>
	 */
	public function auth(): AuthQuery
	{
		return new AuthQuery($this);
	}

	/**
	 * Subscribe to a live query by its id.
	 *
     * @return iterable<LiveMessage<mixed>>
	 */
	public function live(string $queryUuid): iterable
	{
		return $this->connection->liveQuery($queryUuid);
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
			uniqueId: static fn(): string => (string) ++$counter,
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
