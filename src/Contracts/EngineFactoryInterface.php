<?php

namespace SurrealDB\SDK\Contracts;

use SurrealDB\SDK\Connection\DriverContext;

/**
 * Constructs an engine for a connection. The {@see \SurrealDB\SDK\Engines\EngineRegistry}
 * accepts either an implementation of this interface or a plain
 * `callable(DriverContext): EngineInterface` factory.
 */
interface EngineFactoryInterface
{
    public function create(DriverContext $context): EngineInterface;
}
