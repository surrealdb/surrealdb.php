<?php

namespace SurrealDB\Middleware;

use SurrealDB\Contracts\Counter;
use SurrealDB\Contracts\Histogram;
use SurrealDB\Contracts\Meter;
use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Contracts\Tracer;
use SurrealDB\Enum\SpanKind;
use SurrealDB\Enum\SpanStatus;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;
use function is_string;

/**
 * Emits a trace span plus duration/count metrics for every RPC. Like
 * {@see LoggingMiddleware} this is a pure observation seam — it never alters the
 * request or response.
 *
 * It depends only on the {@see Tracer} / {@see Meter} contracts, so the same
 * middleware drives OpenTelemetry, the PSR-3 bridge, or any custom backend.
 * Attribute and metric names follow the OpenTelemetry database semantic
 * conventions.
 */
final class TelemetryMiddleware implements MiddlewareInterface
{
    public const DB_SYSTEM = 'surrealdb';

    private readonly Histogram $duration;
    private readonly Counter $operations;

    /**
     * @param bool $recordQueryText include the SurrealQL text as `db.query.text`
     *        on `query` spans. Off by default since queries may carry sensitive
     *        data.
     */
    public function __construct(
        private readonly Tracer $tracer,
        Meter $meter,
        private readonly bool $recordQueryText = false,
    ) {
        $this->duration = $meter->histogram(
            'db.client.operation.duration',
            's',
            'Duration of SurrealDB client operations.',
        );
        $this->operations = $meter->counter(
            'db.client.operation.count',
            '{operation}',
            'Number of SurrealDB client operations.',
        );
    }

    /**
     * @param callable(RpcRequest): RpcResponse<mixed> $next
     *
     * @return RpcResponse<mixed>
     */
    public function process(RpcRequest $request, callable $next): RpcResponse
    {
        $span = $this->tracer->startSpan(
            self::DB_SYSTEM . '.' . $request->method,
            SpanKind::Client,
            $this->spanAttributes($request),
        );

        $startedAt = hrtime(true);
        $outcome = 'ok';

        try {
            $response = $next($request);
            $error = $response->error;

            if ($error !== null) {
                $outcome = 'error';
                $span->setAttribute('error.type', $error->kind ?? 'rpc_error');
                $span->setStatus(SpanStatus::Error, $error->message);
            } else {
                $span->setStatus(SpanStatus::Ok);
            }

            return $response;
        } catch (\Throwable $error) {
            $outcome = 'exception';
            $span->setAttribute('error.type', $error::class);
            $span->recordException($error);
            $span->setStatus(SpanStatus::Error, $error->getMessage());

            throw $error;
        } finally {
            $attributes = [
                'db.system.name' => self::DB_SYSTEM,
                'db.operation.name' => $request->method,
                'outcome' => $outcome,
            ];

            $this->duration->record((hrtime(true) - $startedAt) / 1_000_000_000, $attributes);
            $this->operations->add(1, $attributes);
            $span->end();
        }
    }

    /**
     * @return array<non-empty-string, scalar|array<scalar>|null>
     */
    private function spanAttributes(RpcRequest $request): array
    {
        $attributes = [
            'db.system.name' => self::DB_SYSTEM,
            'db.operation.name' => $request->method,
        ];

        if ($request->session !== null) {
            $attributes['db.surrealdb.session'] = $request->session;
        }

        if ($request->txn !== null) {
            $attributes['db.surrealdb.transaction'] = $request->txn;
        }

        if ($this->recordQueryText
            && $request->method === 'query'
            && isset($request->params[0])
            && is_string($request->params[0])
        ) {
            $attributes['db.query.text'] = $request->params[0];
        }

        return $attributes;
    }
}
