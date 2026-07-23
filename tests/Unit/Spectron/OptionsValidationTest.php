<?php

namespace SurrealDB\Tests\Unit\Spectron;

use PHPUnit\Framework\TestCase;
use SurrealDB\Spectron\Enum\Verb;
use SurrealDB\Spectron\Options\DocumentQueryOptions;
use SurrealDB\Spectron\Options\EffectiveGrantsOptions;
use SurrealDB\Spectron\Options\GrantOptions;
use SurrealDB\Spectron\Options\KeywordSearchOptions;
use SurrealDB\Spectron\Options\ScopeRegisterOptions;

/** Required members of the options classes are rejected when empty. */
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

        new GrantOptions('', [Verb::READ]);
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

    public function testValidRequiredMembersAreAccepted(): void
    {
        $this->assertSame('handbook', (new DocumentQueryOptions('handbook'))->query);
        $this->assertSame(['path' => 'team/*', 'verbs' => ['read']], (new GrantOptions('team/*', [Verb::READ]))->toPayload());
    }
}
