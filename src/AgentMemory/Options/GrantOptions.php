<?php

namespace SurrealDB\AgentMemory\Options;

use SurrealDB\AgentMemory\Components\Principals;
use SurrealDB\AgentMemory\Enum\Verb;

/** Options for {@see Principals::grant()} and {@see Principals::revoke()}. */
final readonly class GrantOptions
{
    /**
     * @param string            $path  scope pattern the grant applies to (required): an exact node
     *                                 (`team/eng`) or a subtree (`team/*`, `/*`)
     * @param list<Verb|string> $verbs `<noun>:<verb>` verbs to grant or revoke (required, non-empty),
     *                                 e.g. {@see Verb::MEMORY_READ}
     */
    public function __construct(
        public string $path,
        public array $verbs,
    ) {
        if (trim($this->path) === '') {
            throw new \InvalidArgumentException('GrantOptions requires a non-empty path.');
        }

        if ($this->verbs === []) {
            throw new \InvalidArgumentException('GrantOptions requires at least one verb.');
        }
    }

    /**
     * @internal wire fields for `POST|DELETE /principals/{id}/grants`
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'path' => $this->path,
            'verbs' => array_map(
                static fn (Verb|string $verb): string => $verb instanceof Verb ? $verb->value : $verb,
                $this->verbs,
            ),
        ];
    }
}
