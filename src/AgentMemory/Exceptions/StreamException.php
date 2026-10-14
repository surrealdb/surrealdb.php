<?php

namespace SurrealDB\AgentMemory\Exceptions;

/**
 * The server reported a failure mid-stream (an SSE `error` event) after the
 * response had already started, so no HTTP status describes it.
 */
final class StreamException extends AgentMemoryException {}
