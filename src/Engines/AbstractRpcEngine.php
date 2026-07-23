<?php

namespace SurrealDB\Engines;

use Psr\Log\NullLogger;
use SurrealDB\Auth\Tokens;
use SurrealDB\Connection\ConnectionState;
use SurrealDB\Connection\DriverContext;
use SurrealDB\Connection\SessionState;
use SurrealDB\Contracts\EngineInterface;
use SurrealDB\Contracts\HttpConnectionInterface;
use SurrealDB\Events\RpcRequestSent;
use SurrealDB\Events\RpcResponseReceived;
use SurrealDB\Exceptions\ConnectionUnavailableException;
use SurrealDB\Http\HttpConnection;
use SurrealDB\Http\PsrHttpClientProvider;
use SurrealDB\Middleware\LoggingMiddleware;
use SurrealDB\Middleware\MiddlewarePipeline;
use SurrealDB\Middleware\TelemetryMiddleware;
use SurrealDB\Protocol\NamespaceDatabase;
use SurrealDB\Protocol\QueryChunk;
use SurrealDB\Protocol\VersionInfo;
use SurrealDB\Query\BoundQuery;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use SurrealDB\Support\Publisher;
use SurrealDB\Telemetry\NullMeter;
use SurrealDB\Telemetry\NullTracer;
use function is_array;
use function is_string;

/**
 * Implements the SurrealDB protocol by translating every operation into a
 * single `send()` primitive, wrapped by the middleware pipeline. Concrete
 * engines (WebSocket, HTTP) only implement transport `send()` and lifecycle.
 *
 * Port of the JS SDK `RpcEngine`.
 */
abstract class AbstractRpcEngine implements EngineInterface
{
    protected readonly MiddlewarePipeline $pipeline;
    protected readonly Publisher $publisher;
    protected ?ConnectionState $state = null;

    private ?HttpConnectionInterface $http = null;

    public function __construct(protected readonly DriverContext $context)
    {
        $this->publisher = new Publisher();
        $this->pipeline = $this->buildPipeline();
    }

    public function subscribe(string $event, callable $listener): \Closure
    {
        return $this->publisher->subscribe($event, $listener);
    }

    // ----------------------------------------------------------------- //
    //  Transport primitive + lifecycle (implemented by concrete engines) //
    // ----------------------------------------------------------------- //

    /**
     * @return RpcResponse<mixed>
     */
    abstract protected function send(RpcRequest $request): RpcResponse;

    // ----------------------------------------------------------------- //
    //  Protocol operations                                               //
    // ----------------------------------------------------------------- //

    public function health(): void
    {
        $state = $this->requireState();

        $this->http()->request(
            $state->endpoint->httpUriWithPath($state->endpoint->basePath() . '/health'),
            'GET',
            null,
            $this->httpHeaders($state->rootSession, accept: 'application/json'),
        );
    }

    public function version(): VersionInfo
    {
        return new VersionInfo((string) $this->dispatch(new RpcRequest('version')));
    }

    public function sessions(): array
    {
        $result = $this->dispatch(new RpcRequest('sessions'));

        return is_array($result) ? array_map(strval(...), $result) : [];
    }

    public function attach(string $session): void
    {
        $this->dispatch(new RpcRequest('attach', [], $session));
    }

    public function detach(string $session): void
    {
        $this->dispatch(new RpcRequest('detach', [], $session));
    }

    public function use(?NamespaceDatabase $what, ?string $session): NamespaceDatabase
    {
        $namespace = $what?->namespace;
        $database = $what?->database;

        $result = $this->dispatch(new RpcRequest('use', [$namespace, $database], $session));

        if (is_array($result)) {
            return new NamespaceDatabase($result['namespace'] ?? $namespace, $result['database'] ?? $database);
        }

        return new NamespaceDatabase($namespace, $database);
    }

    public function signup(array $auth, ?string $session): Tokens
    {
        return $this->parseTokens($this->dispatch(new RpcRequest('signup', [$auth], $session)));
    }

    public function signin(array $auth, ?string $session): Tokens
    {
        return $this->parseTokens($this->dispatch(new RpcRequest('signin', [$auth], $session)));
    }

    public function authenticate(string $token, ?string $session): void
    {
        $this->dispatch(new RpcRequest('authenticate', [$token], $session));
    }

    public function set(string $name, mixed $value, ?string $session): void
    {
        $this->dispatch(new RpcRequest('let', [$name, $value], $session));
    }

    public function unset(string $name, ?string $session): void
    {
        $this->dispatch(new RpcRequest('unset', [$name], $session));
    }

    public function refresh(Tokens $tokens, ?string $session): Tokens
    {
        return $this->parseTokens($this->dispatch(new RpcRequest('refresh', [$tokens->toArray()], $session)));
    }

