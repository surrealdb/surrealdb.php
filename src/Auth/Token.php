<?php

namespace SurrealDB\Auth;

use SurrealDB\Support\Redacted;

/** A raw JWT/bearer token used with the `authenticate` RPC. */
final readonly class Token
{
    public function __construct(#[\SensitiveParameter] public string $value) {}

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return ['value' => Redacted::value($this->value)];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
