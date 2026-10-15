<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use PHPUnit\Framework\TestCase;

final class SchemaTypesGeneratorTest extends TestCase
{
    private const string REF = '#/components/schemas/';

    /**
     * A spec whose one operation answers with `$responses` (status => schema).
     *
     * @param array<string,mixed> $schemas
     * @param array<string,mixed> $responses
     *
     * @return array<string,mixed>
     */
    private static function spec(array $schemas, array $responses = ['200' => ['$ref' => self::REF . 'Root']]): array
    {
        return [
            'openapi' => '3.1.0',
            'paths' => ['/api/v1/things' => ['get' => [
                'operationId' => 'list_things',
                'responses' => array_map(
                    static fn (array $schema): array => ['description' => 'ok', 'content' => ['application/json' => ['schema' => $schema]]],
                    $responses,
                ),
            ]]],
            'components' => ['schemas' => $schemas],
        ];
    }

    /**
     * @param array<string,mixed> $schemas
     *
     * @return array<string,string>
     */
    private static function aliases(array $schemas): array
    {
        return (new \AgentMemoryTypesGenerator(self::spec($schemas)))->aliases();
    }

    /**
     * The rendered type of `$schema` as the response root, beside `$others`.
     *
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $others
     */
    private static function typeOf(array $schema, array $others = []): string
    {
        return self::aliases(['Root' => $schema] + $others)['Root'];
    }

    public function testRequiredMembersArePlainKeysAndTheRestOptional(): void
    {
        $this->assertSame("array{\n    id: string,\n    note?: string,\n}", self::typeOf([
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string'], 'note' => ['type' => 'string']],
        ]));
    }

    public function testScalarsMapToPhpTypesWhateverTheirFormat(): void
    {
        $cases = [
            'int64' => [['type' => 'integer', 'format' => 'int64'], 'int'],
            'int32' => [['type' => 'integer', 'format' => 'int32'], 'int'],
            'double' => [['type' => 'number', 'format' => 'double'], 'float'],
            'float' => [['type' => 'number', 'format' => 'float'], 'float'],
            'boolean' => [['type' => 'boolean'], 'bool'],
            'date-time' => [['type' => 'string', 'format' => 'date-time'], 'string'],
            'binary' => [['type' => 'string', 'format' => 'binary'], 'string'],
            'array' => [['type' => 'array', 'items' => ['type' => 'string']], 'list<string>'],
            'nested array' => [['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]], 'list<list<string>>'],
            'null' => [['type' => 'null'], 'null'],
        ];

        foreach ($cases as $name => [$schema, $type]) {
            $this->assertSame($type, self::typeOf($schema), $name);
        }
    }

    public function testNullableTypesAndNullableRefsGainNull(): void
    {
        $other = ['Other' => ['type' => 'string']];
        $cases = [
            'a type array' => [['type' => ['string', 'null']], 'string|null'],
            'a nullable integer' => [['type' => ['integer', 'null'], 'format' => 'int64'], 'int|null'],
            'a nullable ref' => [['oneOf' => [['type' => 'null'], ['$ref' => self::REF . 'Other']]], 'Other|null'],
            'a nullable list' => [['type' => ['array', 'null'], 'items' => ['$ref' => self::REF . 'Other']], 'list<Other>|null'],
            'a nullable map' => [['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'integer']], 'array<string, int>|null'],
            'a nullable untyped value' => [['oneOf' => [['type' => 'null'], []]], 'mixed'],
        ];

        foreach ($cases as $name => [$schema, $type]) {
            $this->assertSame($type, self::typeOf($schema, $other), $name);
        }
    }

    public function testEnumsBecomeLiteralStringUnions(): void
    {
        $cases = [
            'strings' => [['type' => 'string', 'enum' => ['queued', 'ready']], "'queued'|'ready'"],
            'one value' => [['type' => 'string', 'enum' => ['entity']], "'entity'"],
            'a null member' => [['type' => ['string', 'null'], 'enum' => ['a', null]], "'a'|null"],
            'a nullable type' => [['type' => ['string', 'null'], 'enum' => ['a', 'b']], "'a'|'b'|null"],
            'quotes and backslashes' => [['type' => 'string', 'enum' => ["it's", 'back\\slash']], "'it\\'s'|'back\\\\slash'"],
            'integers' => [['type' => 'integer', 'enum' => [1, 2]], '1|2'],
        ];

        foreach ($cases as $name => [$schema, $type]) {
            $this->assertSame($type, self::typeOf($schema), $name);
        }
    }

    public function testOneOfVariantsBecomeAUnionOfShapes(): void
    {
        $tagged = self::typeOf(['oneOf' => [
            ['type' => 'object', 'required' => ['kind', 'name'], 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['entity']],
                'name' => ['type' => 'string'],
            ]],
            ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['topic']]]],
        ]]);

