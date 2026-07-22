<?php

declare(strict_types=1);

/*
 * Scoped to one concern: make the engine inline the "compiler optimized"
 * native functions (is_string, is_array, strlen, count, ...) by importing
 * them with `use function`, so an unqualified call in a namespace resolves
 * as fully-qualified at compile time instead of via the runtime fallback.
 */
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'global_namespace_import' => [
            'import_functions' => true,
            'import_classes' => false,
            'import_constants' => false,
        ],
        // global_namespace_import drops the new `use` line straight under the
        // namespace declaration; restore the PSR-12 blank line after it.
        'blank_line_after_namespace' => true,
    ])
    ->setFinder($finder);
