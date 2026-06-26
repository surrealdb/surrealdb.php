<?php

namespace SurrealDB\SDK\Auth;

/** A raw JWT/bearer token used with the `authenticate` RPC. */
final readonly class Token
{
    public function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
