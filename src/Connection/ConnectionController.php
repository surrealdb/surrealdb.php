<?php

namespace SurrealDB\SDK\Connection;

use SurrealDB\SDK\Auth\AuthState;
use SurrealDB\SDK\Auth\Credentials;
use SurrealDB\SDK\Auth\Token;
use SurrealDB\SDK\Auth\Tokens;
use SurrealDB\SDK\Contracts\AuthProviderInterface;
use SurrealDB\SDK\Contracts\Deferred;
use SurrealDB\SDK\Contracts\ConnectionProbeInterface;
use SurrealDB\SDK\Contracts\Counter;
use SurrealDB\SDK\Contracts\EngineInterface;
use SurrealDB\SDK\Contracts\TimerScheduler;
use SurrealDB\SDK\Engines\EngineRegistry;
use SurrealDB\SDK\Enum\SpanKind;
use SurrealDB\SDK\Enum\SpanStatus;
use SurrealDB\SDK\Enum\CodecEnum;
use SurrealDB\SDK\Events\AuthChanged;
use SurrealDB\SDK\Events\Connected;
use SurrealDB\SDK\Events\Connecting;
use SurrealDB\SDK\Events\ConnectionError;
use SurrealDB\SDK\Events\Disconnected;
use SurrealDB\SDK\Events\NamespaceDatabaseSelected;
use SurrealDB\SDK\Events\Reconnecting;
use SurrealDB\SDK\Exceptions\AuthenticationException;
use SurrealDB\SDK\Exceptions\CallTerminatedException;
use SurrealDB\SDK\Exceptions\ConfigurationException;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Exceptions\HttpConnectionException;
use SurrealDB\SDK\Exceptions\InvalidSessionException;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Exceptions\UnavailableFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedVersionException;
use SurrealDB\SDK\Exceptions\ValidationException;
use SurrealDB\SDK\Live\LiveSubscription;
use SurrealDB\SDK\Live\ManagedLiveSubscription;
use SurrealDB\SDK\Protocol\Feature;
use SurrealDB\SDK\Protocol\Features;
use SurrealDB\SDK\Protocol\NamespaceDatabase;
use SurrealDB\SDK\Protocol\VersionInfo;
use SurrealDB\SDK\Query\BoundQuery;
use SurrealDB\SDK\Reconnect\ExponentialBackoffReconnect;
use SurrealDB\SDK\Support\Jwt;
use SurrealDB\SDK\Support\Publisher;
use SurrealDB\SDK\Support\Version;
use SurrealDB\SDK\Types\File;
use SurrealDB\SDK\Types\Set;
use SurrealDB\SDK\Types\Value;
use Symfony\Component\Uid\Uuid;
use function in_array;
use function is_array;
use function is_string;

/**
 * Orchestrates a connection: engine selection, connection/session state,
 * version + feature gating, authentication state and renewal, session restore
 * on (re)connect, and re-dispatch of lifecycle events. Port of the JS SDK
 * `ConnectionController`.
 *
 * High-level lifecycle events are exposed via {@see subscribe()} (an internal
 * publisher consumed by {@see \SurrealDB\SDK\Surreal}); rich domain events are
 * additionally dispatched to the PSR-14 dispatcher for outside observers.
 */
final class ConnectionController
{
    private readonly Publisher $events;
    private readonly EngineRegistry $registry;
    private readonly Counter $connections;

    private ?ConnectionState $state = null;
    private ?EngineInterface $engine = null;
    private ConnectionStatus $status = ConnectionStatus::Disconnected;
    private ?string $cachedVersion = null;

    /**
     * Whether the version request has been answered, or refused by a proxy
     * (the version is then unknown, and {@see self::$cachedVersion} null).
     */
    private bool $versionAsked = false;

    /**
     * Whether the connection is being closed on purpose, as opposed to lost
     * for good (reconnecting gave up, or is off).
     */
    private bool $closing = false;
    private ?\Throwable $lastError = null;

    private Credentials|Token|AuthProviderInterface|\Closure|string|null $authProvider = null;
    private bool $skipRenewal = false;
    private bool $checkVersion = true;

    /** @var list<\Closure> */
    private array $unsubscribers = [];

    /**
     * Managed live subscriptions to replay after a reconnect. Weak, so a
     * subscription the application dropped is not kept alive.
     *
     * @var \WeakMap<ManagedLiveSubscription,true>
     */
    private \WeakMap $managedLive;

    /**
     * Counts reconnect attempts, so work done for one socket can tell that
     * it has since been lost.
     */
    private int $connectionEpoch = 0;

    /** How long to wait before retrying a renewal that failed. */
    private const RENEWAL_RETRY_SECONDS = 5;

    /** Variables the server keeps for itself, and refuses to set. */
    private const array PROTECTED_VARIABLES = ['access', 'auth', 'token', 'session'];

    /**
     * When each session's token is due for renewal (Unix time), keyed by
     * session id ('' for the default session). Checked before calls, so
     * renewal also happens under the synchronous scheduler, which cannot
     * run the timer {@see self::scheduleRenewal()} starts.
     *
     * @var array<string,int>
     */
    private array $renewAt = [];

    /**
     * Renewals in flight, keyed like {@see self::$renewAt}: the task doing
     * each, a guard telling it its entry is still its own, and a deferred
     * for each other task waiting on it (a deferred resumes only one waiter).
     *
     * @var array<string,array{task: object|int|null, guard: object, waiters: list<Deferred<mixed>>}>
     */
    private array $renewing = [];

    /**
     * Cancels for renewal timers still pending, under a scheduler whose timers
     * can be cancelled: closing the connection cancels them, so they do not
     * keep an event loop or coroutine runtime running.
     *
     * @var array<int,\Closure(): void>
     */
    private array $timers = [];

    private int $timerId = 0;

    public function __construct(
        private readonly DriverContext $context,
        ?EngineRegistry $registry = null,
    ) {
        $this->events = new Publisher();
        $this->managedLive = new \WeakMap();
        $this->registry = $registry ?? new EngineRegistry($context->options->engines ?? []);
        $this->connections = $context->meter->counter(
            'db.client.connection.count',
            '{connection}',
            'Number of SurrealDB connection attempts.',
        );
    }

    public function subscribe(string $event, callable $listener): \Closure
    {
        return $this->events->subscribe($event, $listener);
    }

    public function status(): ConnectionStatus
    {
        if ($this->status === ConnectionStatus::Connected && $this->engine instanceof ConnectionProbeInterface) {
            $this->engine->probe();
        }

        return $this->status;
    }

    public function state(): ?ConnectionState
    {
        return $this->state;
    }

    public function version(): VersionInfo
    {
        return $this->requireEngine()->version();
    }

    public function health(): void
    {
        $this->requireEngine()->health();
    }

    // =================================================================== //
    //  Connection management                                              //
    // =================================================================== //

