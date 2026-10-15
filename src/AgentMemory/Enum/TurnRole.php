<?php

namespace SurrealDB\AgentMemory\Enum;

/** Role of a conversation turn participant. */
enum TurnRole: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case SYSTEM = 'system';
    case TOOL = 'tool';
}
