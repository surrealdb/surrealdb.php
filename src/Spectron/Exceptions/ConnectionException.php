<?php

namespace SurrealDB\Spectron\Exceptions;

/** Network failure, timeout, or other non-HTTP error (status 0). */
final class ConnectionException extends SpectronException {}
