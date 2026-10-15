<?php

namespace SurrealDB\Exceptions;

/** Thrown when a query expression cannot be safely composed (e.g. duplicate bindings). */
final class ExpressionException extends SurrealException {}
