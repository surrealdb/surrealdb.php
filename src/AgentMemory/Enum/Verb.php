<?php

namespace SurrealDB\AgentMemory\Enum;

/**
 * Grant verb in the scope permission model, namespaced as `<noun>:<verb>`.
 * Documents live on the memory plane, so the `memory:*` verbs govern document
 * upload and search too.
 */
enum Verb: string
{
    case MEMORY_READ = 'memory:read';
    case MEMORY_WRITE = 'memory:write';
    case MEMORY_FORGET = 'memory:forget';
    case SCOPE_READ = 'scope:read';
    case SCOPE_CREATE = 'scope:create';
    case SCOPE_DELETE = 'scope:delete';
    case GRANT_MANAGE = 'grant:manage';
}
