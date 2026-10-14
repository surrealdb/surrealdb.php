<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\Enum\Verb;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\ElaborateOptions;
use SurrealDB\AgentMemory\Options\GrantOptions;
use SurrealDB\AgentMemory\Options\KeyCreateOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Options\NeighbourhoodOptions;
use SurrealDB\AgentMemory\Options\RecallOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;

/** Required members of the options classes are rejected when empty or contradictory. */
final class OptionsValidationTest extends TestCase
{
    public function testDocumentQueryRequiresANonEmptyQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DocumentQueryOptions('');
    }

    public function testKeywordSearchRequiresANonEmptyQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KeywordSearchOptions('');
    }

    public function testGrantRequiresANonEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GrantOptions('', [Verb::MEMORY_READ]);
    }

    public function testGrantRequiresAtLeastOneVerb(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GrantOptions('team/*', []);
    }

    public function testEffectiveGrantsRequireANonEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EffectiveGrantsOptions('');
    }

    public function testScopeRegistrationRequiresANonEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScopeRegisterOptions('');
    }

    public function testNeighbourhoodRejectsCountCombinedWithMinFacts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NeighbourhoodOptions(minFacts: 2, count: true);
    }

    public function testElaborateNeedsAnEntityOrASweep(): void
    {
        foreach ([static fn () => new ElaborateOptions(), static fn () => new ElaborateOptions(dryRun: true, budget: 5)] as $build) {
            try {
                $build();
                $this->fail('An elaboration without a target should be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(['sweep' => true], (new ElaborateOptions(sweep: true))->toPayload());
        $this->assertSame(['entityRef' => 'person/tobie'], (new ElaborateOptions(entityRef: 'person/tobie'))->toPayload());
    }

    public function testBlankRequiredTextIsRejected(): void
    {
        $cases = [
            'keyword query' => static fn () => new KeywordSearchOptions('   '),
            'document query' => static fn () => new DocumentQueryOptions("\t"),
            'grant path' => static fn () => new GrantOptions(' ', [Verb::MEMORY_READ]),
            'effective path' => static fn () => new EffectiveGrantsOptions(' '),
            'scope path' => static fn () => new ScopeRegisterOptions(' '),
            'endpoint' => static fn () => new AgentMemoryOptions(' ', 'acme_prod', 'secret'),
            'context' => static fn () => new AgentMemoryOptions('https://memory.example.com', ' ', 'secret'),
            'api key' => static fn () => new AgentMemoryOptions('https://memory.example.com', 'acme_prod', ' '),
        ];

        foreach ($cases as $name => $build) {
            try {
                $build();
                $this->fail("A blank {$name} should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNeighbourhoodAllowsCountWithAZeroMinFacts(): void
    {
        $this->assertSame(['minFacts' => 0, 'count' => true], (new NeighbourhoodOptions(minFacts: 0, count: true))->toQuery());
    }

    public function testEmptyMapsAreSentAsJsonObjects(): void
    {
        $this->assertSame('{"grants":{}}', json_encode((new KeyCreateOptions(grants: []))->toPayload()));
        $this->assertSame('{"location":{}}', json_encode((new RecallOptions(location: []))->toPayload()));
        $this->assertSame(
            '{"query":"q","filter":{},"location":{}}',
            json_encode((new DocumentQueryOptions('q', filter: [], location: []))->toPayload()),
        );
    }

    public function testValidRequiredMembersAreAccepted(): void
    {
        $this->assertSame('handbook', (new DocumentQueryOptions('handbook'))->query);
        $this->assertSame(
            ['path' => 'team/*', 'verbs' => ['memory:read']],
            (new GrantOptions('team/*', [Verb::MEMORY_READ]))->toPayload(),
        );
        $this->assertSame(['minFacts' => 2], (new NeighbourhoodOptions(minFacts: 2))->toQuery());
    }

    public function testVerbsUseTheNamespacedWireForm(): void
    {
        $this->assertSame(
            ['memory:read', 'memory:write', 'memory:forget', 'scope:read', 'scope:create', 'scope:delete', 'grant:manage'],
            array_map(static fn (Verb $verb): string => $verb->value, Verb::cases()),
        );
    }
}
