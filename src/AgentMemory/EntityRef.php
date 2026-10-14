<?php

namespace SurrealDB\AgentMemory;

use function strlen;

/**
 * Names one entity by type and name: `person/alice`.
 *
 * The API takes this `<type>/<name>` shorthand wherever it filters or anchors
 * on an entity (`/attributes?entity=`, `/actions?actor=`, `lookup` subjects,
 * `elaborate`), and returns it inside kind-tagged refs such as
 * `entity:person/alice`. {@see parse()} accepts both spellings, so a ref read
 * off a response can be fed straight back in. Every option that takes an
 * entity accepts either an `EntityRef` or the plain string.
 */
final readonly class EntityRef implements \Stringable
{
    private const string TAG = 'entity:';

    public function __construct(
        public string $type,
        public string $name,
    ) {
        if (trim($this->type) === '' || str_contains($this->type, '/')) {
            throw new \InvalidArgumentException("EntityRef type must be a non-empty segment without '/', got \"{$this->type}\".");
        }

        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('EntityRef requires a non-empty name.');
        }
    }

    /**
     * Parses `<type>/<name>` (or the tagged `entity:<type>/<name>` responses
     * carry). The type ends at the first `/`, so the name may contain more.
     */
    public static function parse(string $ref): self
    {
        $bare = str_starts_with($ref, self::TAG) ? substr($ref, strlen(self::TAG)) : $ref;
        $slash = strpos($bare, '/');

        if ($slash === false) {
            throw new \InvalidArgumentException("Entity refs have the form `<type>/<name>`, got \"{$ref}\".");
        }

        return new self(substr($bare, 0, $slash), substr($bare, $slash + 1));
    }

    /** The `<type>/<name>` shorthand. */
    public function __toString(): string
    {
        return "{$this->type}/{$this->name}";
    }

    /** The kind-tagged `entity:<type>/<name>` form, as responses carry it and `inspect()` reads it. */
    public function tagged(): string
    {
        return self::TAG . $this;
    }

    /**
     * @internal the `{type, name}` object a triple carries on the wire
     *
     * @return array{type:string,name:string}
     */
    public function toPayload(): array
    {
        return ['type' => $this->type, 'name' => $this->name];
    }
}
