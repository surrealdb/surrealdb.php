<?php

namespace SurrealDB\AgentMemory\Exceptions;

/**
 * The upload's media type has no parser, so it was not ingested (415).
 * Retrying the same bytes cannot succeed: convert the file to a supported
 * format, or pass the correct `contentType` if the wrong one was sent.
 */
final class UnsupportedMediaTypeException extends AgentMemoryException {}
