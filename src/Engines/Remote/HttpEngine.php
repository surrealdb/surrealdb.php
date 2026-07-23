<?php

namespace SurrealDB\Engines\Remote;

use SurrealDB\Connection\ConnectionState;
use SurrealDB\Contracts\TransportInterface;
use SurrealDB\Engines\AbstractRpcEngine;
use SurrealDB\Exceptions\MissingNamespaceDatabaseException;
use SurrealDB\Exceptions\UnsupportedFeatureException;
use SurrealDB\Protocol\FeatureSet;
use SurrealDB\Protocol\Features;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use SurrealDB\Transport\HttpTransport;
use function in_array;
use function is_array;

/**
 * An engine that issues each RPC as an individual HTTP request. Stateful
 * operations the stateless protocol cannot honor (`use`/`let`/...) are no-ops,
 * mirroring the JS HTTP engine.
 */
final class HttpEngine extends AbstractRpcEngine
{
    private const array ALWAYS_ALLOW = [
        'use', 'signin', 'signup', 'authenticate', 'version', 'query', 'info', 'health',
    ];

    private ?TransportInterface $transport = null;

    public function features(): FeatureSet
    {
        return new FeatureSet(
            Features::refreshTokens(),
            Features::api(),
            Features::exportImportRaw(),
            Features::surrealMl(),
        );
    }

    public function open(ConnectionState $state): void
    {
        $this->state = $state;
        $factory = $this->context->options->httpTransportFactory;
        $this->transport = $factory !== null
            ? ($factory)($this->context, $state)
            : new HttpTransport($this->context, $state);
        $this->transport->open();
        $this->publisher->publish('connected');
    }

    public function close(): void
    {
        $this->transport?->close();
        $this->transport = null;
        $this->state = null;
        $this->publisher->publish('disconnected');
    }

    public function ready(): void
    {
        // No queued calls for the HTTP engine.
    }

    public function liveQuery(string $id): iterable
    {
        throw new UnsupportedFeatureException(Features::liveQueries());
    }

    /**
     * @return RpcResponse<mixed>
     */
    protected function send(RpcRequest $request): RpcResponse
    {
        $state = $this->requireState();

        switch ($request->method) {
            case 'use':
                if (!$this->isEmptyUse($request->params)) {
                    return new RpcResponse($request->id);
                }

                break;
            case 'let':
            case 'unset':
            case 'reset':
            case 'invalidate':
                return new RpcResponse($request->id);
        }

        $session = $state->session($request->session) ?? $state->rootSession;

        if (($session->namespace === null || $session->database === null)
            && !in_array($request->method, self::ALWAYS_ALLOW, true)
        ) {
            throw new MissingNamespaceDatabaseException();
        }

        if ($request->method === 'query') {
            $params = $request->params;
            $bindings = is_array($params[1] ?? null) ? $params[1] : [];
            $mergedBindings = [...$session->variables, ...$bindings];
            $params[1] = $mergedBindings !== [] ? $mergedBindings : null;
            $request = $request->withParams($params);
        }

        return $this->transport->send($request);
    }

    /**
     * @param list<mixed> $params
     */
    private function isEmptyUse(array $params): bool
    {
        return ($params[0] ?? null) === null && ($params[1] ?? null) === null;
    }
}