    public function revoke(Tokens $tokens, ?string $session): void
    {
        $this->dispatch(new RpcRequest('revoke', [$tokens->toArray()], $session));
    }

    public function invalidate(?string $session): void
    {
        $this->dispatch(new RpcRequest('invalidate', [], $session));
    }

    public function reset(?string $session): void
    {
        $this->dispatch(new RpcRequest('reset', [], $session));
    }

    public function begin(?string $session): string
    {
        return (string) $this->dispatch(new RpcRequest('begin', [], $session));
    }

    public function commit(string $txn, ?string $session): void
    {
        $this->dispatch(new RpcRequest('commit', [$txn], $session));
    }

    public function cancel(string $txn, ?string $session): void
    {
        $this->dispatch(new RpcRequest('cancel', [$txn], $session));
    }

    public function query(BoundQuery $query, ?string $session, ?string $txn = null): iterable
    {
        $bindings = $query->bindings !== [] ? $query->bindings : null;
        $responses = $this->dispatch(new RpcRequest('query', [$query->query, $bindings], $session, $txn));

        if (!is_array($responses)) {
            return;
        }

        $index = 0;

        foreach ($responses as $response) {
            if (is_array($response)) {
                yield QueryChunk::fromResult($index++, $response);
            }
        }
    }

    public function importSql(string $data): void
    {
        $state = $this->requireState();
        $url = $state->endpoint->httpUriWithPath($state->endpoint->basePath() . '/import');

        $this->http()->request($url, 'POST', $data, $this->httpHeaders($state->rootSession, accept: 'application/json'));
    }

    public function exportSql(array $options): string
    {
        $state = $this->requireState();
        $url = $state->endpoint->httpUriWithPath($state->endpoint->basePath() . '/export');
        $body = $this->context->codec->serialize($options !== [] ? $options : null);

        $response = $this->http()->request($url, 'POST', $body, $this->httpHeaders(
            $state->rootSession,
            contentType: $this->context->format->contentType(),
            accept: 'text/plain',
        ));

        return (string) $response->getBody();
    }

    // ----------------------------------------------------------------- //
    //  Internals                                                         //
    // ----------------------------------------------------------------- //

    /**
     * The choke point: assign an id, broadcast, run the middleware pipeline
     * around the transport `send()`, broadcast, and unwrap the result.
     */
    protected function dispatch(RpcRequest $request): mixed
    {
        $request = $request->withId($this->context->uniqueId());

        $this->context->events->dispatch(new RpcRequestSent($request));

        $response = $this->pipeline->process(
            $request,
            fn (RpcRequest $req): RpcResponse => $this->send($req),
        );

        $this->context->events->dispatch(new RpcResponseReceived($request, $response));

        return $response->resultOrThrow();
    }

    protected function parseTokens(mixed $response): Tokens
    {
        if (is_string($response)) {
            return new Tokens($response);
        }

        if (is_array($response)) {
            $access = $response['access'] ?? ($response['token'] ?? null);

            return new Tokens(
                is_string($access) ? $access : null,
                isset($response['refresh']) && is_string($response['refresh']) ? $response['refresh'] : null,
            );
        }

        return new Tokens();
    }

    protected function requireState(): ConnectionState
    {
        if ($this->state === null) {
            throw new ConnectionUnavailableException();
        }

        return $this->state;
    }

    /**
     * @return array<string,string>
     */
    protected function httpHeaders(SessionState $session, ?string $contentType = null, string $accept = 'application/json'): array
    {
        $headers = ['Accept' => $accept];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        if ($session->namespace !== null) {
            $headers['Surreal-NS'] = $session->namespace;
        }

        if ($session->database !== null) {
            $headers['Surreal-DB'] = $session->database;
        }

        if ($session->auth->accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $session->auth->accessToken;
        }

        return $headers;
    }

    protected function http(): HttpConnectionInterface
    {
        return $this->http ??= new HttpConnection(new PsrHttpClientProvider(
            $this->context->options->httpClient,
            $this->context->options->requestFactory,
            $this->context->options->streamFactory,
        ));
    }

    private function buildPipeline(): MiddlewarePipeline
    {
        $pipeline = new MiddlewarePipeline();

        // Auto-enable the debugger when a real logger is configured.
        if (!$this->context->logger instanceof NullLogger) {
            $pipeline->push(new LoggingMiddleware($this->context->logger));
        }

        // Auto-enable telemetry when a real tracer or meter is configured.
        if (!$this->context->tracer instanceof NullTracer || !$this->context->meter instanceof NullMeter) {
            $pipeline->push(new TelemetryMiddleware($this->context->tracer, $this->context->meter));
        }

        foreach ($this->context->options->middleware as $middleware) {
            $pipeline->push($middleware);
        }

        return $pipeline;
    }
}
