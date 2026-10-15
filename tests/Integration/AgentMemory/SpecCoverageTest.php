<?php

namespace SurrealDB\Tests\Integration\AgentMemory;

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
use SurrealDB\AgentMemory\EntityRef;
use SurrealDB\AgentMemory\Message;
use SurrealDB\AgentMemory\Options;
use SurrealDB\AgentMemory\Triple;

/**
 * Checks the client against the server's OpenAPI description, so drift in
 * the upstream API fails CI instead of surfacing in production:
 *
 * - every end-user operation maps to a client method (or is listed as
 *   deliberately not exposed),
 * - every query parameter and request-body field the spec declares can be
 *   sent by that method's options class (or is listed as deliberately unsent),
 *   following path-level and `$ref`'d parameters, `$ref`'d request bodies, and
 *   `allOf`/`oneOf`/`anyOf` compositions, and
 * - every response member the client reads (a page's rows, an unwrapped
 *   result) is still in the operation's success (200, 201 or 202) response,
 * - src/AgentMemory/Schema/Schemas.php is what the generator renders from the
 *   spec today, and
 * - each method documents the response schema's alias as its return type
 *   (on the interface it implements), collectors the row alias as a list.
 *
 * Point `AGENT_MEMORY_OPENAPI_SPEC` at the server's `doc/spec/enduser.swagger.json`;
 * the spec-driven tests skip without it unless `AGENT_MEMORY_REQUIRE_SERVER=1`.
 */
