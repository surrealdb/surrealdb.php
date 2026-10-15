<?php

namespace SurrealDB\AgentMemory\Contracts;

use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\KeyCreateOptions;
use SurrealDB\AgentMemory\Options\KeyRotateOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Page;

/** Self-service API keys for this context (requires the `manage` grant). */
interface KeysInterface
{
    /**
     * Mints a new key. The full bearer secret (`sp-{id}-{secret}`) is returned
     * once in the `key` field and cannot be retrieved again.
     *
     * @return array<int|string,mixed>
     */
    public function create(?KeyCreateOptions $options = null): array;

    /** A page of key metadata for the context (secrets are never included). */
    public function list(?PageOptions $options = null): Page;

    /**
     * Every key the caller can see, walking the pages in order (`GET /keys`).
     *
     * @return list<array<string,mixed>>
     *
     * @throws InvalidArgumentException when `$options` sets `cursor` or `count`
     */
    public function listAll(?PageOptions $options = null): array;

    /** Revokes a key by name. */
    public function delete(string $keyName): void;

    /**
     * Rotates a key, returning a fresh secret in the `key` field.
     *
     * @return array<int|string,mixed>
     */
    public function rotate(string $keyName, ?KeyRotateOptions $options = null): array;
}
