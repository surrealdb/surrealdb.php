<?php

namespace SurrealDB\SDK\Auth;

/** An access token and optional refresh token pair. */
final readonly class Tokens
{
    public function __construct(
        public ?string $access = null,
        public ?string $refresh = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->access !== null) {
            $out['access'] = $this->access;
        }

        if ($this->refresh !== null) {
            $out['refresh'] = $this->refresh;
        }

        return $out;
    }
}