final class SpecCoverageTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private static ?array $spec = null;

    /** Operations the client deliberately leaves out, and why. */
    private const array NOT_EXPOSED = [
        'scope_grants_stub' => 'the server answers 501 Not Implemented',
    ];

    /**
     * operationId => how the client covers it:
     * - `method`: the client method that calls it,
     * - `options`: the options class whose fields it sends,
     * - `sends`: fields the method sends itself,
     * - `page`: the rows member a paginated response is read from,
     * - `collect`: the collector that walks every page of it (`listAll()`, `all*()`),
     * - `member`: the response member the method unwraps and returns.
     *
     * @var array<string,array{method:string,options?:class-string,sends?:list<string>,page?:string,collect?:string,member?:string}>
     */
    private const array OPERATIONS = [
        'health_check' => ['method' => AgentMemory::class . '::health'],
        'whoami' => ['method' => AgentMemory::class . '::whoami'],
        'get_profile' => ['method' => AgentMemory::class . '::profile'],
        'get_state' => ['method' => AgentMemory::class . '::state', 'options' => Options\StateOptions::class],
        'create_fact' => ['method' => AgentMemory::class . '::remember', 'options' => Options\RememberOptions::class, 'sends' => ['text']],
        'create_facts_batch' => ['method' => AgentMemory::class . '::rememberMany', 'options' => Options\RememberManyOptions::class, 'sends' => ['messages']],
        'query_memory' => ['method' => AgentMemory::class . '::recall', 'options' => Options\RecallOptions::class, 'sends' => ['query']],
        'lookup' => ['method' => AgentMemory::class . '::lookup', 'options' => Options\LookupOptions::class, 'sends' => ['query']],
        'forget' => ['method' => AgentMemory::class . '::forget', 'options' => Options\ForgetOptions::class, 'sends' => ['query']],
        'chat' => ['method' => AgentMemory::class . '::chat', 'options' => Options\ChatOptions::class, 'sends' => ['message', 'stream']],
        'query_context' => ['method' => AgentMemory::class . '::context', 'options' => Options\ContextOptions::class, 'sends' => ['query']],
        'reflect' => ['method' => AgentMemory::class . '::reflect', 'options' => Options\ReflectOptions::class, 'sends' => ['query']],
        'consolidate' => ['method' => AgentMemory::class . '::consolidate', 'options' => Options\ConsolidateOptions::class],
        'elaborate' => ['method' => AgentMemory::class . '::elaborate', 'options' => Options\ElaborateOptions::class],
        'fsck' => ['method' => AgentMemory::class . '::fsck', 'options' => Options\FsckOptions::class],
        'inspect' => ['method' => AgentMemory::class . '::inspect', 'options' => Options\InspectOptions::class, 'sends' => ['ref']],
        'list_audit' => ['method' => AgentMemory::class . '::audit', 'options' => Options\AuditOptions::class, 'page' => 'rows', 'collect' => AgentMemory::class . '::auditAll'],
        'list_attributes' => ['method' => Facts::class . '::attributes', 'options' => Options\AttributeListOptions::class, 'page' => 'attributes', 'collect' => Facts::class . '::allAttributes'],
        'list_relations' => ['method' => Facts::class . '::relations', 'options' => Options\RelationListOptions::class, 'page' => 'relations', 'collect' => Facts::class . '::allRelations'],
        'list_actions' => ['method' => Facts::class . '::actions', 'options' => Options\ActionListOptions::class, 'page' => 'actions', 'collect' => Facts::class . '::allActions'],
        'list_documents' => ['method' => Documents::class . '::list', 'options' => Options\DocumentListOptions::class, 'page' => 'documents', 'collect' => Documents::class . '::listAll'],
        'upload_document' => ['method' => Documents::class . '::upload', 'options' => Options\DocumentUploadOptions::class],
        'reprocess_document' => ['method' => Documents::class . '::reprocess', 'options' => Options\DocumentUploadOptions::class],
        'get_document' => ['method' => Documents::class . '::get'],
        'delete_document' => ['method' => Documents::class . '::delete'],
        'fetch_document_raw' => ['method' => Documents::class . '::raw'],
        'list_chunks' => ['method' => Documents::class . '::chunks', 'options' => Options\PageOptions::class, 'page' => 'chunks', 'collect' => Documents::class . '::allChunks'],
        'query_documents' => ['method' => Documents::class . '::query', 'options' => Options\DocumentQueryOptions::class],
        'recompute_document_links' => ['method' => Documents::class . '::recomputeLinks'],
        'list_keywords' => ['method' => DocumentKeywords::class . '::list', 'options' => Options\KeywordListOptions::class, 'page' => 'keywords', 'collect' => DocumentKeywords::class . '::listAll'],
        'search_keywords' => ['method' => DocumentKeywords::class . '::search', 'options' => Options\KeywordSearchOptions::class],
        'get_keyword' => ['method' => DocumentKeywords::class . '::get'],
        'list_document_keywords' => ['method' => DocumentKeywords::class . '::forDocument', 'member' => 'keywords'],
        'list_entities' => ['method' => Entities::class . '::list', 'options' => Options\EntityListOptions::class, 'page' => 'entities', 'collect' => Entities::class . '::listAll'],
        'search_entities' => ['method' => Entities::class . '::search', 'options' => Options\EntitySearchOptions::class, 'sends' => ['q'], 'member' => 'matches'],
        'top_entities' => ['method' => Entities::class . '::top', 'options' => Options\TopEntitiesOptions::class, 'member' => 'entities'],
        'get_entity' => ['method' => Entities::class . '::get', 'options' => Options\EntityGetOptions::class],
        'get_entity_history' => ['method' => Entities::class . '::history', 'member' => 'history'],
        'entity_history_all' => ['method' => Entities::class . '::changes', 'options' => Options\PageOptions::class, 'page' => 'history', 'collect' => Entities::class . '::allChanges'],
        'entity_neighbourhood' => ['method' => Entities::class . '::neighbours', 'options' => Options\NeighbourhoodOptions::class, 'page' => 'neighbours', 'collect' => Entities::class . '::allNeighbours'],
        'delete_entity' => ['method' => Entities::class . '::delete'],
        'list_uncertainty' => ['method' => Uncertainty::class . '::list', 'options' => Options\UncertaintyListOptions::class, 'page' => 'unknowns', 'collect' => Uncertainty::class . '::listAll'],
        'resolve_uncertainty' => ['method' => Uncertainty::class . '::resolve', 'sends' => ['acceptedValue', 'note'], 'member' => 'uncertainty'],
        'create_session' => ['method' => Sessions::class . '::create', 'options' => Options\SessionCreateOptions::class],
        'delete_session' => ['method' => Session::class . '::close'],
        'get_session_context' => ['method' => Session::class . '::context', 'sends' => ['query']],
        'list_turns' => ['method' => Session::class . '::turns', 'options' => Options\PageOptions::class, 'page' => 'turns', 'collect' => Session::class . '::allTurns'],
        'expire_context' => ['method' => Lifecycle::class . '::expire'],
        'decay_importance' => ['method' => Lifecycle::class . '::decay'],
        'list_traces' => ['method' => Traces::class . '::list', 'options' => Options\PageOptions::class, 'page' => 'traces', 'collect' => Traces::class . '::listAll'],
        'get_trace' => ['method' => Traces::class . '::get'],
        'get_trace_stats' => ['method' => Traces::class . '::stats'],
        'list_principals' => ['method' => Principals::class . '::list', 'options' => Options\PageOptions::class, 'page' => 'principals', 'collect' => Principals::class . '::listAll'],
        'get_principal' => ['method' => Principals::class . '::get'],
        'effective_principal' => ['method' => Principals::class . '::effective', 'options' => Options\EffectiveGrantsOptions::class],
        'grant_principal' => ['method' => Principals::class . '::grant', 'options' => Options\GrantOptions::class],
        'revoke_principal' => ['method' => Principals::class . '::revoke', 'options' => Options\GrantOptions::class],
        'list_scopes' => ['method' => Scopes::class . '::list', 'options' => Options\ScopeListOptions::class, 'page' => 'scopes', 'collect' => Scopes::class . '::listAll'],
        'register_scope' => ['method' => Scopes::class . '::register', 'options' => Options\ScopeRegisterOptions::class],
        'delete_scope' => ['method' => Scopes::class . '::delete', 'sends' => ['path']],
        'forget_scope' => ['method' => Scopes::class . '::forget', 'options' => Options\ScopeForgetOptions::class],
        'list_self_keys' => ['method' => Keys::class . '::list', 'options' => Options\PageOptions::class, 'page' => 'keys', 'collect' => Keys::class . '::listAll'],
        'create_self_key' => ['method' => Keys::class . '::create', 'options' => Options\KeyCreateOptions::class],
        'rotate_self_key' => ['method' => Keys::class . '::rotate', 'options' => Options\KeyRotateOptions::class],
        'delete_self_key' => ['method' => Keys::class . '::delete'],
    ];

    /** Operations whose method returns something other than the response schema, and why. */
    private const array RETURNS_OTHER = [
        'create_session' => 'returns a Session handle built from SessionResponseJson',
    ];

    /** Spec fields the client deliberately does not send, per operation, and why. */
    private const array UNSENT_FIELDS = [
        'list_documents' => ['page' => 'deprecated; cursor pagination replaces it', 'pageSize' => 'deprecated; use limit'],
        'list_keywords' => ['page' => 'deprecated; cursor pagination replaces it', 'pageSize' => 'deprecated; use limit'],
        'list_chunks' => ['page' => 'deprecated; cursor pagination replaces it', 'pageSize' => 'deprecated; use limit'],
        'list_entities' => ['offset' => 'deprecated; cursor pagination replaces it'],
        'list_turns' => ['offset' => 'deprecated; cursor pagination replaces it'],
        'upload_document' => ['metadata.mimeType' => "the file part's Content-Type declares the media type"],
        'reprocess_document' => ['metadata.mimeType' => "the file part's Content-Type declares the media type"],
    ];

    /**
     * Arguments that must be varied to reach every field (some fields exclude
     * each other); the emitted fields of each variant are unioned.
     */
    private const array VARIANTS = [
        Options\NeighbourhoodOptions::class => [['minFacts' => null], ['count' => false]],
        Options\GrantOptions::class => [['verbs' => ['memory:read']]],
    ];

    /** Guards the schema walk against a self-referencing spec. */
    private const int MAX_SCHEMA_DEPTH = 16;

    public function testEveryMappedClientMethodExists(): void
    {
        foreach (self::OPERATIONS as $operation => $coverage) {
            [$class, $method] = explode('::', $coverage['method']);

            $this->assertTrue(method_exists($class, $method), "{$operation}: {$coverage['method']} does not exist");

            if (isset($coverage['options'])) {
                $this->assertTrue(class_exists($coverage['options']), "{$operation}: {$coverage['options']} does not exist");
            }

            if (isset($coverage['page'])) {
                // Every paged collection has its collector, as in @surrealdb/memory.
                $this->assertArrayHasKey('collect', $coverage, "{$operation}: a paged operation needs a collector");
                [$collectorClass, $collector] = explode('::', $coverage['collect']);
                $this->assertTrue(method_exists($collectorClass, $collector), "{$operation}: {$coverage['collect']} does not exist");
            }
        }
    }

    public function testEveryEndUserOperationIsCovered(): void
    {
        $operations = self::operations(self::spec());
        $missing = array_diff_key($operations, self::OPERATIONS, self::NOT_EXPOSED);

        $this->assertSame([], array_keys($missing), "Spec operations the PHP client does not cover yet:\n" . self::bullets($missing));

        $stale = array_diff_key(self::OPERATIONS, $operations);
        $this->assertSame([], array_keys($stale), 'Mapped operations no longer in the spec (removed upstream?)');
    }

    public function testEveryRequestFieldCanBeSent(): void
    {
        $spec = self::spec();
        $gaps = [];

        foreach (self::operations($spec) as $operationId => $operation) {
            $coverage = self::OPERATIONS[$operationId] ?? null;

            if ($coverage === null) {
                continue;
            }

            $sendable = [...($coverage['sends'] ?? []), ...(isset($coverage['options']) ? self::wireFields($coverage['options']) : [])];
            $unsent = array_keys(self::UNSENT_FIELDS[$operationId] ?? []);

            foreach (self::requestFields($spec, $operation) as $field) {
                if (!in_array($field, $sendable, true) && !in_array($field, $unsent, true)) {
                    $gaps[] = "{$operationId} ({$operation['method']} {$operation['path']}): `{$field}`";
                }
            }
        }

        $this->assertSame([], $gaps, "Request fields the PHP client cannot send yet:\n  - " . implode("\n  - ", $gaps));
    }

    public function testEveryResponseMemberTheClientReadsExists(): void
    {
        $spec = self::spec();
        $gaps = [];

        foreach (self::operations($spec) as $operationId => $operation) {
            $coverage = self::OPERATIONS[$operationId] ?? null;
            $expected = array_filter([
                $coverage['page'] ?? null,
                isset($coverage['page']) ? 'page' : null,
                $coverage['member'] ?? null,
            ]);

            if ($expected === []) {
                continue;
            }

            $members = array_keys(self::properties($spec, self::successResponseSchema($operation)));

            foreach ($expected as $member) {
                if (!in_array($member, $members, true)) {
                    $gaps[] = "{$operationId} ({$operation['method']} {$operation['path']}): the client reads `{$member}`; the success response has "
                        . ($members === [] ? 'no members' : '`' . implode('`, `', $members) . '`');
                }
            }
        }

        $this->assertSame([], $gaps, "Response members the PHP client reads that the spec no longer has:\n  - " . implode("\n  - ", $gaps));
    }

    public function testGeneratedSchemaTypesMatchTheSpec(): void
    {
        $committed = (string) file_get_contents(dirname(__DIR__, 3) . '/' . \AgentMemoryTypesGenerator::TARGET);

        $this->assertSame(
            (new \AgentMemoryTypesGenerator(self::spec()))->render(),
            str_replace("\r\n", "\n", $committed),
            \AgentMemoryTypesGenerator::TARGET . ' is stale: run composer generate:agent-memory-types -- $AGENT_MEMORY_OPENAPI_SPEC',
        );
    }

    public function testEveryMethodReturnsItsResponseSchema(): void
    {
        $spec = self::spec();
        $gaps = [];

        foreach (self::operations($spec) as $operationId => $operation) {
            $coverage = self::OPERATIONS[$operationId] ?? null;
            $schema = self::successResponseSchema($operation);

            if ($coverage === null || $schema === [] || isset(self::RETURNS_OTHER[$operationId])) {
                continue;
            }

            if (isset($coverage['page'], $coverage['collect'])) {
                $row = self::alias(self::properties($spec, $schema)[$coverage['page']]['items'] ?? [], "{$operationId} rows");
                $expected = [$coverage['method'] => "Page<{$row}>", $coverage['collect'] => "list<{$row}>"];
            } elseif (isset($coverage['member'])) {
                $member = self::properties($spec, $schema)[$coverage['member']] ?? [];
                $expected = [$coverage['method'] => isset($member['items'])
                    ? 'list<' . self::alias($member['items'], "{$operationId} {$coverage['member']}") . '>'
                    : self::alias($member, "{$operationId} {$coverage['member']}")];
            } else {
                $expected = [$coverage['method'] => self::alias($schema, $operationId)];
            }

            foreach ($expected as $method => $type) {
                $documented = self::documentedReturn($method);

                if ($documented !== $type) {
                    $gaps[] = "{$operationId}: {$method}() documents @return " . ($documented ?? '(none)') . "; the spec's response is {$type}";
                }
            }
        }

        $this->assertSame([], $gaps, "Return types that do not match the spec:\n  - " . implode("\n  - ", $gaps));
    }

    public function testValueObjectsCoverTheirSchemas(): void
    {
        $spec = self::spec();
        $at = '2026-01-01T00:00:00Z';

        $tripleFields = array_keys(
            Triple::attribute('t/n', 'k', 'v', confidence: 0.5, memoryCategory: 'x', occurredAt: $at, sourceClause: 'x')->toPayload()
            + Triple::relation('t/n', 'k', 't/m', validFrom: $at, validUntil: $at, sourceClause: 'x')->toPayload()
            + Triple::action('t/n', 'v', object: 'o', summary: 's', temporalHint: 'h')->toPayload()
            + Triple::entity('t/n', memoryCategory: 'x')->toPayload(),
        );

        foreach ([
            'Triple' => $tripleFields,
            'TripleEntity' => array_keys((new EntityRef('t', 'n'))->toPayload()),
            'BatchMessage' => array_keys((new Message('user', 'c', ['a'], $at))->toPayload()),
        ] as $schema => $fields) {
            $declared = array_keys(self::properties($spec, ['$ref' => "#/components/schemas/{$schema}"]));
            $missing = array_diff($declared, $fields);

            $this->assertNotSame([], $declared, "{$schema} is no longer in the spec");
            $this->assertSame([], array_values($missing), "{$schema} fields the client cannot send");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function spec(): array
    {
        if (self::$spec !== null) {
            return self::$spec;
        }

        $path = getenv('AGENT_MEMORY_OPENAPI_SPEC');

        if ($path === false || $path === '') {
            $reason = "Set AGENT_MEMORY_OPENAPI_SPEC to the server's doc/spec/enduser.swagger.json to check spec coverage.";

            if (getenv('AGENT_MEMORY_REQUIRE_SERVER') === '1') {
                self::fail($reason);
            }

            self::markTestSkipped($reason);
        }

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return self::$spec = $decoded;
    }

    /**
     * Every operation, with the parameters of its path item merged in (an
     * operation-level parameter overrides a path-level one of the same name
     * and location).
     *
     * @param array<string,mixed> $spec
     *
     * @return array<string,array{method:string,path:string,op:array<string,mixed>,parameters:list<array<string,mixed>>}>
     */
    private static function operations(array $spec): array
    {
        $operations = [];

        foreach ($spec['paths'] as $path => $item) {
            $shared = self::parameters($spec, $item['parameters'] ?? []);

            foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
                $operation = $item[$method] ?? null;

                if (!is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }

                $parameters = $shared;

                foreach (self::parameters($spec, $operation['parameters'] ?? []) as $parameter) {
                    $parameters["{$parameter['in']}:{$parameter['name']}"] = $parameter;
                }

                $operations[$operation['operationId']] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'op' => $operation,
                    'parameters' => array_values($parameters),
                ];
            }
        }

        return $operations;
    }

    /**
     * Resolves a parameter list (entries may be `$ref`s), keyed `in:name`.
     *
     * @param array<string,mixed>        $spec
     * @param list<array<string,mixed>> $parameters
     *
     * @return array<string,array<string,mixed>>
     */
    private static function parameters(array $spec, array $parameters): array
    {
        $resolved = [];

        foreach ($parameters as $parameter) {
            $parameter = self::deref($spec, $parameter);

            if (isset($parameter['in'], $parameter['name'])) {
                $resolved["{$parameter['in']}:{$parameter['name']}"] = $parameter;
            }
        }

        return $resolved;
    }

    /**
     * The query parameters and request-body fields an operation declares;
     * multipart `metadata` members are reported as `metadata.<field>`.
     *
     * @param array<string,mixed>                                                                         $spec
     * @param array{method:string,path:string,op:array<string,mixed>,parameters:list<array<string,mixed>>} $operation
     *
     * @return list<string>
     */
    private static function requestFields(array $spec, array $operation): array
    {
        $fields = [];

        foreach ($operation['parameters'] as $parameter) {
            if ($parameter['in'] === 'query') {
                $fields[] = $parameter['name'];
            }
        }

        $body = isset($operation['op']['requestBody']) ? self::deref($spec, $operation['op']['requestBody']) : [];

        foreach ($body['content'] ?? [] as $contentType => $content) {
            $properties = self::properties($spec, $content['schema'] ?? []);

            foreach ($properties as $field => $schema) {
                $fields[] = $field;

                if ($contentType === 'multipart/form-data' && $field === 'metadata') {
                    foreach (array_keys(self::properties($spec, $schema)) as $member) {
                        $fields[] = "metadata.{$member}";
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * The JSON schema of an operation's success response: the first of 200,
     * 201 and 202 with a JSON body, or `[]` when there is none.
     *
     * @param array{method:string,path:string,op:array<string,mixed>,parameters:list<array<string,mixed>>} $operation
     *
     * @return array<string,mixed>
     */
    private static function successResponseSchema(array $operation): array
    {
        foreach (['200', '201', '202'] as $status) {
            $response = self::deref(self::spec(), $operation['op']['responses'][$status] ?? []);

            if (isset($response['content']['application/json']['schema'])) {
                return $response['content']['application/json']['schema'];
            }
        }

        return [];
    }

    /**
     * The schema name a `#/components/schemas/<name>` reference points at,
     * which is also its alias on {@see \SurrealDB\AgentMemory\Schema\Schemas}.
     *
     * @param array<string,mixed> $schema
     */
    private static function alias(array $schema, string $what): string
    {
        $ref = $schema['$ref'] ?? null;

        if (!is_string($ref) || !str_starts_with($ref, '#/components/schemas/')) {
            self::fail("{$what}: the response schema is not a \$ref, so it has no alias to check against");
        }

        return substr($ref, strlen('#/components/schemas/'));
    }

    /**
     * The type in the first `@return` tag of `Class::method`'s docblock on the
     * interface method it implements, or on the method itself when it
     * implements none.
     */
    private static function documentedReturn(string $method): ?string
    {
        [$class, $name] = explode('::', $method);
        $reflection = new \ReflectionMethod($class, $name);

        try {
            $docblock = $reflection->getPrototype()->getDocComment();
        } catch (\ReflectionException) {
            $docblock = $reflection->getDocComment();
        }

        return preg_match('/@return\s+(\S+)/', (string) $docblock, $match) === 1 ? $match[1] : null;
    }

    /**
     * Every property a schema can carry: `$ref`s are followed, `allOf` arms
     * merged, and the arms of a `oneOf`/`anyOf` unioned (a client may send any
     * field of any arm).
     *
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $schema
     *
     * @return array<string,array<string,mixed>>
     */
    private static function properties(array $spec, array $schema, int $depth = 0): array
    {
        if ($depth > self::MAX_SCHEMA_DEPTH) {
            self::fail('The OpenAPI schema nests deeper than ' . self::MAX_SCHEMA_DEPTH . ' levels; is it self-referencing?');
        }

        $schema = self::deref($spec, $schema);
        $properties = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $properties[$name] = is_array($property) ? $property : [];
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $composition) {
            foreach ($schema[$composition] ?? [] as $arm) {
                $properties += self::properties($spec, is_array($arm) ? $arm : [], $depth + 1);
            }
        }

        return $properties;
    }

    /**
     * Follows a local `$ref` (`#/components/...`) to the node it points at.
     *
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $node
     *
     * @return array<string,mixed>
     */
    private static function deref(array $spec, array $node): array
    {
        for ($hops = 0; isset($node['$ref']) && is_string($node['$ref']); ++$hops) {
            if ($hops > self::MAX_SCHEMA_DEPTH || !str_starts_with($node['$ref'], '#/')) {
                self::fail("Cannot follow the OpenAPI reference {$node['$ref']}");
            }

            $target = $spec;

            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $target = is_array($target) && array_key_exists($segment, $target) ? $target[$segment] : null;
            }

            if (!is_array($target)) {
                self::fail("The OpenAPI reference {$node['$ref']} points at nothing");
            }

            $node = $target;
        }

        return $node;
    }

    /**
     * Every wire field an options class can emit: it is built with a value for
     * every constructor argument, and the keys of its payload/query collected.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function wireFields(string $class): array
    {
        $defaults = [];

        foreach ((new \ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
            $defaults[$parameter->getName()] = self::sample($parameter);
        }

        $fields = [];

        foreach (self::VARIANTS[$class] ?? [[]] as $variant) {
            $options = new $class(...array_merge($defaults, $variant));

            if ($options instanceof Options\DocumentUploadOptions) {
                $fields = [...$fields, 'file', 'metadata', ...array_map(
                    static fn (string $member): string => "metadata.{$member}",
                    self::uploadMetadataFields($options),
                )];

                continue;
            }

            foreach (['toPayload', 'toQuery'] as $method) {
                if (method_exists($options, $method)) {
                    $fields = [...$fields, ...array_keys($options->{$method}())];
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return list<string>
     */
    private static function uploadMetadataFields(Options\DocumentUploadOptions $options): array
    {
        preg_match('/name="metadata"\r\n\r\n(\{.*?\})\r\n--/s', $options->toForm()->body(), $match);
        $metadata = json_decode($match[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

        return is_array($metadata) ? array_keys($metadata) : [];
    }

    /** A value of the parameter's type that makes the option emit its field. */
    private static function sample(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $names = $type instanceof \ReflectionUnionType
            ? array_map(static fn (\ReflectionType $t): string => (string) $t, $type->getTypes())
            : [$type instanceof \ReflectionNamedType ? $type->getName() : 'mixed'];

        return match (true) {
            in_array('bool', $names, true) => true,
            in_array('int', $names, true) => 1,
            in_array('float', $names, true) => 0.5,
            in_array('string', $names, true) => 'x/y',
            in_array('array', $names, true) => [],
            default => 'x',
        };
    }

    /**
     * @param array<string,mixed> $operations
     */
    private static function bullets(array $operations): string
    {
        return implode("\n", array_map(
            static fn (string $id, array $op): string => "  - {$id}: {$op['method']} {$op['path']}",
            array_keys($operations),
            $operations,
        ));
    }
}
