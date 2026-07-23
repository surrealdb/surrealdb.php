<?php

namespace SurrealDB\Spectron\Options;

use SurrealDB\Spectron\Components\Principals;
use SurrealDB\Spectron\Enum\Verb;

/** Options for {@see Principals::grant()} and {@see Principals::revoke()}. */
final readonly class GrantOptions
{
    /**
     * @param string            $path  scope pattern the grant applies to (required)
     * @param list<Verb|string> $verbs verbs to grant or revoke (required, non-empty)
     */
    public function __construct(
        public string $path,
        public array $verbs,
    ) {
        if ($this->path === '') {
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
