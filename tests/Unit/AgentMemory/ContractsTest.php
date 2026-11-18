<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Components\DocumentKeywords;
use SurrealDB\AgentMemory\Components\Documents;
use SurrealDB\AgentMemory\Components\Entities;
use SurrealDB\AgentMemory\Components\Facts;
use SurrealDB\AgentMemory\Components\Keys;
use SurrealDB\AgentMemory\Components\Lifecycle;
use SurrealDB\AgentMemory\Components\Principals;
use SurrealDB\AgentMemory\Components\Scopes;
use SurrealDB\AgentMemory\Components\Session;
use SurrealDB\AgentMemory\Components\Sessions;
use SurrealDB\AgentMemory\Components\Traces;
use SurrealDB\AgentMemory\Components\Uncertainty;
use SurrealDB\AgentMemory\Contracts\AgentMemoryInterface;
use SurrealDB\AgentMemory\Contracts\DocumentKeywordsInterface;
use SurrealDB\AgentMemory\Contracts\DocumentsInterface;
use SurrealDB\AgentMemory\Contracts\EntitiesInterface;
use SurrealDB\AgentMemory\Contracts\FactsInterface;
use SurrealDB\AgentMemory\Contracts\KeysInterface;
use SurrealDB\AgentMemory\Contracts\LifecycleInterface;
use SurrealDB\AgentMemory\Contracts\PrincipalsInterface;
use SurrealDB\AgentMemory\Contracts\ScopesInterface;
use SurrealDB\AgentMemory\Contracts\SessionInterface;
use SurrealDB\AgentMemory\Contracts\SessionsInterface;
use SurrealDB\AgentMemory\Contracts\TracesInterface;
use SurrealDB\AgentMemory\Contracts\UncertaintyInterface;
use SurrealDB\AgentMemory\Options\ElaborateOptions;
use SurrealDB\AgentMemory\Page;

final class ContractsTest extends TestCase
{
    /** Every implementation and the interface code types against. */
    private const array IMPLEMENTATIONS = [
        AgentMemory::class => AgentMemoryInterface::class,
        Documents::class => DocumentsInterface::class,
        DocumentKeywords::class => DocumentKeywordsInterface::class,
        Entities::class => EntitiesInterface::class,
        Facts::class => FactsInterface::class,
        Uncertainty::class => UncertaintyInterface::class,
        Sessions::class => SessionsInterface::class,
        Session::class => SessionInterface::class,
        Lifecycle::class => LifecycleInterface::class,
        Traces::class => TracesInterface::class,
        Principals::class => PrincipalsInterface::class,
        Scopes::class => ScopesInterface::class,
        Keys::class => KeysInterface::class,
    ];

    /** The only returns a class narrows, as [class return, interface return]. */
    private const array COVARIANT_RETURNS = [
        AgentMemory::class . '::onBehalfOf' => ['self', AgentMemoryInterface::class],
        Sessions::class . '::create' => [Session::class, SessionInterface::class],
        Sessions::class . '::attach' => [Session::class, SessionInterface::class],
    ];

    public function testEveryPublicMemberIsDeclaredOnItsInterface(): void
    {
        foreach (self::IMPLEMENTATIONS as $class => $interface) {
            $implementation = new \ReflectionClass($class);
            $contract = new \ReflectionClass($interface);

            $this->assertTrue($contract->isInterface(), "{$interface} is not an interface");
            $this->assertTrue($implementation->implementsInterface($interface), "{$class} does not implement {$interface}");

            foreach ($implementation->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->name, '__')) {
                    $this->assertTrue($contract->hasMethod($method->name), "{$class}::{$method->name}() is not on {$interface}");
                }
            }

