<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\Contracts\AgentMemoryInterface;
use SurrealDB\AgentMemory\DocumentFile;
use SurrealDB\AgentMemory\Enum\ChatEvent;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryExceptionFactory;
use SurrealDB\AgentMemory\Guard;
use SurrealDB\AgentMemory\IdempotencyKey;
use SurrealDB\AgentMemory\MimeType;
use SurrealDB\AgentMemory\MultipartFormData;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\AgentMemory\Paths;
use SurrealDB\AgentMemory\Payload;
use SurrealDB\AgentMemory\RetryAfter;
use SurrealDB\AgentMemory\RetryPolicy;
use SurrealDB\AgentMemory\Schema\Schemas;
use SurrealDB\AgentMemory\Scope;
use SurrealDB\AgentMemory\Streaming\ChatChunk;
use SurrealDB\AgentMemory\Streaming\ChatStreamParser;
use SurrealDB\AgentMemory\Transport;
use SurrealDB\AgentMemory\UserAgent;

/**
 * Pins which parts of the Agent Memory client are `@internal`, the tag that
 * tells callers not to build on a helper. PhpStorm flags internal use, and
 * PHPStan reports it from outside the `SurrealDB` root namespace only with
 * bleeding edge or `featureToggles.internalTag: true`. Anything
 * `@surrealdb/memory` exports an equivalent of stays public.
 */
final class PublicSurfaceTest extends TestCase
{
    /** Helper classes with no `@surrealdb/memory` export. */
    private const array INTERNAL_CLASSES = [
        Guard::class,
        MimeType::class,
        MultipartFormData::class,
        Payload::class,
        RetryAfter::class,
        UserAgent::class,
    ];

    /** Methods of public classes that only the client itself calls. */
    private const array INTERNAL_METHODS = [
        Transport::class => ['requestMember', 'requestMultipart', 'requestPage'],
        Page::class => ['fromEnvelope'],
        ChatChunk::class => ['fromFrame'],
        DocumentUploadOptions::class => ['resolvedContentType', 'resolvedFilename'],
    ];

    /** Plumbing `@surrealdb/memory` exports an equivalent of, and the public PHP-only types. */
    private const array PUBLIC_CLASSES = [
        Transport::class,
        Page::class,
        Scope::class,
        ChatStreamParser::class,
        IdempotencyKey::class,
        RetryPolicy::class,
        Paths::class,
        AgentMemoryExceptionFactory::class,
        Schemas::class,
        ChatEvent::class,
        DocumentFile::class,
    ];

    public function testHelpersWithNoJsExportAreInternal(): void
    {
        foreach (self::INTERNAL_CLASSES as $class) {
            $this->assertTrue(self::isInternal(new \ReflectionClass($class)), "{$class} is not @internal");
        }

        // No other class is hidden: the list above is the whole internal surface.
        $marked = array_values(array_filter($this->agentMemoryClasses(), static fn (string $class): bool => self::isInternal(new \ReflectionClass($class))));
        $expected = self::INTERNAL_CLASSES;
        sort($marked);
        sort($expected);
        $this->assertSame($expected, $marked);
    }

    public function testHelperMethodsWithNoJsExportAreInternal(): void
    {
        foreach (self::INTERNAL_METHODS as $class => $methods) {
            foreach ($methods as $method) {
                $reflection = new \ReflectionMethod($class, $method);
                $this->assertTrue($reflection->isPublic(), "{$class}::{$method}()");
                $this->assertTrue(self::isInternal($reflection), "{$class}::{$method}() is not @internal");
            }
        }
    }

    public function testThePlumbingJsExportsStaysPublic(): void
    {
        $contracts = array_map(
            static fn (string $file): string => 'SurrealDB\\AgentMemory\\Contracts\\' . basename($file, '.php'),
            glob(dirname((string) (new \ReflectionClass(AgentMemoryInterface::class))->getFileName()) . '/*.php') ?: [],
        );
        $this->assertCount(13, $contracts);

        $checked = [];

        foreach ([...self::PUBLIC_CLASSES, ...$contracts] as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertFalse(self::isInternal($reflection), "{$class} is @internal");

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || in_array($method->name, self::INTERNAL_METHODS[$class] ?? [], true)) {
                    continue;
                }

                $this->assertFalse(self::isInternal($method), "{$class}::{$method->name}() is @internal");
                $checked[] = "{$class}::{$method->name}";
            }
        }

        // Tests stub a collection method with `new Page($rows)`, and callers gather rows with collect().
        foreach (['__construct', 'collect', 'next', 'autoPaginate'] as $method) {
            $this->assertContains(Page::class . "::{$method}", $checked);
        }
    }

    public function testOnlyTheComponentPlumbingOfTransportIsInternal(): void
    {
        $internal = [];
        $public = [];

        foreach ((new \ReflectionClass(Transport::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (self::isInternal($method)) {
                $internal[] = $method->name;
            } else {
                $public[] = $method->name;
            }
        }

        sort($internal);
        sort($public);
        $this->assertSame(self::INTERNAL_METHODS[Transport::class], $internal);
        // The supported entry points, as on the JS Transport, plus the redacting dump.
        $this->assertSame(['__construct', '__debugInfo', 'requestBytes', 'requestJson', 'requestStream', 'withOnBehalfOf'], $public);
    }

    private static function isInternal(\ReflectionClass|\ReflectionMethod $reflection): bool
    {
        return preg_match('/@internal\b/', (string) $reflection->getDocComment()) === 1;
    }

    /**
     * @return list<class-string>
     */
    private function agentMemoryClasses(): array
    {
        $root = dirname((string) (new \ReflectionClass(AgentMemory::class))->getFileName());
        $classes = [AgentMemory::class];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/AgentMemory', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }

            $relative = substr((string) $file, strlen($root) + 1, -strlen('.php'));
            $class = 'SurrealDB\\' . str_replace('/', '\\', $relative);
            $this->assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), $class);
            $classes[] = $class;
        }

        return $classes;
    }
}
