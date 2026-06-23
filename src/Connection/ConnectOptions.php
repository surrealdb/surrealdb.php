<?php

namespace SurrealDB\SDK\Connection;

use SurrealDB\SDK\Auth\Credentials;
use SurrealDB\SDK\Auth\Token;
use SurrealDB\SDK\Contracts\AuthProviderInterface;
use SurrealDB\SDK\Contracts\ReconnectStrategyInterface;

/** Options customizing a specific connection. */
final class ConnectOptions
{
    /**
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
