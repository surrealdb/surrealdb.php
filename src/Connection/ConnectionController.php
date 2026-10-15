<?php

namespace SurrealDB\SDK\Connection;

use SurrealDB\SDK\Auth\AuthState;
use SurrealDB\SDK\Auth\Credentials;
use SurrealDB\SDK\Auth\Token;
use SurrealDB\SDK\Auth\Tokens;
use SurrealDB\SDK\Contracts\AuthProviderInterface;
use SurrealDB\SDK\Contracts\ConnectionProbeInterface;
use SurrealDB\SDK\Contracts\Counter;
use SurrealDB\SDK\Contracts\EngineInterface;
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
use SurrealDB\SDK\Exceptions\ConfigurationException;
use SurrealDB\SDK\Exceptions\ConnectionUnavailableException;
use SurrealDB\SDK\Exceptions\InvalidSessionException;
use SurrealDB\SDK\Exceptions\ServerException;
use SurrealDB\SDK\Exceptions\UnavailableFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedFeatureException;
use SurrealDB\SDK\Exceptions\UnsupportedVersionException;
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
use Symfony\Component\Uid\Uuid;
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

    /** How long to wait before retrying a renewal that failed. */
    private const RENEWAL_RETRY_SECONDS = 5;

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
     * Sessions whose renewal is in flight, keyed like {@see self::$renewAt}.
     *
     * @var array<string,true>
     */
    private array $renewing = [];

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

        $this->engine = $engine;
        $this->authProvider = $options->authentication;
        $this->skipRenewal = $options->invalidateOnExpiry;
        $this->checkVersion = $options->versionCheck;
        $this->lastError = null;
        $this->cachedVersion = null;
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
        if ($this->engine === null || $this->cachedVersion === null) {
            throw new ConnectionUnavailableException();
        }

        if (!$this->engine->features()->has($feature)) {
            throw new UnsupportedFeatureException($feature);
        }

        if (!$feature->supports($this->cachedVersion)) {
            throw new UnavailableFeatureException($feature, $this->cachedVersion);
        }
    }

    // =================================================================== //
    //  Protocol wrappers                                                  //
    // =================================================================== //

    public function use(?NamespaceDatabase $what, ?string $session = null): NamespaceDatabase
    {
        $result = $this->requireEngine()->use($what, $session);
        $sessionState = $this->requireSession($session);

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
        $tokens = $this->requireEngine()->signin($auth, $session);
        $this->applyTokens($session, $tokens, $skipOverride);

        return $tokens;
    }

    /**
     * @param array<string,mixed> $auth
     */
    public function signup(array $auth, ?string $session = null, bool $skipOverride = false): Tokens
    {
        $tokens = $this->requireEngine()->signup($auth, $session);
        $this->applyTokens($session, $tokens, $skipOverride);

        return $tokens;
    }

    public function authenticate(string $token, ?string $session = null, bool $skipOverride = false): void
    {
        $this->requireEngine()->authenticate($token, $session);
        $sessionState = $this->requireSession($session);
        $sessionState->auth->accessToken = $token;
        $sessionState->auth->overridden = $sessionState->auth->overridden || !$skipOverride;
        $this->handleAuthChanged($session);
    }

    public function refresh(Tokens $tokens, ?string $session = null, bool $skipOverride = false): Tokens
    {
        $this->assertFeature(Features::refreshTokens());
        $refreshed = $this->requireEngine()->refresh($tokens, $session);
        $this->applyTokens($session, $refreshed, $skipOverride);

        return $refreshed;
    }

    public function revoke(Tokens $tokens, ?string $session = null): void
    {
        $this->assertFeature(Features::refreshTokens());
        $this->requireEngine()->revoke($tokens, $session);
    }

    public function set(string $name, mixed $value, ?string $session = null): void
    {
        $this->renewIfDue($session);
        $this->requireEngine()->set($name, $value, $session);
        $this->requireSession($session)->variables[$name] = $value;
    }

    public function unset(string $name, ?string $session = null): void
    {
        $this->renewIfDue($session);
        $this->requireEngine()->unset($name, $session);
        unset($this->requireSession($session)->variables[$name]);
    }

    public function invalidate(?string $session = null): void
    {
        $this->requireEngine()->invalidate($session);
        $this->handleAuthInvalidate($session);
    }

    public function reset(?string $session = null): void
    {
        $this->requireEngine()->reset($session);
        $sessionState = $this->requireSession($session);
        $sessionState->namespace = null;
        $sessionState->database = null;
        $sessionState->variables = [];
        $this->handleAuthInvalidate($session);

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

        return $this->requireEngine()->begin($session);
    }

    public function commit(string $txn, ?string $session = null): void
    {
        $this->assertFeature(Features::transactions());
        $this->renewIfDue($session);
        $this->requireEngine()->commit($txn, $session);
    }

    public function cancel(string $txn, ?string $session = null): void
    {
        $this->assertFeature(Features::transactions());
        $this->renewIfDue($session);
        $this->requireEngine()->cancel($txn, $session);
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
        $this->renewIfDue($session);
        return $this->requireEngine()->query($query, $session, $txn);
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
     * @return list<string>
     */
    public function sessions(): array
    {
        $this->assertFeature(Features::sessions());

        return $this->requireEngine()->sessions();
    }

    public function createSession(?string $clone = null): string
    {
        return $this->attachSession($clone === null ? null : $this->requireSession($clone));
    }

    /**
     * Create a session that starts with a copy of another session's namespace,
     * database, variables and authentication. Unlike {@see self::createSession()},
     * the source may be the default session (null).
     */
    public function forkSession(?string $source): string
    {
        return $this->attachSession($this->requireSession($source));
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
        unset($state->sessions[$session]);
    }

    // =================================================================== //
    //  Lifecycle callbacks                                                //
    // =================================================================== //

    private function onConnected(): void
    {
        try {
            $version = $this->requireEngine()->version()->version;
            $this->cachedVersion = $version;

            if ($this->checkVersion && !Version::isSupported($version)) {
                throw new UnsupportedVersionException($version, Version::MINIMUM, Version::MAXIMUM);
            }

            foreach ($this->requireState()->allSessions() as $session) {
                if ($session->id !== null) {
                    $this->requireEngine()->attach($session->id);
                }

                $this->restoreSession($session);
            }

            $this->requireEngine()->ready();

            // Before announcing the connection, so a listener that writes in
            // response already has the live queries listening.
            $this->replayLiveQueries();

            $this->status = ConnectionStatus::Connected;
            $this->events->publish('connected', $version);
            $this->context->events->dispatch(new Connected($version));
        } catch (\Throwable $error) {
            $this->onError($error);
            $this->engine?->close();
        }
    }

    /**
     * Re-issue managed live queries on a new socket, once their sessions are
     * restored. A subscription whose query fails ends; the others carry on.
     */
    private function replayLiveQueries(): void
    {
        $subscriptions = [];

        foreach ($this->managedLive as $subscription => $_) {
            $subscriptions[] = $subscription;
        }

        foreach ($subscriptions as $subscription) {
            // Its session is gone (or it was killed): end it, which also
            // releases an iteration waiting for the replay.
            if (!$subscription->isAlive()) {
                unset($this->managedLive[$subscription]);
                $subscription->connectionClosed();

                continue;
            }

            try {
                $subscription->replay();
            } catch (\Throwable $error) {
                unset($this->managedLive[$subscription]);
                $this->onError($error);
            }
        }
    }

    private function onDisconnected(): void
    {
        foreach ($this->unsubscribers as $unsubscribe) {
            $unsubscribe();
        }

        $this->unsubscribers = [];
        $this->state = null;
        $this->engine = null;
        $this->renewAt = [];

        $live = $this->managedLive;
        $this->managedLive = new \WeakMap();

        foreach ($live as $subscription => $_) {
            $subscription->connectionClosed();
        }

        $this->status = ConnectionStatus::Disconnected;
        $this->events->publish('disconnected');
        $this->context->events->dispatch(new Disconnected());
    }

    private function onReconnecting(): void
    {
        // Reconnects span asynchronous engine callbacks, so they are surfaced as
        // a counter increment rather than a span (which would activate a scope
        // in one callback and close it in another).
        $this->connections->add(1, ['db.system.name' => 'surrealdb', 'outcome' => 'reconnect']);
        $this->status = ConnectionStatus::Reconnecting;

        foreach ($this->managedLive as $subscription => $_) {
            $subscription->suspend($this->context->scheduler);
        }

        $this->events->publish('reconnecting');
        $this->context->events->dispatch(new Reconnecting());
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
        $sessionState = $this->state?->session($session);
        $token = $sessionState?->auth->accessToken;

        if ($token === null) {
            return;
        }

        $expiry = Jwt::expiry($token);

        if ($expiry === null) {
            return;
        }

        $delay = max($expiry - time() - 60, 0);
        $this->renewAt[$session ?? ''] = $expiry - 60;

        // Runs only under an async scheduler; the sync scheduler treats spawn as
        // a no-op and relies on the checks before each call instead. The timer
        // goes through the same deadline as those checks, so a renewal already
        // done (or in flight) is not repeated, and a timer left over from an
        // earlier token finds the deadline moved on.
        $this->context->scheduler->spawn(function () use ($delay, $session): void {
            $this->context->scheduler->delay((float) $delay);
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

        if ($at === null || time() < $at || isset($this->renewing[$key])) {
            return;
        }

        $this->renewing[$key] = true;

        try {
            // Success schedules the next deadline, or clears it if the
            // session ends up signed out; anything else leaves nothing to
            // renew.
            $this->applyAuthentication($session, keepValidToken: true);

            if ($this->renewalDeadline($key) === $at) {
                unset($this->renewAt[$key]);
            }
        } catch (\Throwable $error) {
            // Try again shortly rather than carry on with a token about to
            // expire.
            if ($this->renewalDeadline($key) === $at) {
                $this->renewAt[$key] = time() + self::RENEWAL_RETRY_SECONDS;
            }

            $this->onError(new AuthenticationException('Token renewal failed', $error));
        } finally {
            unset($this->renewing[$key]);
        }
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
     */
    private function applyAuthentication(?string $session, bool $keepValidToken = false): void
    {
        $sessionState = $this->state?->session($session);

        if ($sessionState === null) {
            return;
        }

        if ($this->skipRenewal) {
            $this->abortAuthentication($session);

            return;
        }

        $token = $sessionState->auth->accessToken;

        if ($token !== null) {
            $expiry = Jwt::expiry($token);

            if ($expiry !== null && ($expiry - time()) > 60) {
                try {
                    $this->authenticate($token, $session, true);

                    return;
                } catch (ServerException) {
                    // Fall through to the next strategy.
                }
            }
        }

        if ($sessionState->auth->refreshToken !== null) {
            $tokens = $sessionState->auth->tokens();

            if ($tokens !== null) {
                try {
                    $this->refresh($tokens, $session, true);

                    return;
                } catch (ServerException) {
                    // Fall through to the next strategy.
                }
            }
        }

        if (!$sessionState->auth->overridden && $this->applyAuthProvider($session)) {
            return;
        }

        if ($keepValidToken && $token !== null && (Jwt::expiry($token) ?? PHP_INT_MAX) > time()) {
            return;
        }

        $this->abortAuthentication($session);
    }

    private function applyAuthProvider(?string $session): bool
    {
        if ($this->authProvider === null) {
            return false;
        }

        $computed = $this->resolveProvider($this->authProvider, $session);

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

    private function abortAuthentication(?string $session): void
    {
        $sessionState = $this->state?->session($session);

        if ($sessionState?->auth->accessToken !== null) {
            $this->invalidate($session);
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
        // any; a fork reuses its source's tokens.
        $session = $source === null ? new SessionState(id: $id) : new SessionState(
            id: $id,
            namespace: $source->namespace,
            database: $source->database,
            auth: new AuthState(
                $source->auth->accessToken,
                $source->auth->refreshToken,
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
     * @param bool $fork whether the session's tokens were copied from another
     *                   session, which still holds them
     */
    private function restoreSession(SessionState $session, bool $fork = false): void
    {
        if ($session->namespace !== null || $session->database !== null) {
            $this->use(new NamespaceDatabase($session->namespace, $session->database), $session->id);
        }

        foreach ($session->variables as $name => $value) {
            $this->requireEngine()->set($name, $value, $session->id);
        }

        if ($fork && $this->authenticateWithCopiedToken($session)) {
            return;
        }

        $this->applyAuthentication($session->id);
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
}
