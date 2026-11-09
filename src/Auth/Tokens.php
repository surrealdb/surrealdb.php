<?php

namespace SurrealDB\SDK\Auth;

use SurrealDB\SDK\Support\Redacted;

/** An access token and optional refresh token pair. */
final readonly class Tokens
{
    public function __construct(
        #[\SensitiveParameter]
        public ?string $access = null,
        #[\SensitiveParameter]
        public ?string $refresh = null,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return ['access' => Redacted::value($this->access), 'refresh' => Redacted::value($this->refresh)];
    }

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
