<?php

namespace SurrealDB\AgentMemory\Exceptions;

/**
 * A streaming `chat` failed after the response had started, so no HTTP status
 * describes it. Either the server sent an SSE `error` event (the `detail`
 * carries its message), or the stream ended before its terminal `done` frame
 * because the connection dropped or the server's task died. A partial reply
 * is never passed off as complete.
 */
final class StreamException extends AgentMemoryException {}
