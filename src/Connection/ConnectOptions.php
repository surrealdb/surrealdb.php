<?php

namespace SurrealDB\Connection;

use SurrealDB\Auth\Credentials;
use SurrealDB\Auth\Token;
use SurrealDB\Contracts\AuthProviderInterface;
use SurrealDB\Contracts\ReconnectStrategyInterface;

/** Options customizing a specific connection. */
final class ConnectOptions
{
    /**
     * @param bool $invalidateOnExpiry sign a session out when its access token
     *                                 expires, rather than renew it (with the
     *                                 refresh token or `$authentication`). The
     *                                 token is still reused after a reconnect
     *                                 while it is valid, and `$authentication`
     *                                 still signs a new session in.
     * @param array<string,mixed>|bool|ReconnectStrategyInterface $reconnect
     */
    public function __construct(
        public ?string $namespace = null,
        public ?string $database = null,
        public Credentials|Token|AuthProviderInterface|\Closure|string|null $authentication = null,
        public bool $versionCheck = true,
        public bool $invalidateOnExpiry = false,
        public bool|array|ReconnectStrategyInterface $reconnect = true,
    ) {}
}
