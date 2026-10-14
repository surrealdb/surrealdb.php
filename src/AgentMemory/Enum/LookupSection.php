<?php

namespace SurrealDB\AgentMemory\Enum;

/**
 * A section of a `lookup` answer. Every section but `passages` is stored rows
 * chosen by predicate; `passages` costs a retrieval pass (it embeds the query),
 * so it is left out unless asked for.
 */
enum LookupSection: string
{
    /** The subject's attributes, ranked by importance. */
    case FACTS = 'facts';
    /** Relations in both directions. */
    case RELATIONS = 'relations';
    /** Actions the subject performed. */
    case EVENTS = 'events';
    /** Document and memory passages behind the answer. */
    case PASSAGES = 'passages';
    /** The entities a topic query spans. */
    case ENTITIES = 'entities';
    /** Open questions recorded about the subject. */
    case UNCERTAINTY = 'uncertainty';
}
