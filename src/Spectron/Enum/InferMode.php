<?php

namespace SurrealDB\Spectron\Enum;

/** Inference mode for the `/facts` write API. */
enum InferMode: string
{
    case FULL = 'full';
    case TRIPLES = 'triples';
    case PREVIEW = 'preview';
    case NONE = 'none';
}
