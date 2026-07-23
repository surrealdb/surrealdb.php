<?php

namespace SurrealDB\Spectron\Enum;

/** Grant verb in the scope permission model. */
enum Verb: string
{
    case READ = 'read';
    case WRITE = 'write';
    case CREATE_SCOPE = 'create_scope';
    case DELETE_SCOPE = 'delete_scope';
    case GRANT = 'grant';
    case MANAGE = 'manage';
    case FORGET = 'forget';
}
