<?php

namespace SurrealDB\Tests\PHPStan;

use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Contracts\AgentMemoryInterface;
use SurrealDB\AgentMemory\Exceptions\RateLimitException;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\ScopeForgetOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Streaming\ChatChunk;
use function PHPStan\Testing\assertType;

/*
 * Analysed by `composer analyse`, never run: each assertType() fails the
 * analysis unless PHPStan infers exactly that type. It checks that the
 * generated response shapes (SurrealDB\AgentMemory\Schema\Schemas) reach code
 * typed against the interfaces, and code typed against the concrete client,
 * whose methods inherit the interface docblocks.
 */

function typesThroughTheInterfaces(AgentMemoryInterface $memory): void
{
    $resolution = $memory->lookup('alice')['resolution'];

    if ($resolution['kind'] === 'entity') {
        assertType('float', $resolution['confidence']);
    }

    assertType('string', $memory->remember('Alice is the CTO')['sessionId']);
    assertType("'chunking'|'embedding'|'extracting'|'extracting_nodes'|'failed'|'keywording'|'queued'|'ready'", $memory->documents->get('d')['status']);
    assertType('string|null', $memory->documents->get('d')['error'] ?? null);
    assertType('int', $memory->documents->count());
    assertType('string', $memory->documents->keywords->forDocument('d')[0]['normalised']);
    assertType('float', $memory->entities->search('alice')[0]['score']);
    assertType('string', $memory->uncertainty->resolve('u', 'v')['about']);
    assertType('string', $memory->sessions->attach('s')->context('q')['context']);
    assertType('int', $memory->lifecycle->expire()['affected']);
    assertType('string', $memory->principals->get('p')['displayName']);
    assertType('string', $memory->scopes->register(new ScopeRegisterOptions('team'))['path']);
    assertType('string', $memory->keys->create()['key']);

    foreach ($memory->entities->list() as $entity) {
        assertType('string', $entity['name']);
    }

    foreach ($memory->facts->allEdgesOf('person/alice') as $relation) {
        assertType('string', $relation['label']);
    }

    foreach ($memory->sessions->attach('s')->turns()->autoPaginate() as $turn) {
        assertType("'assistant'|'system'|'tool'|'user'", $turn['role']);
    }

    $next = $memory->traces->list()->next();

    if ($next !== null) {
        assertType('list<string>', array_column($next->items, 'id'));
    }

    foreach ($memory->chatStream('hello') as $chunk) {
        assertType(ChatChunk::class, $chunk);
        assertType('SurrealDB\\AgentMemory\\Enum\\ChatEvent|null', $chunk->event);
        assertType('string|null', $chunk->reply);

        if ($chunk->citations !== null && $chunk->memoryUpdates !== null) {
            assertType('string', $chunk->citations[0]['marker']);
            assertType('string', $chunk->memoryUpdates['turnId']);
        }
    }
}

function typesThroughTheConcreteClient(AgentMemory $memory): void
{
    $resolution = $memory->lookup('alice')['resolution'];

    if ($resolution['kind'] === 'ambiguous') {
        assertType('float', $resolution['candidates'][0]['score']);
    }

    assertType(AgentMemory::class, $memory->onBehalfOf('bob'));
    assertType("'context'|'identity'|'knowledge'", $memory->facts->attributes()->items[0]['memoryCategory']);
    assertType('string', $memory->documents->upload(new DocumentUploadOptions('x'))['contentHash']);
    assertType('int', $memory->documents->count());
    assertType('string', $memory->documents->keywords->get('k')['normalised']);
    assertType('float', $memory->entities->top()[0]['score']);
    assertType('bool', $memory->uncertainty->list()->items[0]['resolvable']);
    assertType('string', $memory->sessions->create()->context('q')['context']);
    assertType('int', $memory->lifecycle->decay()['affected']);
    assertType('string', $memory->traces->get('t')['queryText']);
    assertType('string', $memory->principals->effective('p', new EffectiveGrantsOptions('team'))['path']);
    assertType('int', $memory->scopes->forget(new ScopeForgetOptions('team'))['forgotten']);
    assertType('string', $memory->keys->rotate('k')['key']);

    foreach ($memory->entities->allChanges('person', 'alice', max: 10) as $change) {
        assertType('string', $change['value']);
    }

    foreach ($memory->auditAll() as $row) {
        assertType('int', $row['rowsTouched']);
    }
}

/*
 * PHPStan reads a @throws list as complete, so each list names
 * AgentMemoryException: without it, this catch is reported as a dead catch.
 */
function failedRequestsStayCatchable(AgentMemory $memory): void
{
    try {
        $memory->recall('What is my role?');
    } catch (RateLimitException) {
    }
}
