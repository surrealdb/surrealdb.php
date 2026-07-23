<?php

namespace SurrealDB\Spectron\Components;

use SurrealDB\Spectron\Options\SessionCreateOptions;
use SurrealDB\Spectron\Paths;
use SurrealDB\Spectron\Transport;

/** Creates and manages conversation sessions for a context. */
final class Sessions
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $contextId,
    ) {}

    /** Opens a new session with an optional DNF scope selector and metadata. */
    public function create(?SessionCreateOptions $options = null): Session
    {
        $info = $this->transport->requestJson(
            'POST',
            Paths::contextApiPrefix($this->contextId) . '/sessions',
            body: $options?->toPayload() ?? [],
        ) ?? [];

        return new Session($this->transport, $this->contextId, $info);
    }
}
