<?php

namespace SurrealDB\Enum;

/**
 * The terminal status of a {@see \SurrealDB\Contracts\Span}, mapped to the
 * matching OpenTelemetry status code by the adapter.
 */
enum SpanStatus
{
    case Unset;
    case Ok;
    case Error;
}
