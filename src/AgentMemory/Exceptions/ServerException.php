<?php

namespace SurrealDB\AgentMemory\Exceptions;

/**
 * Server error after any retries were exhausted (5xx). A `503` (load shedding,
 * or a dependency the server could not reach, with no work done) is retried for
 * every request, so reaching this means the server stayed unavailable;
 * {@see AgentMemoryException::$retryAfter} says how long it asked callers to wait.
 */
final class ServerException extends AgentMemoryException {}
