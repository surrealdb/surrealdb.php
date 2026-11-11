<?php

namespace SurrealDB\AgentMemory\Enum;

/** Result families a memory recall can return; both by default. */
enum ResultFamily: string
{
    /** Structured facts (attributes, relations, actions). */
    case FACTS = 'facts';
    /** Document chunks and conversation passages. */
    case PASSAGES = 'passages';
}
