<?php

namespace SurrealDB\SDK\Engines;

use SurrealDB\SDK\Connection\DriverContext;
use SurrealDB\SDK\Contracts\EngineFactoryInterface;
use SurrealDB\SDK\Contracts\EngineInterface;
use SurrealDB\SDK\Engines\Remote\HttpEngine;
use SurrealDB\SDK\Engines\Remote\WebSocketEngine;
use SurrealDB\SDK\Exceptions\UnsupportedEngineException;

/**
 * Maps URL schemes to engine factories. Port of the JS `createRemoteEngines`
 * map + scheme resolution. Factories may be plain callables or
 * {@see EngineFactoryInterface} instances.
 */
final class EngineRegistry
{
    /** @var array<string,callable|EngineFactoryInterface> */
    private array $factories;

    /**
     * @param array<string,callable|EngineFactoryInterface> $factories
     */
    public function __construct(array $factories = [])
    {
        $this->factories = $factories !== [] ? $factories : self::remoteEngines();
    }

    /**
     * The default `ws`/`wss`/`http`/`https` engine factories.
     *
     * @return array<string,\Closure(DriverContext): EngineInterface>
     */
    public static function remoteEngines(): array
    {
        return [
            'ws' => static fn (DriverContext $context): EngineInterface => new WebSocketEngine($context),
            'wss' => static fn (DriverContext $context): EngineInterface => new WebSocketEngine($context),
            'http' => static fn (DriverContext $context): EngineInterface => new HttpEngine($context),
            'https' => static fn (DriverContext $context): EngineInterface => new HttpEngine($context),
        ];
    }

    public function register(string $scheme, callable|EngineFactoryInterface $factory): void
    {
        $this->factories[$scheme] = $factory;
    }

    public function supports(string $scheme): bool
    {
        return isset($this->factories[$scheme]);
    }

    public function create(string $scheme, DriverContext $context): EngineInterface
    {
        $factory = $this->factories[$scheme] ?? throw new UnsupportedEngineException($scheme);

        if ($factory instanceof EngineFactoryInterface) {
            return $factory->create($context);
        }

        return $factory($context);
    }
}
