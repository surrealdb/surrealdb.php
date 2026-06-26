<?php

namespace SurrealDB\SDK\Auth;

/**
 * Credentials that can be turned into the parameter object expected by the
 * `signin` / `signup` RPC methods.
 */
interface Credentials
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
