<?php

namespace SurrealDB\AgentMemory\Enum;

/** Bulk extraction strategy for `/facts/batch`. */
enum BatchExtractionMode: string
{
    case PER_MESSAGE = 'per_message';
    case WHOLE_CONVERSATION = 'whole_conversation';
}
