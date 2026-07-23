<?php

namespace SurrealDB\Spectron\Auth;

/**
 * A Spectron authentication strategy. Each implementation contributes the
 * header(s) that authenticate every request; adding a new scheme means adding a
 * new implementation, never editing the client (Open/Closed).
 */
interface AuthenticationInterface
{
    /**
     * The header(s) this strategy adds to every Spectron request.
     *
     * @return array<string,string>
     */
    public function headers(): array;
}
