<?php

namespace SurrealDB\AgentMemory\Exceptions;

/** Rate or token budget exceeded (429); see {@see AgentMemoryException::$retryAfter}. */
final class RateLimitException extends AgentMemoryException {}
