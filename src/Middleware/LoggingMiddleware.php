<?php

namespace SurrealDB\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/**
 * The built-in debugger: logs every RPC request, its outcome, and timing to a
 * PSR-3 logger. This is a pure observation/interception seam — it never alters
 * the request or response.
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $level = LogLevel::DEBUG,
    ) {}

    /**
     * @param callable(RpcRequest): RpcResponse<mixed> $next
     *
     * @return RpcResponse<mixed>
     */
    public function process(RpcRequest $request, callable $next): RpcResponse
    {
        $startedAt = hrtime(true);

        $this->logger->log($this->level, 'SurrealDB RPC -> {method}', [
            'method' => $request->method,
            'session' => $request->session,
            'txn' => $request->txn,
        ]);

        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            $this->logger->error('SurrealDB RPC x {method}: {error}', [
                'method' => $request->method,
                'error' => $error->getMessage(),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            throw $error;
        }

        if ($response->isError()) {
            $this->logger->warning('SurrealDB RPC ! {method}: {error}', [
                'method' => $request->method,
                'error' => $response->error?->message,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
        } else {
            $this->logger->log($this->level, 'SurrealDB RPC <- {method} ({duration_ms} ms)', [
                'method' => $request->method,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
        }

        return $response;
    }

    private function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}
