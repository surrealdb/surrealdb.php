<?php

namespace SurrealDB\AgentMemory\Enum;

/** Kind of trace an audit row records. */
enum TraceKind: string
{
    case DECISION = 'decision';
    case RETRIEVAL = 'retrieval';
    case RESPONSE = 'response';
}
