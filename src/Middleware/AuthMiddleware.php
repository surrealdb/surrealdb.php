<?php

namespace SurrealDB\Middleware;

use SurrealDB\Contracts\MiddlewareInterface;
use SurrealDB\Rpc\RpcError;
use SurrealDB\Rpc\RpcRequest;
use SurrealDB\Rpc\RpcResponse;

/**
 * Auth as a cross-cutting concern: when a call fails with an authentication
 * error, invoke the re-authentication callback (typically wired to the
 * connection controller) and transparently retry the call once.
 *
 * Static bearer/namespace headers for the HTTP protocol are applied lower down
 * by the transport from session state; this seam covers reactive renewal.
 */
final class AuthMiddleware implements MiddlewareInterface
{
	/**
	 * @param \Closure(?string $session): bool $reauthenticate returns true when re-auth succeeded
	 */
	public function __construct(private readonly \Closure $reauthenticate) {}

	/**
	 * @param callable(RpcRequest): RpcResponse<mixed> $next
	 *
	 * @return RpcResponse<mixed>
	 */
	public function process(RpcRequest $request, callable $next): RpcResponse
	{
		$response = $next($request);

		if (
			$response->isError() &&
			$this->isAuthError($response->error) &&
			($this->reauthenticate)($request->session) === true
		) {
			return $next($request);
		}

		return $response;
	}

	private function isAuthError(?RpcError $error): bool
	{
		if ($error === null) {
			return false;
		}

		if ($error->kind === "NotAllowed") {
			return true;
		}

		$message = strtolower($error->message);

		return (str_contains($message, "token") &&
			str_contains($message, "expired")) ||
			str_contains($message, "not authenticated") ||
			str_contains($message, "unauthorized");
	}
}
