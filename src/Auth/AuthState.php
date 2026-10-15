<?php

namespace SurrealDB\SDK\Auth;

/**
 * Mutable per-session authentication state owned by the connection controller.
 *
 * `overridden` marks that an explicit signin/signup/authenticate took place,
 * in which case a configured auth provider must not silently take over.
 * `generation` counts explicit changes (sign-in, sign-out, reset), so that a
 * renewal running meanwhile can tell it was overtaken.
 */
final class AuthState
{
    public int $generation = 0;

    public function __construct(
        public ?string $accessToken = null,
        public ?string $refreshToken = null,
        public bool $overridden = false,
    ) {}

    public function tokens(): ?Tokens
    {
        if ($this->accessToken === null) {
            return null;
        }

        return new Tokens($this->accessToken, $this->refreshToken);
    }

    public function clear(): void
    {
        $this->accessToken = null;
        $this->refreshToken = null;
    }
}
