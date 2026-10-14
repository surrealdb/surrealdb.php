<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Enum\MemoryCategory;
use SurrealDB\AgentMemory\Enum\TurnRole;
use SurrealDB\AgentMemory\Message;
use SurrealDB\AgentMemory\MimeType;
use SurrealDB\AgentMemory\Options\AttributeListOptions;
use SurrealDB\AgentMemory\Options\RelationListOptions;
use SurrealDB\AgentMemory\Triple;

final class ValueObjectsTest extends TestCase
{
    public function testEntityRefParsesBothSpellingsAndSplitsOnTheFirstSlash(): void
    {
        $this->assertSame('person/alice', (string) EntityRef::parse('person/alice'));
        $this->assertSame('person/alice', (string) EntityRef::parse('entity:person/alice'));

        $band = EntityRef::parse('band/AC/DC');
        $this->assertSame('band', $band->type);
        $this->assertSame('AC/DC', $band->name);
        $this->assertSame(['type' => 'band', 'name' => 'AC/DC'], $band->toPayload());
    }

    public function testEntityRefRejectsMalformedRefs(): void
    {
        foreach (['alice', '/alice', 'person/', 'person/   '] as $ref) {
            try {
                EntityRef::parse($ref);
                $this->fail("Expected '{$ref}' to be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEntityRefHasATaggedForm(): void
    {
        $alice = new EntityRef('person', 'alice');

        $this->assertSame('entity:person/alice', $alice->tagged());
        $this->assertSame('person/alice', (string) EntityRef::parse($alice->tagged()));
    }

    public function testEntityFiltersAcceptTheTaggedRefsResponsesCarry(): void
    {
        // An attribute row's `entity` is tagged; it must filter, not match nothing.
        $this->assertSame(['entity' => 'person/alice'], (new AttributeListOptions(entity: 'entity:person/alice'))->toQuery());
        $this->assertSame(
            ['src' => 'person/alice', 'dst' => 'company/acme'],
            (new RelationListOptions(src: 'entity:person/alice', dst: new EntityRef('company', 'acme')))->toQuery(),
        );
    }

    public function testAMalformedEntityFilterFailsInsteadOfMatchingNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AttributeListOptions(entity: 'alice'))->toQuery();
    }

    public function testTripleBuildersProduceTheThreeFactFamilies(): void
    {
        $this->assertSame(
            ['entity' => ['type' => 'person', 'name' => 'alice'], 'key' => 'role', 'value' => 'CTO', 'confidence' => 0.9, 'memory_category' => 'knowledge'],
            Triple::attribute('person/alice', 'role', 'CTO', confidence: 0.9, memoryCategory: MemoryCategory::KNOWLEDGE)->toPayload(),
        );

        $this->assertSame(
            [
                'entity' => ['type' => 'person', 'name' => 'alice'],
                'key' => 'works_at',
                'target' => ['type' => 'company', 'name' => 'acme'],
                'valid_from' => '2024-01-01T00:00:00Z',
            ],
            Triple::relation(new EntityRef('person', 'alice'), 'works_at', 'company/acme', validFrom: '2024-01-01T00:00:00Z')->toPayload(),
        );

        $this->assertSame(
            [
                'entity' => ['type' => 'person', 'name' => 'alice'],
                'verb' => 'shipped',
                'object' => 'the v2 release',
                'temporal_hint' => 'last Friday',
                'source_clause' => 'Alice shipped v2 last Friday.',
            ],
            Triple::action('person/alice', 'shipped', object: 'the v2 release', temporalHint: 'last Friday', sourceClause: 'Alice shipped v2 last Friday.')->toPayload(),
        );
    }

    public function testATripleStatesItsTimeOnce(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Triple::action('person/alice', 'shipped', occurredAt: '2026-01-01T00:00:00Z', temporalHint: 'yesterday');
    }

    public function testARelationsValidFromExcludesOccurredAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Triple::relation('person/alice', 'works_at', 'company/acme', occurredAt: '2026-01-01T00:00:00Z', validFrom: '2026-01-01T00:00:00Z');
    }

    public function testTripleRejectsOutOfRangeConfidenceAndBlankPredicates(): void
    {
        $cases = [
            static fn (): Triple => Triple::attribute('person/alice', 'role', 'CTO', confidence: 1.5),
            static fn (): Triple => Triple::attribute('person/alice', ' ', 'CTO'),
            static fn (): Triple => Triple::relation('person/alice', '', 'company/acme'),
            static fn (): Triple => Triple::action('person/alice', ''),
        ];

        foreach ($cases as $i => $build) {
            try {
                $build();
                $this->fail("Case {$i} should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMessagesSerialiseRoleContentAndAddressees(): void
    {
        $this->assertSame(
            ['role' => 'user', 'content' => 'hi', 'addressees' => ['Sam'], 'ts' => '2026-01-01T00:00:00Z'],
            Message::user('hi', ['Sam'], '2026-01-01T00:00:00Z')->toPayload(),
        );
        $this->assertSame(['role' => 'assistant', 'content' => 'hello'], Message::assistant('hello')->toPayload());
        $this->assertSame(['role' => 'tool', 'content' => '{}'], (new Message(TurnRole::TOOL, '{}'))->toPayload());
    }

    public function testMimeTypeCoversTheIngestedExtensionsOnly(): void
    {
        $this->assertSame('text/markdown', MimeType::fromFilename('README.md'));
        $this->assertSame('application/pdf', MimeType::fromFilename('/tmp/Report.PDF'));
        $this->assertSame('text/csv', MimeType::fromFilename('rows.csv'));
        $this->assertNull(MimeType::fromFilename('archive.zip'));
        $this->assertNull(MimeType::fromFilename('no-extension'));
        $this->assertNull(MimeType::fromFilename('trailing.'));
    }
}
