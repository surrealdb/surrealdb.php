<?php

namespace SurrealDB\SDK;

use Psr\Log\NullLogger;
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
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Events\EventDispatcher;
use SurrealDB\SDK\Exceptions\ConfigurationException;
use SurrealDB\SDK\Exceptions\UnavailableFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedFeatureException;
use SurrealDB\SDK\Protocol\Feature;
use SurrealDB\SDK\Scheduler\SyncScheduler;
use SurrealDB\SDK\Telemetry\NullMeter;
use SurrealDB\SDK\Telemetry\NullTracer;

/**
 * The primary entry point: connect to SurrealDB and run queries in the default
 * session. Connection orchestration is delegated to the {@see ConnectionController}.
 *
 * Everything a {@see SurrealSession} offers (query builders, authentication,
 * variables, live queries, transactions) applies here to the default session;
 * {@see self::newSession()} opens further, independent sessions on the same
 * connection.
 */
final class Surreal extends SurrealSession
{
	public function __construct(?DriverOptions $options = null)
	{
		parent::__construct(new ConnectionController(
			self::buildContext($options ?? new DriverOptions()),
		));
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
	//  Sessions                                                           //
	// =================================================================== //

	/**
	 * Open a new session on this connection. It starts with no namespace,
	 * database, variables or authentication of its own.
	 */
	public function newSession(): SurrealSession
	{
		return new SurrealSession($this->connection, $this->connection->createSession());
	}

	/**
	 * The ids of the sessions attached to this connection on the server.
	 *
	 * @return list<string>
	 */
	public function sessions(): array
	{
		return $this->connection->sessions();
	}

	// =================================================================== //
	//  Data management                                                    //
	// =================================================================== //

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

	/**
	 * Access the underlying controller for low-level operations.
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
