<?php

namespace SurrealDB\Exceptions;

/**
 * The base class for the SurrealDB SDK's exceptions. Catch
 * {@see SurrealExceptionInterface} to cover every exception the SDK raises,
 * including any that extend another SPL exception instead.
 */
class SurrealException extends \RuntimeException implements SurrealExceptionInterface {}
