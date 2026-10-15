<?php

namespace SurrealDB\Enum;

/**
 * The relationship between a span and its parent/children, mapped to the
 * matching OpenTelemetry span kind by the adapter. SDK operations are
 * {@see self::Client} (the SDK calls out to a remote SurrealDB server).
 */
enum SpanKind
{
    case Internal;
    case Client;
    case Server;
    case Producer;
    case Consumer;
}
