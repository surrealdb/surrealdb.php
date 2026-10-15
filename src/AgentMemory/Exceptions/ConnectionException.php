<?php

namespace SurrealDB\AgentMemory\Exceptions;

/** Network failure, timeout, or other non-HTTP error (status 0). */
final class ConnectionException extends AgentMemoryException {}
