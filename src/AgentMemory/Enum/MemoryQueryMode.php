<?php

namespace SurrealDB\AgentMemory\Enum;

/** Retrieval mode for memory recall (`/query`); `hybrid` by default. */
enum MemoryQueryMode: string
{
    case HYBRID = 'hybrid';
    case VECTOR = 'vector';
    case BM25 = 'bm25';
    case GRAPH = 'graph';
}
