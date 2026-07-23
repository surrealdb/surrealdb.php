<?php

namespace SurrealDB\Spectron\Enum;

/** Memory category classification applied during extraction. */
enum MemoryCategory: string
{
    case IDENTITY = 'identity';
    case KNOWLEDGE = 'knowledge';
    case CONTEXT = 'context';
}
