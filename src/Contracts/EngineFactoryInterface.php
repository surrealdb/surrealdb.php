<?php

namespace SurrealDB\Contracts;

use SurrealDB\Connection\DriverContext;

/**
 * Constructs an engine for a connection. The {@see \SurrealDB\Engines\EngineRegistry}
 * accepts either an implementation of this interface or a plain
 * `callable(DriverContext): EngineInterface` factory.
 */
interface EngineFactoryInterface
{
    public function create(DriverContext $context): EngineInterface;
}
