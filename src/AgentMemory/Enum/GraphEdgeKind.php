<?php

namespace SurrealDB\AgentMemory\Enum;

/** Document-graph edge kinds followed when a document search expands the graph. */
enum GraphEdgeKind: string
{
    case KNOWLEDGE_HAS_KEYWORD = 'knowledge_has_keyword';
    case SECTION_MATCH = 'section_match';
    case DOCUMENT_LINK = 'document_link';
    case DOCUMENT_SUMMARY = 'document_summary';
    case HYBRID_GRAPH = 'hybrid_graph';
}
