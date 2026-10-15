<?php

namespace SurrealDB\Contracts;

use SurrealDB\Auth\Credentials;
use SurrealDB\Auth\Token;

/**
 * Supplies authentication details for a session on (re)connect. Unlike an
 * explicit `signin()`, a provider may be re-invoked whenever a session needs to
 * be (re)authenticated, e.g. after a reconnect or token expiry.
 */
interface AuthProviderInterface
{
    /**
     * Resolve credentials, a raw token, or null to remain unauthenticated.
     */
    public function resolve(?string $session): Credentials|Token|string|null;
}
