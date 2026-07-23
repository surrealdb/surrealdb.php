<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Sessions;
use SurrealDB\Spectron\Payload;
use SurrealDB\Spectron\Scope;

/** Options for {@see Sessions::create()}. */
final readonly class SessionCreateOptions
{
    /**
     * @param string|array<int,string|list<string>>|null $scopes   DNF scope selector the session writes to (outer OR, inner AND)
     * @param mixed                                      $metadata free-form metadata stored on the session
     */
    public function __construct(
        public string|array|null $scopes = null,
        public mixed $metadata = null,
    ) {}

    /**
     * @internal wire fields for `POST /sessions`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'scopes' => Scope::normalise($this->scopes),
            'metadata' => $this->metadata,
        ]);
    }
}
