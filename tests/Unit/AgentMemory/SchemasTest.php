<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory\Schema\Schemas;

final class SchemasTest extends TestCase
{
    public function testTheGeneratedClassLoadsAndCannotBeInstantiated(): void
    {
        $class = new \ReflectionClass(Schemas::class);

        $this->assertTrue($class->isFinal());
        $this->assertFalse($class->isInstantiable());
        $this->assertSame([], $class->getMethods(\ReflectionMethod::IS_PUBLIC));
    }

    public function testItsAliasesAreSortedAndUnique(): void
    {
        $aliases = self::definedAliases();
        $sorted = $aliases;
        usort($sorted, strcmp(...));

        $this->assertContains('PageMeta', $aliases);
        $this->assertContains('DocumentJson', $aliases);
        $this->assertSame($sorted, $aliases);
        $this->assertSame(array_values(array_unique($aliases)), $aliases);
    }

    public function testEveryImportedAliasIsDefined(): void
    {
        $this->assertSame(['A', 'B', 'C'], self::importedAliases(
            "@phpstan-import-type A from Schemas\n@phpstan-import-type B from \\SurrealDB\\AgentMemory\\Schema\\Schemas\n"
            . "@phpstan-import-type C from SurrealDB\\AgentMemory\\Schema\\Schemas as D\n@phpstan-import-type E from Other\n",
        ));

        $defined = self::definedAliases();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        $imports = 0;

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                foreach (self::importedAliases((string) file_get_contents($file->getPathname())) as $alias) {
                    $this->assertContains($alias, $defined, "{$file->getPathname()} imports {$alias}, which Schemas does not define");
                    ++$imports;
                }
            }
        }

        $this->assertGreaterThan(0, $imports);
    }

    /**
     * @return list<string>
     */
    private static function definedAliases(): array
    {
        preg_match_all('/@phpstan-type\s+(\S+)/', (string) (new \ReflectionClass(Schemas::class))->getDocComment(), $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private static function importedAliases(string $source): array
    {
        preg_match_all('/@phpstan-import-type\s+(\w+)\s+from\s+(?:\\\\?SurrealDB\\\\AgentMemory\\\\Schema\\\\)?Schemas\b/', $source, $matches);

        return $matches[1];
    }
}
