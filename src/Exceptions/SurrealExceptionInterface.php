<?php

namespace SurrealDB\Exceptions;

/**
 * Implemented by every exception the SurrealDB SDK raises itself, including
 * those that extend another SPL exception instead of {@see SurrealException},
 * so one `catch (SurrealExceptionInterface $e)` handles them all.
 *
 * Exceptions thrown by third-party code the SDK calls into, such as a PSR-18
 * client or a PSR-7 message factory, are not always wrapped and can still
 * escape.
 */
interface SurrealExceptionInterface extends \Throwable {}
