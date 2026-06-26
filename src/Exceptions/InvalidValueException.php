<?php

namespace SurrealDB\SDK\Exceptions;

/**
 * Thrown client-side when a value cannot be parsed into, or constructed as, a
 * SurrealDB type (e.g. an invalid datetime, duration, decimal, or record id).
 *
 * Mirrors the JS SDK's `Invalid*Error` family.
 */
final class InvalidValueException extends SurrealException {}
