<?php

namespace SurrealDB\AgentMemory\Enum;

/** Scope read breadth for memory queries. */
enum ScopeView: string
{
    case STRICT = 'strict';
    case MERGED = 'merged';
    case CROSS_TEAM = 'crossTeam';
}
