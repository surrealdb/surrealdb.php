<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Scopes;
use SurrealDB\AgentMemory\Payload;

/** Options for {@see Scopes::register()}. */
final readonly class ScopeRegisterOptions
{
    /**
     * @param string      $path        scope path to register (required)
     * @param string|null $displayName human-readable name for the node
     * @param string|null $description free-form description of the node
     */
    public function __construct(
        public string $path,
        public ?string $displayName = null,
        public ?string $description = null,
    ) {
        if (trim($this->path) === '') {
            throw new \InvalidArgumentException('ScopeRegisterOptions requires a non-empty path.');
        }
    }

    /**
     * @internal wire fields for `POST /scopes`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return Payload::compact([
            'path' => $this->path,
            'displayName' => $this->displayName,
            'description' => $this->description,
        ]);
    }
}
