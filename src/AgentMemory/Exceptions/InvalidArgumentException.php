<?php

namespace SurrealDB\AgentMemory\Exceptions;

use SurrealDB\Exceptions\SurrealExceptionInterface;

/**
 * Thrown client-side, before any request is sent, when an argument or option
 * is blank, malformed or contradictory. It extends PHP's
 * `\InvalidArgumentException`, so existing `catch` blocks keep working. It is
 * not an {@see AgentMemoryException}: the server never saw the request
 * (compare {@see ValidationException}, the server's 400/422).
 */
final class InvalidArgumentException extends \InvalidArgumentException implements SurrealExceptionInterface {}
