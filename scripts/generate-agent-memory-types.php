<?php

/*
 * Regenerates src/AgentMemory/Schema/Schemas.php, the PHPStan response types,
 * from the Agent Memory OpenAPI description:
 *
 *   composer generate:agent-memory-types -- ../spectron/doc/spec/enduser.swagger.json
 *
 * Without a path argument it reads AGENT_MEMORY_OPENAPI_SPEC.
 */

require __DIR__ . '/AgentMemoryTypesGenerator.php';

$spec = $argv[1] ?? (string) getenv('AGENT_MEMORY_OPENAPI_SPEC');

if ($spec === '') {
    fwrite(STDERR, "Usage: composer generate:agent-memory-types -- <path/to/enduser.swagger.json>\n"
        . "       (or set AGENT_MEMORY_OPENAPI_SPEC)\n");
    exit(2);
}

try {
    $generator = AgentMemoryTypesGenerator::fromFile($spec);
    $source = $generator->render();
    $count = count($generator->aliases());
} catch (UnexpectedValueException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$target = dirname(__DIR__) . '/' . AgentMemoryTypesGenerator::TARGET;

if ((!is_dir(dirname($target)) && !mkdir(dirname($target), 0o777, true)) || file_put_contents($target, $source) === false) {
    fwrite(STDERR, "Cannot write {$target}\n");
    exit(1);
}

echo 'Wrote ', AgentMemoryTypesGenerator::TARGET, " ({$count} aliases)\n";
