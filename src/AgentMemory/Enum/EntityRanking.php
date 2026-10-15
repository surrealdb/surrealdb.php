<?php

namespace SurrealDB\AgentMemory\Enum;

use SurrealDB\AgentMemory\Components\Entities;

/** How {@see Entities::top()} ranks entities; `coverage` by default. */
enum EntityRanking: string
{
    /** Most facts first. */
    case COVERAGE = 'coverage';
    /** Highest importance first. */
    case IMPORTANCE = 'importance';
    /** Most recently learned about first. */
    case RECENCY = 'recency';
}
