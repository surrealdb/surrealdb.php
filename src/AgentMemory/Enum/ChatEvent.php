<?php

namespace SurrealDB\AgentMemory\Enum;

use SurrealDB\AgentMemory\Streaming\ChatChunk;
use SurrealDB\AgentMemory\Streaming\ChatStreamParser;

/**
 * SSE event names of a streaming `chat` response ({@see ChatChunk::$event}).
 * The server's `error` event never becomes a chunk: {@see ChatStreamParser}
 * raises it as a StreamException.
 */
enum ChatEvent: string
{
    /** First frame: the trace and session ids. */
    case META = 'meta';
    /** A piece of reply text. */
    case CHUNK = 'chunk';
    /** The terminal frame: the full reply, its citations, and the memory updates. */
    case DONE = 'done';
}
