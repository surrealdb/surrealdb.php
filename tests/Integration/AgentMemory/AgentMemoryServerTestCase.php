<?php

namespace SurrealDB\Tests\Integration\AgentMemory;

use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;

/**
 * Base for the tests that run against a real Agent Memory server.
 *
 * Configure the server with `AGENT_MEMORY_ENDPOINT`, `AGENT_MEMORY_CONTEXT`,
 * and `AGENT_MEMORY_API_KEY` (an admin key for the context, as
 * `scripts/agent-memory-server.sh` provisions). Without them the tests skip;
 * CI sets `AGENT_MEMORY_REQUIRE_SERVER=1` so a missing or unreachable server
 * fails the build instead.
 *
 * Every test class writes under its own run-unique scope and entity names, so
 * repeated runs against one long-lived server never see each other's rows.
 */
abstract class AgentMemoryServerTestCase extends TestCase
{
    /** Lowercase, run-unique suffix for entity names and scope paths. */
    protected static string $run = '';

    /** The scope node this class writes into, registered before the first test. */
    protected static string $scope = '';

    private static ?AgentMemory $memory = null;

    public static function setUpBeforeClass(): void
    {
        self::$memory = null;
        static::$run = bin2hex(random_bytes(4));
        static::$scope = 'php_sdk/r' . static::$run;

        static::memory()->scopes->register(new ScopeRegisterOptions(static::$scope, displayName: 'PHP SDK integration run'));
    }

    /** The client for the configured server, verified reachable. */
    protected static function memory(): AgentMemory
    {
        if (self::$memory !== null) {
            return self::$memory;
        }

        [$endpoint, $context, $apiKey] = [self::env('AGENT_MEMORY_ENDPOINT'), self::env('AGENT_MEMORY_CONTEXT'), self::env('AGENT_MEMORY_API_KEY')];

        if ($endpoint === null || $context === null || $apiKey === null) {
            static::unavailable('Set AGENT_MEMORY_ENDPOINT, AGENT_MEMORY_CONTEXT and AGENT_MEMORY_API_KEY to run the Agent Memory integration tests (see scripts/agent-memory-server.sh).');
        }

        $memory = static::client($apiKey);

        try {
            $memory->health();
        } catch (AgentMemoryException $e) {
            static::unavailable("Agent Memory server at {$endpoint} is not reachable: {$e->getMessage()}");
        }

        return self::$memory = $memory;
    }

    /** A client for the configured server and context, authenticated with `$apiKey`. */
    protected static function client(string $apiKey, ?string $context = null): AgentMemory
    {
        return new AgentMemory(new AgentMemoryOptions(
            (string) self::env('AGENT_MEMORY_ENDPOINT'),
            $context ?? (string) self::env('AGENT_MEMORY_CONTEXT'),
            $apiKey,
        ));
    }

    /** A run-unique entity ref, e.g. `person/alice1a2b3c4d`. */
    protected static function entity(string $type, string $name): string
    {
        return "{$type}/{$name}" . static::$run;
    }

    protected static function unavailable(string $reason): never
    {
        if (getenv('AGENT_MEMORY_REQUIRE_SERVER') === '1') {
            self::fail($reason);
        }

        self::markTestSkipped($reason);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