            foreach ($implementation->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $this->assertTrue($contract->hasProperty($property->name), "{$class}::\${$property->name} is not on {$interface}");
            }
        }
    }

    public function testEveryComponentHasAnInterface(): void
    {
        $components = glob(dirname((string) (new \ReflectionClass(Documents::class))->getFileName()) . '/*.php');
        $contracts = glob(dirname((string) (new \ReflectionClass(DocumentsInterface::class))->getFileName()) . '/*.php');

        $this->assertNotEmpty($components);
        $this->assertNotEmpty($contracts);

        foreach ($components as $file) {
            $class = 'SurrealDB\\AgentMemory\\Components\\' . basename($file, '.php');
            $this->assertArrayHasKey($class, self::IMPLEMENTATIONS, "{$class} has no interface in Contracts");
        }

        foreach ($contracts as $file) {
            $interface = 'SurrealDB\\AgentMemory\\Contracts\\' . basename($file, '.php');
            $this->assertContains($interface, self::IMPLEMENTATIONS, "{$interface} has no implementation");
        }
    }

    public function testTheSubClientsAreExposedThroughTheirInterfaces(): void
    {
        $owners = [AgentMemory::class => AgentMemoryInterface::class, Documents::class => DocumentsInterface::class];

        foreach ($owners as $class => $interface) {
            foreach ((new \ReflectionClass($interface))->getProperties() as $property) {
                if ($property->name === 'contextId') {
                    continue;
                }

                $declared = (string) $property->getType();
                $this->assertContains($declared, self::IMPLEMENTATIONS, "{$interface}::\${$property->name} is not typed as a Contracts interface");
                // The class keeps the concrete type, so users of the class lose nothing.
                $this->assertSame(
                    array_search($declared, self::IMPLEMENTATIONS, true),
                    (string) (new \ReflectionProperty($class, $property->name))->getType(),
                    "{$class}::\${$property->name}",
                );
            }
        }
    }

    public function testInterfaceAndClassSignaturesMatch(): void
    {
        $covariant = 0;

        foreach (self::IMPLEMENTATIONS as $class => $interface) {
            foreach ((new \ReflectionClass($interface))->getMethods() as $declared) {
                $implemented = new \ReflectionMethod($class, $declared->name);
                $where = "{$class}::{$declared->name}()";

                // PHPStan checks named arguments against the interface and PHP resolves them
                // against the class, so a renamed parameter or a dropped default breaks callers.
                $this->assertSame(self::parameters($declared), self::parameters($implemented), "{$where}: parameters differ from {$interface}");

                $returns = [(string) $implemented->getReturnType(), (string) $declared->getReturnType()];
                $narrowed = self::COVARIANT_RETURNS["{$class}::{$declared->name}"] ?? null;

                if ($narrowed === null) {
                    $this->assertSame($returns[1], $returns[0], "{$where}: return type differs from {$interface}");
                } else {
                    $this->assertSame($narrowed, $returns, "{$where}: return types");
                    ++$covariant;
                }
            }
        }

        $this->assertSame(count(self::COVARIANT_RETURNS), $covariant);
    }

    public function testMethodDocblocksLiveOnTheInterfaces(): void
    {
        foreach (self::IMPLEMENTATIONS as $class => $interface) {
            foreach ((new \ReflectionClass($interface))->getMethods() as $declared) {
                $this->assertNotFalse($declared->getDocComment(), "{$interface}::{$declared->name}() has no docblock");
                $this->assertFalse(
                    (new \ReflectionMethod($class, $declared->name))->getDocComment(),
                    "{$class}::{$declared->name}() has a docblock; document it on {$interface}",
                );
            }
        }
    }

    public function testAListOfThrowsAlsoCoversAFailedRequest(): void
    {
        // PHPStan reads a @throws list as complete: without AgentMemoryException in it, a
        // caller's catch (RateLimitException) around the call is reported as a dead catch.
        $sendsNoRequest = [AgentMemoryInterface::class . '::onBehalfOf', SessionsInterface::class . '::attach'];

        foreach (self::IMPLEMENTATIONS as $interface) {
            foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
                $name = "{$interface}::{$method->name}";
                $doc = (string) $method->getDocComment();

                if (!str_contains($doc, '@throws') || in_array($name, $sendsNoRequest, true)) {
                    continue;
                }

                $this->assertMatchesRegularExpression('/@throws AgentMemoryException\s/', $doc, "{$name}() lists @throws without AgentMemoryException");
            }
        }
    }

    public function testCodeTypedAgainstTheInterfaceCanBeTestedWithAStub(): void
    {
        $entities = $this->createStub(EntitiesInterface::class);
        $entities->method('search')->willReturn([['type' => 'person', 'name' => 'alice', 'score' => 1.0]]);
        $entities->method('list')->willReturn(new Page([['type' => 'person', 'name' => 'alice'], ['type' => 'person', 'name' => 'bob']]));

        $memory = $this->createStub(AgentMemoryInterface::class);
        $memory->method(PropertyHook::get('contextId'))->willReturn('acme_prod');
        $memory->method(PropertyHook::get('entities'))->willReturn($entities);

        $names = static fn (AgentMemoryInterface $memory): array => array_column(iterator_to_array($memory->entities->list()), 'name');

        $this->assertSame('acme_prod', $memory->contextId);
        $this->assertSame('alice', $memory->entities->search('alice')[0]['name']);
        $this->assertSame(['alice', 'bob'], $names($memory));
    }

    public function testASessionCanBeMockedThroughItsInterface(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method(PropertyHook::get('id'))->willReturn('sess-1');
        $session->method('context')->willReturn(['context' => 'Alice is the CTO.']);

        $sessions = $this->createMock(SessionsInterface::class);
        $sessions->expects($this->once())->method('attach')->with('sess-1')->willReturn($session);

        $memory = $this->createStub(AgentMemoryInterface::class);
        $memory->method(PropertyHook::get('sessions'))->willReturn($sessions);

        $attached = $memory->sessions->attach('sess-1');

        $this->assertSame('sess-1', $attached->id);
        $this->assertSame(['context' => 'Alice is the CTO.'], $attached->context('What is my role?'));
    }

    public function testElaborateKeepsItsOptionsRequired(): void
    {
        // The server answers a body with neither `entityRef` nor `sweep` with a 400, so unlike
        // `elaborate(options?)` in @surrealdb/memory the options cannot be left out.
        foreach ([AgentMemory::class, AgentMemoryInterface::class] as $type) {
            $options = (new \ReflectionMethod($type, 'elaborate'))->getParameters()[0];

            $this->assertSame(ElaborateOptions::class, (string) $options->getType(), $type);
            $this->assertFalse($options->isOptional(), "{$type}::elaborate() options became optional");
            $this->assertFalse($options->allowsNull(), "{$type}::elaborate() options became nullable");
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function parameters(\ReflectionMethod $method): array
    {
        return array_map(static fn (\ReflectionParameter $parameter): array => [
            'name' => $parameter->name,
            'type' => (string) $parameter->getType(),
            'optional' => $parameter->isOptional(),
            'default' => $parameter->isDefaultValueAvailable() ? [$parameter->getDefaultValue()] : [],
            'variadic' => $parameter->isVariadic(),
            'byReference' => $parameter->isPassedByReference(),
        ], $method->getParameters());
    }
}
