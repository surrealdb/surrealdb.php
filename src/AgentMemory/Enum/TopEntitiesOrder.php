<?php

namespace SurrealDB\AgentMemory\Enum;

/** Ordering for the top-entities listing; `coverage` by default. */
enum TopEntitiesOrder: string
{
    /** Most facts first. */
    case COVERAGE = 'coverage';
    /** Highest importance first. */
    case IMPORTANCE = 'importance';
    /** Most recently learned about first. */
    case RECENCY = 'recency';
}
