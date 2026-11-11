<?php

namespace SurrealDB\Live;

/** The kind of change reported by a live query notification. */
enum LiveAction: string
{
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Killed = 'KILLED';
}