    public function connect(Endpoint $endpoint, ConnectOptions $options): void
    {
        $engine = $this->registry->create($endpoint->scheme, $this->context);

        $this->disconnect();
        $this->closing = false;

        // Callbacks still running for the previous connection must not
        // mistake this one for theirs.
        $this->connectionEpoch++;

        $this->engine = $engine;
        $this->authProvider = $options->authentication;
        $this->skipRenewal = $options->invalidateOnExpiry;
        $this->checkVersion = $options->versionCheck;
        $this->lastError = null;
        $this->cachedVersion = null;
        $this->versionAsked = false;
        $this->state = new ConnectionState(
            $endpoint,
            ExponentialBackoffReconnect::fromOption($options->reconnect),
            new SessionState(namespace: $options->namespace, database: $options->database),
        );

        $this->unsubscribers = [
            $engine->subscribe('connected', $this->onConnected(...)),
            $engine->subscribe('disconnected',$this->onDisconnected(...)),
            $engine->subscribe('reconnecting', $this->onReconnecting(...)),
            $engine->subscribe('error', $this->onError(...)),
        ];

        $this->status = ConnectionStatus::Connecting;
        $this->events->publish('connecting');
        $this->context->events->dispatch(new Connecting());

        // Trace connection establishment. With the OpenTelemetry adapter this
        // span is the active context, so the session-restore RPCs issued during
        // `open()` nest underneath it.
        $span = $this->context->tracer->startSpan(
            'surrealdb.connect',
            SpanKind::Client,
            $this->connectionAttributes($endpoint),
        );
        $outcome = 'ok';

        try {
            $engine->open($this->state);
            $this->ready();
            $span->setStatus(SpanStatus::Ok);
        } catch (\Throwable $error) {
            $outcome = 'error';
            $span->recordException($error)->setStatus(SpanStatus::Error, $error->getMessage());

            throw $error;
        } finally {
            $this->connections->add(1, ['db.system.name' => 'surrealdb', 'outcome' => $outcome]);
            $span->end();
        }
    }

    public function disconnect(): void
    {
        $this->closing = true;
        $this->engine?->close();
    }

    public function ready(): void
    {
        if ($this->status === ConnectionStatus::Connected) {
            return;
        }

        if ($this->status === ConnectionStatus::Disconnected) {
            throw $this->lastError ?? new ConnectionUnavailableException();
        }

        if ($this->lastError !== null) {
            throw $this->lastError;
        }
    }

    public function assertFeature(Feature $feature): void
    {
        if ($this->engine === null || !$this->versionAsked) {
            throw new ConnectionUnavailableException();
        }

        if (!$this->engine->features()->has($feature)) {
            throw new UnsupportedFeatureException($feature);
        }

        if ($this->cachedVersion !== null && !$feature->supports($this->cachedVersion)) {
            throw new UnavailableFeatureException($feature, $this->cachedVersion);
        }
    }

    // =================================================================== //
    //  Protocol wrappers                                                  //
    // =================================================================== //

    public function use(?NamespaceDatabase $what, ?string $session = null): NamespaceDatabase
    {
        $sessionState = $this->requireSession($session);
        $this->renewIfDue($session);
        $result = $this->requireEngine()->use($what, $session);

        if ($result->namespace !== null) {
            $sessionState->namespace = $result->namespace;
        }

        if ($result->database !== null) {
            $sessionState->database = $result->database;
        }

        $selected = new NamespaceDatabase($sessionState->namespace, $sessionState->database);

        $this->events->publish('using', $selected, $session);
        $this->context->events->dispatch(new NamespaceDatabaseSelected($selected, $session));

        return $selected;
    }

    /**
     * @param array<string,mixed> $auth
     */
    public function signin(array $auth, ?string $session = null, bool $skipOverride = false): Tokens
    {
        // A record access sign-in with a refresh token exchanges it for a new
        // pair. A server without refresh tokens reads it as one more variable
        // of the SIGNIN query, and refuses it with an unrelated error.
        if (isset($auth['refresh'])) {
            $this->assertFeature(Features::refreshTokens());
        }

        $sessionState = $this->requireSession($session);
        $generation = $this->beginAuthChange($sessionState, $skipOverride);
        $tokens = $this->sendAuth($sessionState, $skipOverride, fn (): Tokens => $this->requireEngine()->signin($auth, $session));

        if (!$this->isCurrentAuth($session, $sessionState->auth, $generation)) {
            return $tokens;
        }

        $this->endLiveQueriesUnlessRenewing($session, $skipOverride, $tokens->access);
        $this->selectFromCredentials($auth, $session);
        $this->applyTokens($session, $tokens, $skipOverride);

        return $tokens;
    }

    /**
     * @param array<string,mixed> $auth
     */
    public function signup(array $auth, ?string $session = null, bool $skipOverride = false): Tokens
    {
        $sessionState = $this->requireSession($session);
        $generation = $this->beginAuthChange($sessionState, $skipOverride);
        $tokens = $this->sendAuth($sessionState, $skipOverride, fn (): Tokens => $this->requireEngine()->signup($auth, $session));

        if (!$this->isCurrentAuth($session, $sessionState->auth, $generation)) {
            return $tokens;
        }

        $this->endLiveQueriesUnlessRenewing($session, $skipOverride, $tokens->access);
        $this->selectFromCredentials($auth, $session);
        $this->applyTokens($session, $tokens, $skipOverride);

        return $tokens;
    }

