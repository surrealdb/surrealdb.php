<?php

namespace SurrealDB\Spectron\Enum;

/** Document pipeline status values returned by the API. */
enum DocumentStatus: string
{
    case QUEUED = 'queued';
    case EXTRACTING = 'extracting';
    case CHUNKING = 'chunking';
    case EMBEDDING = 'embedding';
    case KEYWORDING = 'keywording';
    case EXTRACTING_NODES = 'extracting_nodes';
    case READY = 'ready';
    case FAILED = 'failed';
}
