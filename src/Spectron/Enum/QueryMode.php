<?php

namespace SurrealDB\Spectron\Enum;

/** Chunk query mode for `/documents/query`. */
enum QueryMode: string
{
    case HYBRID = 'hybrid';
    case VECTOR = 'vector';
    case BM25 = 'bm25';
    case HYBRID_GRAPH = 'hybrid_graph';
}