    public function authenticate(#[\SensitiveParameter] string $token, ?string $session = null, bool $skipOverride = false): void
    {
        $sessionState = $this->requireSession($session);
        $generation = $this->beginAuthChange($sessionState, $skipOverride);
        $this->sendAuth($sessionState, $skipOverride, fn () => $this->requireEngine()->authenticate($token, $session));

        if (!$this->isCurrentAuth($session, $sessionState->auth, $generation)) {
            return;
        }

        $this->endLiveQueriesUnlessRenewing($session, $skipOverride, $token);
        $sessionState->auth->accessToken = $token;

        // A token given explicitly may belong to someone else: keeping the
        // previous refresh token would renew the session as that user.
        if (!$skipOverride) {
            $sessionState->auth->refreshToken = null;
        }

        $sessionState->auth->overridden = $sessionState->auth->overridden || !$skipOverride;
        $this->handleAuthChanged($session);
    }

    public function refresh(Tokens $tokens, ?string $session = null, bool $skipOverride = false): Tokens
    {
        $this->assertFeature(Features::refreshTokens());
        $sessionState = $this->requireSession($session);
        $generation = $this->beginAuthChange($sessionState, $skipOverride);
        $refreshed = $this->sendAuth($sessionState, $skipOverride, fn (): Tokens => $this->requireEngine()->refresh($tokens, $session));

        if (!$this->isCurrentAuth($session, $sessionState->auth, $generation)) {
            return $refreshed;
        }

        // Refreshing the session's own pair keeps who it is; another's does not.
        $this->endLiveQueriesUnlessRenewing($session, true, $refreshed->access);
        $this->applyTokens($session, $refreshed, $skipOverride);

        return $refreshed;
    }

    public function revoke(Tokens $tokens, ?string $session = null): void
    {
        $this->assertFeature(Features::refreshTokens());
        $sessionState = $this->requireSession($session);
        $this->requireEngine()->revoke($tokens, $session);

        // Revoking the session's own refresh token: renewing and restoring
        // the session must not send it again. Its access token stays good
        // until it expires.
        if ($tokens->refresh !== null && $sessionState->auth->refreshToken === $tokens->refresh) {
            $sessionState->auth->refreshToken = null;
        }
    }

    public function set(string $name, mixed $value, ?string $session = null): void
    {
        $sessionState = $this->requireSession($session);

        // The server refuses these over a WebSocket. HTTP keeps variables
        // itself and would send one with every query, each then refused.
        if (in_array($name, self::PROTECTED_VARIABLES, true)) {
            throw new ValidationException('Validation', "'{$name}' is a protected variable and cannot be set", -32602);
        }

        $this->assertSendable($value);
        // HTTP sends the session's variables with every query, and a reconnect
        // replays them: both must send the value as it was now, as a WebSocket
        // does, not as the caller changes it later.
        $value = self::snapshot($value);
        $this->renewIfDue($session);
        $this->requireEngine()->set($name, $value, $session);
        $sessionState->variables[$name] = $value;
    }

    public function unset(string $name, ?string $session = null): void
    {
        $sessionState = $this->requireSession($session);
        $this->renewIfDue($session);
        $this->requireEngine()->unset($name, $session);
        unset($sessionState->variables[$name]);
    }

    /**
     * Sign the session out. An explicit sign-out stands: the connection's
     * auth provider does not sign the session back in on a reconnect, and a
     * fork of it starts signed out. `$skipOverride` is for the SDK giving up
     * on a renewal, after which the provider may still sign in again.
     */
    public function invalidate(?string $session = null, bool $skipOverride = false): void
    {
        $sessionState = $this->requireSession($session);
        $this->beginAuthChange($sessionState, false);
        $this->sendAuth($sessionState, false, fn () => $this->requireEngine()->invalidate($session));
        $this->endLiveQueries($sessionState);
        $this->handleAuthInvalidate($session);
        $sessionState->auth->overridden = $sessionState->auth->overridden || !$skipOverride;
    }

    /**
     * Clear the session's namespace, database, variables and authentication.
     * Like {@see self::invalidate()}, the auth provider does not sign it back in.
     */
    public function reset(?string $session = null): void
    {
        $sessionState = $this->requireSession($session);
        $this->beginAuthChange($sessionState, false);
        $this->sendAuth($sessionState, false, fn () => $this->requireEngine()->reset($session));
        $sessionState->namespace = null;
        $sessionState->database = null;
        $sessionState->variables = [];
        // Resetting the session cancels its transactions and live queries
        // on the server (SurrealDB 2.x sends no KILLED notification for them).
        $sessionState->transactions = [];
        $this->endLiveQueries($sessionState);
        $this->handleAuthInvalidate($session);
        $sessionState->auth->overridden = true;

        $payload = new NamespaceDatabase();
        $this->events->publish('using', $payload, $session);
        $this->context->events->dispatch(new NamespaceDatabaseSelected($payload, $session));
    }

    public function begin(?string $session = null): string
    {
        $this->assertFeature(Features::transactions());
        $this->renewIfDue($session);

        // Committing sends the transaction id as a UUID value, which JSON
        // cannot express; refuse up front rather than leave it open.
        if ($this->context->format === CodecEnum::JSON) {
            throw new ConfigurationException(
                'Configuration',
                'Interactive transactions need the CBOR format: SurrealDB only accepts the transaction id as a UUID.',
            );
        }

        $sessionState = $this->requireSession($session);
        $txn = $this->requireEngine()->begin($session);
        $sessionState->transactions[$txn] = true;

        return $txn;
    }

    /** Whether a transaction begun on this connection is still open on the server. */
    public function isTransactionOpen(string $txn, ?string $session = null): bool
    {
        return isset($this->state?->session($session)?->transactions[$txn]);
    }

    public function commit(string $txn, ?string $session = null): void
    {
        $this->assertFeature(Features::transactions());
        $this->renewIfDue($session);
        $this->endTransaction($txn, $session, fn () => $this->requireEngine()->commit($txn, $session));
    }

    public function cancel(string $txn, ?string $session = null): void
    {
        $this->assertFeature(Features::transactions());
        $this->renewIfDue($session);
        $this->endTransaction($txn, $session, fn () => $this->requireEngine()->cancel($txn, $session));
    }

    public function importSql(string $data): void
    {
        $this->renewIfDue(null);
        $this->requireEngine()->importSql($data);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function exportSql(array $options = []): string
    {
        $this->renewIfDue(null);
        return $this->requireEngine()->exportSql($options);
    }

    /**
     * @return iterable<\SurrealDB\SDK\Protocol\QueryChunk<mixed>>
     */
    public function query(BoundQuery $query, ?string $session = null, ?string $txn = null): iterable
    {
        // A closed session's handle fails here rather than on the server.
        $this->requireSession($session);
        $this->assertSendable($query->bindings);
        $this->renewIfDue($session);

        return $this->requireEngine()->query($query, $session, $txn);
    }

    /**
     * @param array<string,mixed> $variables
     *
     * @return iterable<\SurrealDB\SDK\Protocol\QueryChunk<mixed>>
     */
    public function gql(string $query, array $variables = [], ?string $session = null, ?string $txn = null): iterable
    {
        $this->assertFeature(Features::gql());
        $this->requireSession($session);
        $this->renewIfDue($session);

        return $this->requireEngine()->gql($query, $variables, $session, $txn);
    }

    /**
     * @param array<string,mixed> $variables
     */
    public function graphql(string $query, array $variables = [], ?string $operation = null, ?string $session = null): mixed
    {
        $this->assertFeature(Features::graphql());
        $this->requireSession($session);
        $this->renewIfDue($session);

        return $this->requireEngine()->graphql($query, $variables, $operation, $session);
    }

    /**
     * @return iterable<\SurrealDB\SDK\Live\LiveMessage<mixed>>
     */
    public function liveQuery(string $id): iterable
    {
        $this->assertFeature(Features::liveQueries());

        return $this->requireEngine()->liveQuery($id);
    }

    /**
     * @internal a subscription on the session, which ends if the session is
     * reset. A closed session's handle cannot start one.
     */
    public function registerLive(LiveSubscription $subscription, ?string $session): void
    {
        $this->requireSession($session)->live[$subscription] = true;
    }

    /**
     * @return list<string>
     */
    public function sessions(): array
    {
        $this->assertFeature(Features::sessions());

        $this->renewIfDue(null);

        return $this->requireEngine()->sessions();
    }

    public function createSession(?string $clone = null): string
    {
        return $clone === null ? $this->attachSession(null) : $this->forkSession($clone);
    }

    /**
     * Create a session that starts with a copy of another session's namespace,
     * database, variables and authentication. Unlike {@see self::createSession()},
     * the source may be the default session (null).
     *
     * @throws AuthenticationException when the source is signed in with an
     *                                 access token that has expired, and
     *                                 renewing it did not replace it
     */
    public function forkSession(?string $source): string
    {
        $sourceState = $this->requireSession($source);

        // The fork signs in with the source's access token. One due for
        // renewal is renewed first (for the source, which keeps the new pair).
        $this->renewIfDue($source);
        $token = $sourceState->auth->accessToken;
        $expiry = $token === null ? null : Jwt::expiry($token);

        if ($expiry !== null && $expiry <= time()) {
            throw new AuthenticationException(
                'The session\'s access token has expired, so a fork cannot sign in with it. Sign the session in again first.',
            );
        }

        return $this->attachSession($sourceState);
    }

    /**
     * @internal replay this subscription's LIVE query after a reconnect
     */
    public function trackLive(ManagedLiveSubscription $subscription): void
    {
        $this->managedLive[$subscription] = true;
    }

    /**
     * @internal
     */
    public function untrackLive(ManagedLiveSubscription $subscription): void
    {
        unset($this->managedLive[$subscription]);
    }

    public function destroySession(?string $session): void
    {
        $state = $this->requireState();

        if ($session === null || !isset($state->sessions[$session])) {
            throw new InvalidSessionException($session);
        }

        $this->requireEngine()->detach($session);
        // Its live queries go with it: nothing can receive or kill them now.
        $this->endLiveQueries($state->sessions[$session]);
        unset($state->sessions[$session]);
    }

    // =================================================================== //
    //  Lifecycle callbacks                                                //
    // =================================================================== //

    private function onConnected(): void
    {
        $epoch = $this->connectionEpoch;

        // A connection that was up before: restoring it must not undo it.
        $reconnect = $this->status === ConnectionStatus::Reconnecting;

        try {
            $version = $this->askVersion();

            foreach ($this->requireState()->allSessions() as $session) {
                // A new socket starts without the old one's transactions.
                $session->transactions = [];

                if ($session->id !== null) {
                    $this->requireEngine()->attach($session->id);
                }

                $this->restoreSession($session, reconnect: $reconnect);
            }

            // Asked before signing in, the version request carried no token,
            // which a proxy in front of the server may refuse. Signed in now,
            // so ask again; if it is still refused, the version stays unknown.
            if ($this->cachedVersion === null) {
                $version = $this->askVersion();
            }

            $this->requireEngine()->ready();

            // Before announcing the connection, so a listener that writes in
            // response already has the live queries listening. If this socket
            // was lost meanwhile, the next one announces itself instead.
            if (!$this->replayLiveQueries()) {
                return;
            }

            $this->status = ConnectionStatus::Connected;
            $this->events->publish('connected', $version ?? '');
            $this->context->events->dispatch(new Connected($version ?? ''));
        } catch (\Throwable $error) {
            // This socket was lost while restoring: the engine is already
            // reconnecting (or has reconnected), and a later socket announces
            // itself.
            if (!$this->isCurrentConnection($epoch)) {
                return;
            }

            $this->onError($error);
            $this->engine?->close();
        }
    }

    /**
     * Learn the server's version, and refuse one the SDK does not support.
     *
     * @return string|null null when a proxy in front of the server refused
     *                     the request (an HTTP 4xx): features are then not
     *                     gated by version, and the server has the last word
     */
    private function askVersion(): ?string
    {
        try {
            $version = $this->requireEngine()->version()->version;
        } catch (HttpConnectionException $error) {
            if ($error->status < 400 || $error->status >= 500) {
                throw $error;
            }

            $this->cachedVersion = null;
            $this->versionAsked = true;

            return null;
        }

        $this->cachedVersion = $version;
        $this->versionAsked = true;

        if ($this->checkVersion && !Version::isSupported($version)) {
            throw new UnsupportedVersionException($version, Version::MINIMUM, Version::MAXIMUM);
        }

        return $version;
    }

    /**
     * Re-issue managed live queries on a new socket, once their sessions are
     * restored. A subscription whose query fails ends; the others carry on.
     *
     * @return bool false if the socket was lost while replaying
     */
    private function replayLiveQueries(): bool
    {
        $epoch = $this->connectionEpoch;
        $subscriptions = [];
        $replayed = [];

        // Held weakly, so a subscription the application lets go of while an
        // earlier one is replaying is not replayed too, and left running on
        // the server once the batch drops it.
        foreach ($this->managedLive as $subscription => $_) {
            $subscriptions[] = \WeakReference::create($subscription);
        }

        unset($subscription);

        foreach ($subscriptions as $weak) {
            $subscription = $weak->get();

            if ($subscription === null) {
                continue;
            }

            // Its session is gone (or it was killed): end it, which also
            // releases an iteration waiting for the replay.
            if (!$subscription->isAlive()) {
                unset($this->managedLive[$subscription]);
                $subscription->connectionClosed();

                continue;
            }

            $origin = $subscription->origin();
            $ownerState = $this->state?->session($subscription->session());

            // Restarted as whoever the session is now, it would deliver
            // another user's view of the table.
            if ($origin !== null && $ownerState !== null && Jwt::identity($ownerState->auth->accessToken) !== $origin['identity']) {
                unset($this->managedLive[$subscription]);
                $subscription->connectionClosed(new ConnectionUnavailableException(
                    'The connection was lost, and the live query was not started again: its session is now signed in as someone else, or signed out.',
                ));

                continue;
            }

            // The session may have moved to another namespace or database
            // since: the query runs where it started, and the session goes
            // back to its own selection after.
            $moved = $origin !== null && $ownerState !== null
                && [$origin['namespace'], $origin['database']] !== [$ownerState->namespace, $ownerState->database];

            try {
                if ($moved) {
                    $this->requireEngine()->use(new NamespaceDatabase($origin['namespace'], $origin['database']), $ownerState->id);
                }

                try {
                    $subscription->replay();
                } finally {
                    if ($moved && $this->isCurrentConnection($epoch)) {
                        $this->requireEngine()->use(new NamespaceDatabase($ownerState->namespace, $ownerState->database), $ownerState->id);
                    }
                }
            } catch (\Throwable $error) {
                // The new socket was lost too: the subscriptions stay
                // tracked and waiting, for the next connection to replay.
                if ($this->connectionEpoch !== $epoch) {
                    return false;
                }

                unset($this->managedLive[$subscription]);
                $subscription->connectionClosed(new ConnectionUnavailableException(
                    'The connection was lost, and the server refused to start the live query again: ' . $error->getMessage(),
                    $error,
                ));
                $this->onError($error);

                // An error listener closed the connection.
                if (!$this->isCurrentConnection($epoch)) {
                    return false;
                }

                continue;
            }

            $replayed[] = $weak;

            // The application may have let go of it while its query was in
            // flight. Once this loop lets go too, nothing would stop the new
            // query on the server.
            $id = $subscription->id();
            $owner = $subscription->session();
            unset($subscription);

            if ($weak->get() === null && $id !== '') {
                try {
                    LiveSubscription::killOnServer($this, $id, $owner);
                } catch (\Throwable) {
                    // Best effort: it is gone on this side either way.
                }

                // That lost the socket: the next one replays the rest.
                if (!$this->isCurrentConnection($epoch)) {
                    return false;
                }
            }
        }

        // Only now, so a consumer that writes as soon as it resumes does not
        // miss a change a query still being replayed would have caught.
        unset($subscription);

        foreach ($replayed as $weak) {
            // A resumed consumer can run straight away (OpenSwoole) and lose
            // this socket too: the rest then wait for the next replay.
            if (!$this->isCurrentConnection($epoch)) {
                return false;
            }

            $weak->get()?->resumeIteration();
        }

        return $this->isCurrentConnection($epoch);
    }

    /**
     * Whether the socket a callback started on is still the live one: not
     * lost since, and the connection not closed.
     */
    private function isCurrentConnection(int $epoch): bool
    {
        return $this->engine !== null && $this->connectionEpoch === $epoch;
    }

    private function onDisconnected(): void
    {
        foreach ($this->unsubscribers as $unsubscribe) {
            $unsubscribe();
        }

        $this->unsubscribers = [];

        // Closed on purpose, iteration over the live queries ends. Lost, it
        // throws: changes may have been missed.
        $lost = $this->closing ? null : new ConnectionUnavailableException('The connection was lost, and could not be established again.');
        /** @var \WeakMap<LiveSubscription, true> $live */
        $live = new \WeakMap();

        foreach ($this->managedLive as $subscription => $_) {
            $live[$subscription] = true;
        }

        foreach ($this->state?->allSessions() ?? [] as $session) {
            foreach ($session->live as $subscription => $_) {
                $live[$subscription] = true;
            }
        }

        $this->managedLive = new \WeakMap();
        $this->state = null;
        $this->engine = null;
        $this->renewAt = [];

        $timers = $this->timers;
        $this->timers = [];

        foreach ($timers as $cancel) {
            $cancel();
        }

        // Renewals still running belong to the closed connection: release
        // the tasks waiting on them, and leave the next connection to renew
        // for itself.
        $renewing = $this->renewing;
        $this->renewing = [];

        // Announced before waking anyone: a woken task can run straight away
        // (OpenSwoole) and connect again, which this must not then undo.
        $this->status = ConnectionStatus::Disconnected;

        try {
            $this->events->publish('disconnected');
            $this->context->events->dispatch(new Disconnected());
        } finally {
            foreach ($renewing as $renewal) {
                foreach ($renewal['waiters'] as $waiter) {
                    $waiter->resolve(true);
                }
            }

            foreach ($live as $subscription => $_) {
                $subscription->connectionClosed($lost);
            }
        }
    }

    private function onReconnecting(): void
    {
        // Reconnects span asynchronous engine callbacks, so they are surfaced as
        // a counter increment rather than a span (which would activate a scope
        // in one callback and close it in another).
        $this->connections->add(1, ['db.system.name' => 'surrealdb', 'outcome' => 'reconnect']);
        // The lost socket took its transactions with it.
        foreach ($this->state?->allSessions() ?? [] as $session) {
            $session->transactions = [];
        }

        $this->status = ConnectionStatus::Reconnecting;
        $this->connectionEpoch++;

        foreach ($this->managedLive as $subscription => $_) {
            $subscription->suspend($this->context->scheduler);
        }

        // One attached with liveOf() is not started again: the SDK does not
        // know its query.
        foreach ($this->state?->allSessions() ?? [] as $session) {
            foreach ($session->live as $subscription => $_) {
                if (!$subscription->isManaged()) {
                    $subscription->connectionClosed(new ConnectionUnavailableException(
                        'The connection was lost. A live query attached with liveOf() is not started again on the new connection.',
                    ));
                }
            }
        }

        // A listener that throws must not stop the reconnect half way, which
        // would leave the engine neither connected nor closed.
        $announcements = [
            fn () => $this->events->publish('reconnecting'),
            fn () => $this->context->events->dispatch(new Reconnecting()),
        ];

        foreach ($announcements as $announce) {
            try {
                $announce();
            } catch (\Throwable $error) {
                $this->onError($error);
            }
        }
    }

    private function onError(\Throwable $error): void
    {
        $this->lastError = $error;
        $this->events->publish('error', $error);
        $this->context->events->dispatch(new ConnectionError($error));
    }

    // =================================================================== //
    //  Authentication                                                     //
    // =================================================================== //

    /**
     * End the session's live subscriptions: they were started by whoever it
     * was signed in as. The server stops them on reset (2.x without a
     * `KILLED` notification), and should on a change of principal, which
     * SurrealDB 3.0.x did not always do (GHSA-4m82-p8cx-f94j).
     */
    private function endLiveQueries(SessionState $sessionState): void
    {
        foreach ($sessionState->live as $subscription => $_) {
            $subscription->sessionEnded();
        }

        $sessionState->live = new \WeakMap();
    }

    /**
     * An explicit sign-in changes who the session is. Renewing (the SDK's own
     * sign-in with the auth provider, or with a refresh token) usually does
     * not, but can: an auth provider may hand out another user's credentials,
     * and a refresh may be given another user's pair. The live queries the
     * session started end whenever who it is changes.
     */
    private function endLiveQueriesUnlessRenewing(?string $session, bool $renewing, ?string $newToken = null): void
    {
        $sessionState = $this->state?->session($session);

        if ($sessionState === null) {
            return;
        }

        if (!$renewing || Jwt::identity($sessionState->auth->accessToken) !== Jwt::identity($newToken)) {
            $this->endLiveQueries($sessionState);
        }
    }

    /**
     * Send an authentication request. Until an explicit one is answered, the
     * session's credentials are about to change, so renewals hold off rather
     * than renew what is being replaced.
     *
     * @template T
     *
     * @param \Closure(): T $request
     *
     * @return T
     */
    private function sendAuth(SessionState $sessionState, bool $skipOverride, \Closure $request): mixed
    {
        if ($skipOverride) {
            return $request();
        }

        $auth = $sessionState->auth;
        $auth->pending++;

        try {
            return $request();
        } finally {
            $auth->pending--;
        }
    }

    /**
     * An explicit change starts a new generation, before its request goes
     * out; a renewal or restore (`$skipOverride`) carries on the current one.
     */
    private function beginAuthChange(SessionState $sessionState, bool $skipOverride): int
    {
        return $skipOverride ? $sessionState->auth->generation : ++$sessionState->auth->generation;
    }

    /**
     * Whether nothing changed the session's authentication since `$generation`
     * began: no explicit sign-in or sign-out, and still the same connection.
     * Other tasks change that while this one waits.
     *
     * @phpstan-impure
     */
    private function isCurrentAuth(?string $session, AuthState $auth, int $generation): bool
    {
        return $this->state?->session($session)?->auth === $auth && $auth->generation === $generation;
    }

    private function applyTokens(?string $session, Tokens $tokens, bool $skipOverride): void
    {
        $sessionState = $this->requireSession($session);
        $sessionState->auth->accessToken = $tokens->access;
        $sessionState->auth->refreshToken = $tokens->refresh;
        $sessionState->auth->overridden = $sessionState->auth->overridden || !$skipOverride;
        $this->handleAuthChanged($session);
    }

    private function handleAuthChanged(?string $session): void
    {
        $sessionState = $this->state?->session($session);
        $tokens = $sessionState?->auth->tokens();

        if ($tokens === null) {
            return;
        }

        $this->events->publish('auth', $tokens, $session);
        $this->context->events->dispatch(new AuthChanged($tokens, $session));
        $this->scheduleRenewal($session);
    }

    private function handleAuthInvalidate(?string $session): void
    {
        $sessionState = $this->state?->session($session);

        if ($sessionState === null) {
            return;
        }

        $sessionState->auth->clear();
        unset($this->renewAt[$session ?? '']);
        $this->events->publish('auth', null, $session);
        $this->context->events->dispatch(new AuthChanged(null, $session));
    }

    private function scheduleRenewal(?string $session): void
    {
        // Whatever the previous token was due for no longer applies.
        unset($this->renewAt[$session ?? '']);

        $sessionState = $this->state?->session($session);
        $token = $sessionState?->auth->accessToken;

        if ($token === null) {
            return;
        }

        $expiry = Jwt::expiry($token);

        if ($expiry === null) {
            return;
        }

        // Renew a minute early. With renewal off there is nothing to do
        // early: the session is signed out once the token expires.
        $lead = $this->skipRenewal ? 0 : 60;
        $delay = max($expiry - time() - $lead, 0);
        $this->renewAt[$session ?? ''] = $expiry - $lead;

        // Runs only under an async scheduler; the sync scheduler treats spawn as
        // a no-op and relies on the checks before each call instead. The timer
        // goes through the same deadline as those checks, so a renewal already
        // done (or in flight) is not repeated, and a timer left over from an
        // earlier token finds the deadline moved on.
        $this->after((float) $delay, function () use ($session): void {
            $this->renewIfDue($session);
        });
    }

    /**
     * Renew the session's token if it is due. Under an async scheduler the
     * timer from {@see self::scheduleRenewal()} usually got there first.
     */
    private function renewIfDue(?string $session): void
    {
        $key = $session ?? '';
        $at = $this->renewalDeadline($key);

        if ($at === null || time() < $at) {
            return;
        }

        // An explicit sign-in or sign-out is waiting on the server: it
        // replaces or clears these credentials. Check again shortly, in case
        // it fails and they still need renewing.
        if (($this->state?->session($session)?->auth->pending ?? 0) > 0) {
            $this->checkRenewalAt($session, time() + self::RENEWAL_RETRY_SECONDS);

            return;
        }

        if (isset($this->renewing[$key])) {
            // Another task is renewing: wait for its token rather than send
            // the old one.
            $this->awaitRenewal($key);

            return;
        }

        $guard = $this->claimRenewal($key);

        try {
            // A new token schedules the next deadline, and signing out clears
            // it. The same token again (one that could not be replaced, or a
            // fixed token from the auth provider) is checked once more when it
            // expires, when the provider may have a new one.
            $this->applyAuthentication($session, keepValidToken: true);

            if ($this->renewalDeadline($key) === $at) {
                $token = $this->state?->session($session)?->auth->accessToken;
                $expiry = $token === null ? null : Jwt::expiry($token);

                if ($expiry !== null && $expiry > $at) {
                    $this->renewAt[$key] = $expiry;

                    // Checked at expiry even if no call comes along: that
                    // signs the session out (and ends its live queries)
                    // unless something can replace it.
                    $this->checkRenewalAt($session, $expiry);
                } else {
                    unset($this->renewAt[$key]);
                }
            }
        } catch (\Throwable $error) {
            // The connection it renewed for was closed meanwhile: its failure
            // is not the current connection's to report.
            if (!$this->ownsRenewal($key, $guard)) {
                return;
            }

            // Try again shortly rather than carry on with a token about to
            // expire.
            if ($this->renewalDeadline($key) === $at) {
                $this->renewAt[$key] = time() + self::RENEWAL_RETRY_SECONDS;
                $this->checkRenewalAt($session, $this->renewAt[$key]);
            }

            $this->onError(new AuthenticationException('Token renewal failed', $error));
        } finally {
            $this->releaseRenewal($key, $guard);
        }
    }

    /**
     * Wait for the renewal another task has in flight for `$key`, if any.
     * The renewing task itself (an auth provider that queries, say) goes
     * ahead: waiting for itself would never end.
     */
    private function awaitRenewal(string $key): void
    {
        $renewal = $this->renewing[$key] ?? null;

        if ($renewal === null || $renewal['task'] === self::currentTask()) {
            return;
        }

        /** @var Deferred<mixed> $done */
        $done = $this->context->scheduler->defer();
        $this->renewing[$key]['waiters'][] = $done;

        try {
            $done->await();
        } catch (\Throwable) {
            // The renewing task reports its own failure.
        }
    }

    /**
     * Mark the current task as renewing `$key`; the guard identifies this
     * claim to {@see self::releaseRenewal()}.
     */
    private function claimRenewal(string $key): object
    {
        $guard = new \stdClass();
        $this->renewing[$key] = ['task' => self::currentTask(), 'guard' => $guard, 'waiters' => []];

        return $guard;
    }

    /**
     * End a claim and wake the tasks waiting on it. Unless the connection was
     * closed meanwhile, which released the waiters and may have let a new
     * connection start its own.
     */
    private function releaseRenewal(string $key, object $guard): void
    {
        if (!$this->ownsRenewal($key, $guard)) {
            return;
        }

        $waiters = $this->renewing[$key]['waiters'];
        unset($this->renewing[$key]);

        foreach ($waiters as $waiter) {
            $waiter->resolve(true);
        }
    }

    /**
     * Under an async scheduler, check the renewal at `$at` even if no call
     * comes along (the sync scheduler treats spawn as a no-op, and relies on
     * the checks before each call).
     */
    private function checkRenewalAt(?string $session, int $at): void
    {
        $this->after((float) max($at - time(), 0), function () use ($session): void {
            $this->renewIfDue($session);
        });
    }

    /**
     * Run `$task` as a background task after `$seconds`. Under a scheduler
     * whose timers can be cancelled, the connection closing cancels it;
     * otherwise it is a task that sleeps first (a no-op under the
     * synchronous scheduler).
     */
    private function after(float $seconds, \Closure $task): void
    {
        $scheduler = $this->context->scheduler;

        if (!$scheduler instanceof TimerScheduler) {
            $scheduler->spawn(static function () use ($scheduler, $seconds, $task): void {
                $scheduler->delay($seconds);
                $task();
            });

            return;
        }

        $id = $this->timerId++;
        $this->timers[$id] = $scheduler->after($seconds, function () use ($id, $task): void {
            unset($this->timers[$id]);
            $task();
        });
    }

    /**
     * Whether the renewal holding `$guard` is still the one in flight for
     * `$key`: closing the connection drops the guards while renewals wait.
     */
    private function ownsRenewal(string $key, object $guard): bool
    {
        return isset($this->renewing[$key]) && $this->renewing[$key]['guard'] === $guard;
    }

    /**
     * The fiber or coroutine running now, or null outside one (where nothing
     * else can run at the same time).
     */
    private static function currentTask(): object|int|null
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber !== null) {
            return $fiber;
        }

        if (class_exists(\OpenSwoole\Coroutine::class)) {
            $id = \OpenSwoole\Coroutine::getCid();

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * Read fresh each time: renewing reschedules (or clears) the deadline.
     */
    private function renewalDeadline(string $key): ?int
    {
        return $this->renewAt[$key] ?? null;
    }

    /**
     * @param bool $keepValidToken when nothing can replace the token, keep it
     *                             while it is still valid rather than sign the
     *                             session out (renewal, as opposed to restoring a
     *                             session on a new socket)
     *
     * @return bool whether it kept a token nothing could replace
     */
    private function applyAuthentication(?string $session, bool $keepValidToken = false): bool
    {
        $sessionState = $this->state?->session($session);

        if ($sessionState === null) {
            return false;
        }

        // Each step below can wait (on the server, or on an auth provider),
        // and an explicit sign-in or sign-out meanwhile takes precedence.
        $auth = $sessionState->auth;
        $generation = $auth->generation;
        $token = $auth->accessToken;

        if ($this->skipRenewal) {
            return $this->applyWithoutRenewal($session, $auth, $generation, $keepValidToken);
        }

        if ($token !== null) {
            $expiry = Jwt::expiry($token);

            if ($expiry !== null && ($expiry - time()) > 60) {
                try {
                    $this->authenticate($token, $session, true);

                    return false;
                } catch (ServerException) {
                    // Fall through to the next strategy.
                }
            }
        }

        if (!$this->isCurrentAuth($session, $auth, $generation)) {
            return false;
        }

        if ($sessionState->auth->refreshToken !== null) {
            $tokens = $sessionState->auth->tokens();

            if ($tokens !== null) {
                try {
                    $this->refresh($tokens, $session, true);

                    return false;
                } catch (ServerException $error) {
                    // Expired, revoked or spent: it will not work again, and
                    // trying it at every renewal and reconnect only fails.
                    if ($this->isCurrentAuth($session, $auth, $generation) && $auth->refreshToken === $tokens->refresh) {
                        $auth->refreshToken = null;
                    }

                    $this->onError(new AuthenticationException('The refresh token was refused', $error));
                }
            }
        }

        if (!$this->isCurrentAuth($session, $auth, $generation)) {
            return false;
        }

        if (!$sessionState->auth->overridden) {
            try {
                if ($this->applyAuthProvider($session, $auth, $generation)) {
                    return false;
                }
            } catch (ServerException $error) {
                // Refused: a fixed token that has expired, or a password that
                // changed. Restoring a session reports that to the caller.
                // Renewing it carries on with the token while it lasts, and
                // signs the session out after, rather than retry forever.
                if (!$keepValidToken) {
                    throw $error;
                }

                $this->onError(new AuthenticationException('The auth provider\'s sign-in was refused', $error));
            }
        }

        if (!$this->isCurrentAuth($session, $auth, $generation)) {
            return false;
        }

        if ($keepValidToken && $token !== null && (Jwt::expiry($token) ?? PHP_INT_MAX) > time()) {
            return true;
        }

        $this->abortAuthentication($session);

        return false;
    }

    private function applyAuthProvider(?string $session, AuthState $auth, int $generation): bool
    {
        if ($this->authProvider === null) {
            return false;
        }

        $computed = $this->resolveProvider($this->authProvider, $session);

        // Signed in or out explicitly while the provider ran: that stands.
        if (!$this->isCurrentAuth($session, $auth, $generation)) {
            return true;
        }

        if ($computed === null) {
            return false;
        }

        if ($computed instanceof Credentials) {
            $this->signin($computed->toArray(), $session, true);
        } else {
            $this->authenticate((string) $computed, $session, true);
        }

        return true;
    }

    private function resolveProvider(
        Credentials|Token|AuthProviderInterface|\Closure|string $provider,
        ?string $session,
    ): Credentials|Token|string|null {
        if ($provider instanceof \Closure) {
            $provider = $provider($session);
        } elseif ($provider instanceof AuthProviderInterface) {
            $provider = $provider->resolve($session);
        }

        if ($provider instanceof Credentials || $provider instanceof Token || is_string($provider)) {
            return $provider;
        }

        return null;
    }

    /**
     * Authentication with renewal off (`invalidateOnExpiry`): a token is used
     * for as long as it is valid, on a new socket too, and the session is
     * signed out once it expires, not renewed. Signing a session in for the
     * first time with the auth provider is not renewal, and still happens.
     *
     * @return bool whether it kept a valid token (renewal)
     */
    private function applyWithoutRenewal(?string $session, AuthState $auth, int $generation, bool $keepValidToken): bool
    {
        $token = $auth->accessToken;
        $expiry = $token === null ? null : Jwt::expiry($token);

        if ($token !== null && ($expiry === null || $expiry > time())) {
            if ($keepValidToken) {
                return true;
            }

            try {
                $this->authenticate($token, $session, true);

                return false;
            } catch (ServerException) {
                // Refused: signed out below.
            }
        }

        // A session signed out when its token expired is overridden (see
        // abortAuthentication()), so it is not signed back in here.
        if ($token === null && !$auth->overridden && $this->applyAuthProvider($session, $auth, $generation)) {
            return false;
        }

        if ($this->isCurrentAuth($session, $auth, $generation)) {
            $this->abortAuthentication($session);
        }

        return false;
    }

    private function abortAuthentication(?string $session): void
    {
        $sessionState = $this->state?->session($session);

        // With renewal off this is the token expiring, which signs the session
        // out for good: the auth provider does not sign it back in. Otherwise
        // the provider may still, once it has new credentials.
        if ($sessionState?->auth->accessToken !== null) {
            $this->invalidate($session, !$this->skipRenewal);
        }
    }

    // =================================================================== //
    //  Session restore + helpers                                          //
    // =================================================================== //

    private function attachSession(?SessionState $source): string
    {
        $state = $this->requireState();
        $this->assertFeature(Features::sessions());

        $id = Uuid::v4()->toRfc4122();
        $this->requireEngine()->attach($id);

        // A new session is authenticated by the configured auth provider, if
        // any; a fork reuses its source's access token. Not its refresh token:
        // the server revokes one when it is used, so two sessions renewing
        // with the same one would leave the slower of them signed out.
        $session = $source === null ? new SessionState(id: $id) : new SessionState(
            id: $id,
            namespace: $source->namespace,
            database: $source->database,
            auth: new AuthState(
                $source->auth->accessToken,
                null,
                $source->auth->overridden,
            ),
        );
        $session->variables = $source->variables ?? [];
        $state->sessions[$id] = $session;

        try {
            $this->restoreSession($session, $source !== null);
        } catch (\Throwable $error) {
            // Nothing outside holds the id yet, so nobody else could close it.
            unset($state->sessions[$id]);

            try {
                $this->requireEngine()->detach($id);
            } catch (\Throwable) {
                // The restore failure is the one worth reporting.
            }

            throw $error;
        }

        return $id;
    }

    /**
     * Run a commit or cancel. Once the server has answered, even with an
     * error (a commit that hit a conflict is gone too), the transaction is
     * over; only a call that may not have reached the server leaves it open.
     *
     * @param \Closure(): void $end
     */
    private function endTransaction(string $txn, ?string $session, \Closure $end): void
    {
        try {
            $end();
        } catch (ServerException $error) {
            $this->forgetTransaction($txn, $session);

            throw $error;
        }

        $this->forgetTransaction($txn, $session);
    }

    private function forgetTransaction(string $txn, ?string $session): void
    {
        $sessionState = $this->state?->session($session);

        if ($sessionState !== null) {
            unset($sessionState->transactions[$txn]);
        }
    }

    /**
     * Signing in with namespace or database credentials also selects them.
     *
     * @param array<string,mixed> $auth
     */
    private function selectFromCredentials(array $auth, ?string $session): void
    {
        $sessionState = $this->requireSession($session);

        // The protocol accepts both spellings.
        $namespace = $auth['ns'] ?? $auth['NS'] ?? null;
        $database = $auth['db'] ?? $auth['DB'] ?? null;

        if (is_string($namespace)) {
            $sessionState->namespace = $namespace;
        }

        if (is_string($database)) {
            $sessionState->database = $database;
        }
    }

    /**
     * @param bool $fork whether the session's tokens were copied from another
     *                   session, which still holds them
     */
    private function restoreSession(SessionState $session, bool $fork = false, bool $reconnect = false): void
    {
        $epoch = $this->connectionEpoch;

        // Authenticate first: a server that refuses guests (the SurrealDB 2.x
        // default) rejects USE and LET from an anonymous session.
        if (!$fork || !$this->authenticateWithCopiedToken($session)) {
            try {
                $this->restoreAuthentication($session->id);
            } catch (\Throwable $error) {
                // On a reconnect, a session nothing can sign in again comes
                // back signed out, rather than take the connection down with
                // it. Connecting, or attaching a session, reports it instead.
                if (!$reconnect || !$this->isCurrentConnection($epoch)
                    || $error instanceof ConnectionUnavailableException || $error instanceof CallTerminatedException
                ) {
                    throw $error;
                }

                $this->endLiveQueries($session);
                $this->handleAuthInvalidate($session->id);
                $this->onError(new AuthenticationException('Could not sign the session in again after reconnecting', $error));
            }
        }

        if ($session->namespace !== null || $session->database !== null) {
            $this->use(new NamespaceDatabase($session->namespace, $session->database), $session->id);
        }

        foreach ($session->variables as $name => $value) {
            $this->requireEngine()->set($name, $value, $session->id);
        }
    }

    /**
     * Authenticate a session on a new socket as the session's renewal: one
     * already in flight is waited for, and one that falls due meanwhile waits
     * for this, so the two never spend the same single-use refresh token. The
     * second to spend it would fail, and sign the session out.
     */
    private function restoreAuthentication(?string $session): void
    {
        $key = $session ?? '';

        // Waiting can end with another task's renewal under way.
        while (isset($this->renewing[$key]) && $this->renewing[$key]['task'] !== self::currentTask()) {
            $this->awaitRenewal($key);
        }

        // A renewal that lost its socket restores it in passing (the
        // synchronous scheduler reconnects inline), and holds the claim.
        if (isset($this->renewing[$key])) {
            $this->applyAuthentication($session);

            return;
        }

        $guard = $this->claimRenewal($key);

        try {
            $this->applyAuthentication($session);
        } finally {
            $this->releaseRenewal($key, $guard);
        }
    }

    /**
     * A fork's copied token is good for as long as the source's is, even when
     * it is too close to expiry for a reconnect to bother reusing it.
     */
    private function authenticateWithCopiedToken(SessionState $session): bool
    {
        $token = $session->auth->accessToken;

        if ($token === null) {
            return false;
        }

        $expiry = Jwt::expiry($token);

        if ($expiry !== null && $expiry <= time()) {
            return false;
        }

        try {
            $this->authenticate($token, $session->id, true);

            return true;
        } catch (ServerException) {
            return false;
        }
    }

    /**
     * @return array<non-empty-string, scalar|array<scalar>|null>
     */
    private function connectionAttributes(Endpoint $endpoint): array
    {
        $attributes = [
            'db.system.name' => 'surrealdb',
            'server.address' => $endpoint->host,
            'network.transport' => $endpoint->scheme,
        ];

        if ($endpoint->port !== null) {
            $attributes['server.port'] = $endpoint->port;
        }

        return $attributes;
    }

    private function requireEngine(): EngineInterface
    {
        return $this->engine ?? throw new ConnectionUnavailableException();
    }

    private function requireState(): ConnectionState
    {
        return $this->state ?? throw new ConnectionUnavailableException();
    }

    private function requireSession(?string $session): SessionState
    {
        return $this->requireState()->session($session) ?? throw new InvalidSessionException($session);
    }

    /**
     * A copy of a value that later changes to the original do not reach.
     * Arrays are copied by value already, but the objects in them are not.
     * The SDK's own value types are immutable, and anything else the encoder
     * refuses anyway.
     */
    private static function snapshot(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::snapshot(...), $value);
        }

        if ($value instanceof \stdClass) {
            $copy = new \stdClass();

            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = self::snapshot($item);
            }

            return $copy;
        }

        // Encoded as what it serializes to, so that is what is kept.
        if ($value instanceof \JsonSerializable && !$value instanceof Value) {
            return self::snapshot($value->jsonSerialize());
        }

        return $value;
    }

    /**
     * Refuse a value the connected server has no type for, with an error that
     * names it. SurrealDB 2.x has no sets or files, and answers a request
     * carrying one only with "Encountered an unknown CBOR tag".
     */
    private function assertSendable(mixed $value): void
    {
        $version = $this->cachedVersion;

        if ($version === null || (Features::sets()->supports($version) && Features::files()->supports($version))) {
            return;
        }

        if ($value instanceof Set) {
            $this->assertFeature(Features::sets());
        }

        if ($value instanceof File) {
            $this->assertFeature(Features::files());
        }

        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertSendable($item);
            }
        }
    }
}