        $this->assertSame("array{\n    kind: 'entity',\n    name: string,\n}|array{\n    kind: 'topic',\n}", $tagged);
        $this->assertSame('A|B', self::typeOf(
            ['oneOf' => [['$ref' => self::REF . 'A'], ['$ref' => self::REF . 'B']]],
            ['A' => ['type' => 'string'], 'B' => ['type' => 'integer']],
        ));
    }

    public function testAdditionalPropertiesBecomeAStringKeyedMap(): void
    {
        $cases = [
            'integer values' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'propertyNames' => ['type' => 'string']], 'array<string, int>'],
            'list values' => [['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]], 'array<string, list<string>>'],
            'any values' => [['type' => 'object', 'additionalProperties' => true], 'array<string, mixed>'],
            'no declared members' => [['type' => 'object'], 'array<string, mixed>'],
        ];

        foreach ($cases as $name => [$schema, $type]) {
            $this->assertSame($type, self::typeOf($schema), $name);
        }
    }

    public function testUntypedSchemasAreMixed(): void
    {
        $this->assertSame('mixed', self::typeOf([]));
        $this->assertSame('mixed', self::typeOf(['description' => 'Any JSON value.']));
    }

    public function testNestedInlineObjectsStayOnOneLine(): void
    {
        $this->assertSame("array{\n    items: list<array{score: float, text?: string}>,\n    truncated: bool,\n}", self::typeOf([
            'type' => 'object',
            'required' => ['items', 'truncated'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['score'], 'properties' => [
                    'score' => ['type' => 'number', 'format' => 'double'],
                    'text' => ['type' => 'string'],
                ]]],
                'truncated' => ['type' => 'boolean'],
            ],
        ]));
    }

    public function testKeysThatAreNotIdentifiersAreQuoted(): void
    {
        $this->assertSame("array{\n    'content-type': string,\n    '1st'?: int,\n    'it\\'s'?: bool,\n}", self::typeOf([
            'type' => 'object',
            'required' => ['content-type'],
            'properties' => ['content-type' => ['type' => 'string'], '1st' => ['type' => 'integer'], "it's" => ['type' => 'boolean']],
        ]));
    }

    public function testOnlyTheResponseClosureIsGenerated(): void
    {
        $object = static fn (string $member, string $ref): array => [
            'type' => 'object',
            'properties' => [$member => ['$ref' => self::REF . $ref]],
        ];
        $spec = self::spec(
            [
                'Root' => $object('child', 'Child'),
                'Child' => $object('grandchild', 'Grandchild'),
                'Grandchild' => ['type' => 'string'],
                'Created' => ['type' => 'string'],
                'Accepted' => ['type' => 'string'],
                'Row' => ['type' => 'string'],
                'Failure' => ['type' => 'string'],
                'Plain' => ['type' => 'string'],
                'RequestOnly' => ['type' => 'string'],
            ],
            [
                '200' => ['$ref' => self::REF . 'Root'],
                '201' => ['$ref' => self::REF . 'Created'],
                '202' => ['type' => 'array', 'items' => ['$ref' => self::REF . 'Row']],
                '400' => ['$ref' => self::REF . 'Failure'],
            ],
        );
        $spec['paths']['/api/v1/things']['post'] = [
            'operationId' => 'create_thing',
            'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => self::REF . 'RequestOnly']]]],
            'responses' => [
                '202' => ['description' => 'queued', 'content' => ['application/json' => ['schema' => ['$ref' => self::REF . 'Accepted']]]],
                '200' => ['description' => 'raw', 'content' => ['text/plain' => ['schema' => ['$ref' => self::REF . 'Plain']]]],
                '204' => ['description' => 'no content'],
            ],
        ];

        $this->assertSame(
            ['Accepted', 'Child', 'Created', 'Grandchild', 'Root', 'Row'],
            (new \AgentMemoryTypesGenerator($spec))->responseSchemas(),
        );
    }

    public function testAliasesAreSortedAndTheOutputIsStable(): void
    {
        $schemas = [
            'b' => ['type' => 'string'],
            'Root' => ['type' => 'object', 'properties' => [
                'z' => ['$ref' => self::REF . 'b'],
                'a' => ['$ref' => self::REF . '_x'],
                'm' => ['$ref' => self::REF . 'A'],
            ]],
            '_x' => ['type' => 'string'],
            'A' => ['type' => 'string'],
        ];
        $generator = new \AgentMemoryTypesGenerator(self::spec($schemas));

        $this->assertSame(['A', 'Root', '_x', 'b'], $generator->responseSchemas());
        $this->assertSame("array{\n    z?: b,\n    a?: _x,\n    m?: A,\n}", $generator->aliases()['Root']);
        $this->assertSame($generator->render(), $generator->render());
        $this->assertSame($generator->render(), (new \AgentMemoryTypesGenerator(self::spec(array_reverse($schemas))))->render());
    }

    public function testDocumentationOnlyChangesDoNotChangeTheOutput(): void
    {
        $schemas = [
            'Root' => ['type' => 'object', 'required' => ['count'], 'properties' => [
                'count' => ['type' => 'integer', 'format' => 'int32'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'at' => ['type' => ['string', 'null']],
            ]],
        ];
        $documented = [
            'Root' => ['type' => 'object', 'title' => 'Root', 'description' => 'A root.', 'required' => ['count'], 'x-rust-type' => 'Root', 'properties' => [
                'count' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 0, 'maximum' => 9, 'description' => 'How many.', 'example' => 3],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^\w+$'], 'maxItems' => 5, 'minItems' => 1, 'uniqueItems' => true],
                'at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'examples' => ['2026-01-01T00:00:00Z'], 'deprecated' => true, 'default' => null],
            ]],
        ];

        $this->assertSame(
            (new \AgentMemoryTypesGenerator(self::spec($schemas)))->render(),
            (new \AgentMemoryTypesGenerator(self::spec($documented)))->render(),
        );
    }

    public function testUnsupportedConstructsFailWithTheirLocation(): void
    {
        $root = self::REF . 'Root';
        $member = static fn (array $schema): array => ['type' => 'object', 'properties' => ['x' => $schema]];
        $cases = [
            'allOf' => [['Root' => ['allOf' => [['type' => 'string']]]], "{$root}/allOf"],
            'anyOf' => [['Root' => ['anyOf' => [['type' => 'string']]]], "{$root}/anyOf"],
            'not' => [['Root' => ['not' => ['type' => 'string']]], "{$root}/not"],
            'const' => [['Root' => $member(['const' => 'a'])], "{$root}/properties/x/const"],
            'discriminator' => [['Root' => ['oneOf' => [['type' => 'string']], 'discriminator' => ['propertyName' => 'kind']]], "{$root}/discriminator"],
            'OpenAPI 3.0 nullable' => [['Root' => ['type' => 'string', 'nullable' => true]], "{$root}/nullable"],
            'patternProperties' => [['Root' => ['type' => 'object', 'patternProperties' => ['^a' => ['type' => 'string']]]], "{$root}/patternProperties"],
            'prefixItems' => [['Root' => ['type' => 'array', 'prefixItems' => [['type' => 'string']]]], "{$root}/prefixItems"],
            'properties beside additionalProperties' => [['Root' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string']]], "{$root}/additionalProperties"],
            'a $ref beside a type' => [['Root' => $member(['$ref' => self::REF . 'Root', 'type' => 'object'])], "{$root}/properties/x"],
            'an external $ref' => [['Root' => $member(['$ref' => 'other.json#/components/schemas/X'])], "{$root}/properties/x/\$ref"],
            'a $ref to a missing schema' => [['Root' => $member(['$ref' => self::REF . 'Missing'])], "{$root}/properties/x/\$ref"],
            'an invalid alias name' => [['Root' => $member(['$ref' => self::REF . 'Bad-Name']), 'Bad-Name' => ['type' => 'string']], "{$root}/properties/x/\$ref"],
            'two non-null types' => [['Root' => ['type' => ['string', 'integer']]], "{$root}/type"],
            'an unknown type' => [['Root' => ['type' => 'file']], "{$root}/type"],
            'an array without items' => [['Root' => ['type' => 'array']], $root],
            'items on a string' => [['Root' => ['type' => 'string', 'items' => ['type' => 'string']]], "{$root}/items"],
            'a required member that does not exist' => [['Root' => ['type' => 'object', 'required' => ['gone'], 'properties' => ['a' => ['type' => 'string']]]], "{$root}/required/0"],
            'a value that would close the docblock' => [['Root' => ['type' => 'string', 'enum' => ['a*/b']]], "{$root}/enum/0"],
            'a $ref cycle' => [['Root' => $member(['$ref' => self::REF . 'B']), 'B' => ['type' => 'object', 'properties' => ['r' => ['$ref' => self::REF . 'Root']]]], self::REF . 'B/properties/r'],
        ];

        foreach ($cases as $name => [$schemas, $pointer]) {
            try {
                self::aliases($schemas);
                $this->fail("{$name}: no exception");
            } catch (\UnexpectedValueException $e) {
                $this->assertStringStartsWith("{$pointer}: ", $e->getMessage(), $name);
                $this->assertStringEndsWith('Fix the spec, or extend scripts/AgentMemoryTypesGenerator.php.', $e->getMessage(), $name);
            }
        }
    }

    public function testACycleNamesTheSchemasOnIt(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('`$ref` cycle Root → B → Root is not supported');

        self::aliases([
            'Root' => ['type' => 'object', 'properties' => ['b' => ['$ref' => self::REF . 'B']]],
            'B' => ['type' => 'array', 'items' => ['$ref' => self::REF . 'Root']],
        ]);
    }

    public function testTheRenderedFileIsValidPhp(): void
    {
        $source = (new \AgentMemoryTypesGenerator(self::spec([
            'Root' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string', 'enum' => ['a', 'b']]]],
        ])))->render();

        \PhpToken::tokenize($source, TOKEN_PARSE);

        $this->assertStringStartsWith("<?php\n\n// @generated by scripts/generate-agent-memory-types.php", $source);
        $this->assertStringContainsString("\nnamespace SurrealDB\\AgentMemory\\Schema;\n\n/**\n", $source);
        $this->assertStringContainsString(" * @phpstan-type Root array{\n *     kind: 'a'|'b',\n * }\n */\n", $source);
        $this->assertStringEndsWith("final class Schemas\n{\n    private function __construct() {}\n}\n", $source);

        // Prose never starts a docblock line with a tag, so the aliases are its only tags.
        preg_match_all('/^ \* @(\S+)/m', $source, $tags);
        $this->assertSame(['phpstan-type'], array_values(array_unique($tags[1])));
    }

    public function testFromFileReadsADecodedSpecAndRejectsAnythingElse(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'spec');

        try {
            file_put_contents($path, (string) json_encode(self::spec(['Root' => ['type' => 'string']])));
            $this->assertSame(['Root' => 'string'], \AgentMemoryTypesGenerator::fromFile($path)->aliases());

            foreach (['{"openapi": ' => 'is not valid JSON', '"3.1.0"' => 'does not hold an OpenAPI document'] as $json => $problem) {
                file_put_contents($path, $json);

                try {
                    \AgentMemoryTypesGenerator::fromFile($path);
                    $this->fail("{$json}: no exception");
                } catch (\UnexpectedValueException $e) {
                    $this->assertStringContainsString($problem, $e->getMessage());
                }
            }
        } finally {
            unlink($path);
        }

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Cannot read the OpenAPI description at {$path}.");

        \AgentMemoryTypesGenerator::fromFile($path);
    }
}
