<?php

namespace SurrealDB\AgentMemory\Exceptions;

/**
 * The request conflicts with existing state (409): an `Idempotency-Key` reused
 * with a different body, a duplicate name, or a per-fact label cap reached.
 * An idempotent write whose first attempt is still running under the same key
 * is retried first; seeing this for one means that attempt outlasted the
 * retries, and it may still complete on the server.
 */
final class ConflictException extends AgentMemoryException {}
