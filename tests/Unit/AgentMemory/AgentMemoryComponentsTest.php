<?php

namespace SurrealDB\Tests\Unit\AgentMemory;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\DocumentFile;
use SurrealDB\AgentMemory\Enum\DocumentStatus;
use SurrealDB\AgentMemory\Enum\GraphEdgeKind;
use SurrealDB\AgentMemory\Enum\QueryMode;
use SurrealDB\AgentMemory\Enum\Verb;
use SurrealDB\AgentMemory\Exceptions\AgentMemoryException;
use SurrealDB\AgentMemory\Exceptions\InvalidArgumentException;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Options\DocumentQueryOptions;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;
use SurrealDB\AgentMemory\Options\EffectiveGrantsOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;
use SurrealDB\AgentMemory\Options\GrantOptions;
use SurrealDB\AgentMemory\Options\KeyCreateOptions;
use SurrealDB\AgentMemory\Options\KeyRotateOptions;
use SurrealDB\AgentMemory\Options\KeywordListOptions;
use SurrealDB\AgentMemory\Options\KeywordSearchOptions;
use SurrealDB\AgentMemory\Options\PageOptions;
use SurrealDB\AgentMemory\Options\ScopeForgetOptions;
use SurrealDB\AgentMemory\Options\ScopeListOptions;
use SurrealDB\AgentMemory\Options\ScopeRegisterOptions;
use SurrealDB\AgentMemory\Options\SessionCreateOptions;
use SurrealDB\AgentMemory\Page;
use SurrealDB\Tests\Fakes\RecordingHttpConnection;

final class AgentMemoryComponentsTest extends TestCase
{
    private const string BASE = 'https://memory.example.com/api/v1/acme_prod';

    private function client(RecordingHttpConnection $connection): AgentMemory
    {
        return new AgentMemory(
            new AgentMemoryOptions('https://memory.example.com', 'acme_prod', 'secret'),
            $connection,
        );
    }

    public function testDocumentUploadSendsMultipartWithMetadataBeforeFile(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-1","status":"queued"}'));

        $upload = $this->client($connection)->documents->upload(new DocumentUploadOptions(
            file: 'PDFBYTES',
            title: 'Handbook',
            scopes: 'team/eng',
            observedAt: '2026-01-02T03:04:05Z',
            observedAtHeader: 'date',
        ));

        $this->assertSame(self::BASE . '/documents', $connection->url);
        $this->assertSame('POST', $connection->method);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $connection->headers['Content-Type']);

        $body = (string) $connection->body;
        $metadataAt = strpos($body, 'name="metadata"');
        $fileAt = strpos($body, 'name="file"; filename="upload"');
        $this->assertNotFalse($metadataAt);
        $this->assertNotFalse($fileAt);
        $this->assertLessThan($fileAt, $metadataAt);
        $this->assertStringContainsString(
            '{"title":"Handbook","scopes":[["team/eng"]],"observedAt":"2026-01-02T03:04:05Z","observedAtHeader":"date"}',
            $body,
        );
        // No declared or inferable type: the part carries none, so the server sniffs the bytes.
        $this->assertStringContainsString("filename=\"upload\"\r\n\r\nPDFBYTES", $body);
        $this->assertStringNotContainsString('application/octet-stream', $body);
        $this->assertSame('doc-1', $upload['id']);
    }

    public function testDocumentUploadEncodesFilenamesThatCouldBreakTheHeader(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-3"}'));

        $this->client($connection)->documents->upload(new DocumentUploadOptions(
            file: 'BYTES',
            filename: "evil\"\r\nContent-Type: application/pdf\r\n\r\nX\\name.md",
        ));

        $body = (string) $connection->body;
        $this->assertStringContainsString(
            'filename="evil%22%0D%0AContent-Type: application/pdf%0D%0A%0D%0AX%5Cname.md"' . "\r\nContent-Type: text/markdown\r\n\r\nBYTES",
            $body,
        );
        $this->assertSame(1, substr_count($body, "\r\nContent-Type:"), 'the filename must not inject a second header');
    }

    public function testDocumentUploadRejectsLineBreaksInTheContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client(new RecordingHttpConnection(new Response(202, [], '{}')))->documents->upload(
            new DocumentUploadOptions(file: 'x', contentType: "text/plain\r\nX-Injected: 1"),
        );
    }

    public function testDocumentMetadataThatCannotBeEncodedIsAnAgentMemoryException(): void
    {
        $this->expectException(AgentMemoryException::class);
        $this->expectExceptionMessage('Failed to encode the document metadata');

        $this->client(new RecordingHttpConnection(new Response(202, [], '{}')))->documents->upload(
            new DocumentUploadOptions(file: 'x', title: "bad \xB1\x31 utf-8"),
        );
    }

    public function testDocumentUploadInfersTheMediaTypeFromTheFilename(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-2"}'));
        $documents = $this->client($connection)->documents;

        $documents->upload(new DocumentUploadOptions(file: '# Notes', filename: 'notes.MD'));
        $this->assertStringContainsString("filename=\"notes.MD\"\r\nContent-Type: text/markdown\r\n", (string) $connection->body);
        // No metadata part when no metadata field is set.
        $this->assertStringNotContainsString('name="metadata"', (string) $connection->body);

        $documents->upload(new DocumentUploadOptions(file: 'x', filename: 'scan.pdf', contentType: 'application/x-custom'));
        $this->assertStringContainsString("Content-Type: application/x-custom\r\n", (string) $connection->body);
    }

    public function testDocumentUploadTakesTheNameAndTypeFromADocumentFile(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-4"}'));

        $this->client($connection)->documents->upload(new DocumentUploadOptions(DocumentFile::fromString('# Notes', 'notes.md')));

        $this->assertStringContainsString("filename=\"notes.md\"\r\nContent-Type: text/markdown\r\n\r\n# Notes\r\n", (string) $connection->body);
    }

    public function testUploadOptionsOverrideTheDocumentFile(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-5"}'));
        $documents = $this->client($connection)->documents;
        $file = DocumentFile::fromString('x', 'a.pdf', 'application/pdf');

        // A new filename does not re-infer the type: the DocumentFile's own type still wins.
        $documents->upload(new DocumentUploadOptions($file, filename: 'b.md'));
        $this->assertStringContainsString("filename=\"b.md\"\r\nContent-Type: application/pdf\r\n", (string) $connection->body);

        $documents->reprocess('doc-5', new DocumentUploadOptions($file, contentType: 'text/plain', filename: 'b.md'));
        $this->assertStringContainsString("filename=\"b.md\"\r\nContent-Type: text/plain\r\n", (string) $connection->body);

        // A DocumentFile with no type falls back to the final filename's.
        $documents->upload(new DocumentUploadOptions(DocumentFile::fromString('x', 'blob'), filename: 'notes.md'));
        $this->assertStringContainsString("filename=\"notes.md\"\r\nContent-Type: text/markdown\r\n", (string) $connection->body);
    }

    public function testDocumentFileWithoutAKnownTypeDeclaresNone(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-6"}'));

        $this->client($connection)->documents->upload(new DocumentUploadOptions(DocumentFile::fromString('BYTES')));

        $this->assertStringContainsString("filename=\"upload\"\r\n\r\nBYTES\r\n", (string) $connection->body);
        $this->assertStringNotContainsString('Content-Type: ', (string) $connection->body);
    }

    public function testDocumentUploadReadsAStreamWholeAndRejectsAnUnreadableOne(): void
    {
        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-7"}'));
        $documents = $this->client($connection)->documents;
        $stream = Utils::streamFor('# Notes');
        $stream->read(3);

        $documents->upload(new DocumentUploadOptions($stream, filename: 'notes.md'));
        $this->assertStringContainsString("Content-Type: text/markdown\r\n\r\n# Notes\r\n", (string) $connection->body);

        $connection = new RecordingHttpConnection(new Response(202, [], '{"id":"doc-8"}'));
        $detached = Utils::streamFor('# Notes');
        $detached->detach();

        try {
            $this->client($connection)->documents->upload(new DocumentUploadOptions($detached));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Cannot read the document stream: it is not readable.', $e->getMessage());
            $this->assertNull($connection->url, 'nothing is uploaded');
        }
    }

    public function testDocumentReadEndpointsBuildTheExpectedPaths(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"documents":[],"chunks":[{"id":"c-1"}],"page":{"hasMore":false}}'));
        $documents = $this->client($connection)->documents;

        $documents->get('doc 1');
        $this->assertSame(self::BASE . '/documents/doc%201', $connection->url);

        $chunks = $documents->chunks('doc-1', new PageOptions(limit: 50, cursor: 'c2', count: true));
        $this->assertSame(self::BASE . '/documents/doc-1/chunks?limit=50&cursor=c2&count=true', $connection->url);
        $this->assertSame([['id' => 'c-1']], $chunks->items);

        $documents->list(new DocumentListOptions(status: DocumentStatus::READY, limit: 10));
        $this->assertSame(self::BASE . '/documents?status=ready&limit=10', $connection->url);

        $documents->delete('doc-1');
        $this->assertSame('DELETE', $connection->method);

        $documents->query(new DocumentQueryOptions(
            'handbook',
            k: 5,
            mode: QueryMode::HYBRID_GRAPH,
            graphEdges: [GraphEdgeKind::SECTION_MATCH, 'document_link'],
            lens: 'team/eng',
        ));
        $this->assertSame(self::BASE . '/documents/query', $connection->url);
        $this->assertSame(
            '{"query":"handbook","k":5,"mode":"hybrid_graph","graphEdges":["section_match","document_link"],"lens":[["team/eng"]]}',
            $connection->body,
        );

        $documents->recomputeLinks();
        $this->assertSame(self::BASE . '/documents/recompute-links', $connection->url);
        $this->assertSame('{}', $connection->body);
    }

    public function testDocumentRawReturnsBytes(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], 'RAWBYTES'));

        $raw = $this->client($connection)->documents->raw('doc-1');

        $this->assertSame(self::BASE . '/documents/doc-1/raw', $connection->url);
        $this->assertSame('*/*', $connection->headers['Accept']);
        $this->assertSame('RAWBYTES', $raw);
    }

    public function testDocumentKeywordsEndpoints(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"keywords":[{"normalised":"rust"}],"page":{"hasMore":false}}'));
        $keywords = $this->client($connection)->documents->keywords;

        $page = $keywords->list(new KeywordListOptions(q: 'ru', minDocumentCount: 2, limit: 20));
        $this->assertSame(self::BASE . '/documents/keywords?q=ru&minDocumentCount=2&limit=20', $connection->url);
        $this->assertSame([['normalised' => 'rust']], $page->items);

        $keywords->search(new KeywordSearchOptions('rust', k: 3, lens: [['team/eng', 'org/acme']]));
        $this->assertSame(self::BASE . '/documents/keywords/search', $connection->url);
        $this->assertSame('{"query":"rust","k":3,"lens":[["team/eng","org/acme"]]}', $connection->body);

        $keywords->get('rust');
        $this->assertSame(self::BASE . '/documents/keywords/rust', $connection->url);

        $linked = $keywords->forDocument('doc-1');
        $this->assertSame(self::BASE . '/documents/doc-1/keywords', $connection->url);
        $this->assertSame([['normalised' => 'rust']], $linked);
    }

    public function testEntitiesListPagesAndHistoryUnwraps(): void
    {
        $connection = new RecordingHttpConnection(new Response(
            200,
            [],
            '{"entities":[{"name":"tobie"}],"history":[{"value":"CTO"}],"page":{"hasMore":true,"nextCursor":"n1","totalSize":7}}',
        ));
        $entities = $this->client($connection)->entities;

        $page = $entities->list(new EntityListOptions(type: 'person', limit: 1, count: true));
        $this->assertSame(self::BASE . '/entities?type=person&limit=1&count=true', $connection->url);
        $this->assertSame([['name' => 'tobie']], $page->items);
        $this->assertTrue($page->hasMore);
        $this->assertSame('n1', $page->nextCursor);
        $this->assertSame(7, $page->totalSize);

        $entities->get('person', 'tobie beau');
        $this->assertSame(self::BASE . '/entities/person/tobie%20beau', $connection->url);

        $this->assertSame([['value' => 'CTO']], $entities->history('person', 'tobie', 'role'));
        $this->assertSame(self::BASE . '/entities/person/tobie/history/role', $connection->url);

        $entities->delete('person', 'tobie');
        $this->assertSame('DELETE', $connection->method);
    }

    public function testSessionsCreateReturnsSessionHandle(): void
    {
        $connection = new RecordingHttpConnection(new Response(
            201,
            [],
            '{"id":"sess-1","createdAt":"2026-07-23T00:00:00Z","scopes":[["team/eng"]],"turns":[{"role":"user"}],"page":{"hasMore":false}}',
        ));
        $client = $this->client($connection);

        $session = $client->sessions->create(new SessionCreateOptions(
            scopes: 'team/eng',
            metadata: ['app' => 'demo'],
        ));

        $this->assertSame(self::BASE . '/sessions', $connection->url);
        $this->assertSame('{"scopes":[["team/eng"]],"metadata":{"app":"demo"}}', $connection->body);
        $this->assertSame('sess-1', $session->id);
        $this->assertSame('2026-07-23T00:00:00Z', $session->createdAt);
        $this->assertSame([['team/eng']], $session->scopes);

        $turns = $session->turns(new PageOptions(limit: 25));
        $this->assertSame([['role' => 'user']], $turns->items);
        $this->assertSame(self::BASE . '/sessions/sess-1/turns?limit=25', $connection->url);

        $session->context('what changed?');
        $this->assertSame(self::BASE . '/sessions/sess-1/context', $connection->url);
        $this->assertSame('{"query":"what changed?"}', $connection->body);

        $session->close();
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame(self::BASE . '/sessions/sess-1', $connection->url);
    }

    public function testSessionsAttachToAnExistingIdWithoutARequest(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"turns":[],"page":{"hasMore":false}}'));

        $session = $this->client($connection)->sessions->attach('auto/created');

        $this->assertNull($connection->url);
        $this->assertSame('auto/created', $session->id);
        $this->assertNull($session->createdAt);
        $this->assertNull($session->scopes);

        $session->turns();
        $this->assertSame(self::BASE . '/sessions/auto%2Fcreated/turns', $connection->url);
    }

    public function testLifecycleSweepsPostEmptyBodies(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"affected":3}'));
        $lifecycle = $this->client($connection)->lifecycle;

        $lifecycle->expire();
        $this->assertSame(self::BASE . '/lifecycle/expire', $connection->url);
        $this->assertSame('{}', $connection->body);

        $lifecycle->decay();
        $this->assertSame(self::BASE . '/lifecycle/decay', $connection->url);
    }

    public function testTracesEndpoints(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"traces":[{"id":"tr-1"}],"page":{"hasMore":false}}'));
        $traces = $this->client($connection)->traces;

        $this->assertSame([['id' => 'tr-1']], $traces->list(new PageOptions(limit: 5))->items);
        $this->assertSame(self::BASE . '/traces?limit=5', $connection->url);

        $traces->get('tr-1');
        $this->assertSame(self::BASE . '/traces/tr-1', $connection->url);

        $traces->stats();
        $this->assertSame(self::BASE . '/traces/stats', $connection->url);
    }

    public function testPrincipalsGrantAndRevokeSendNamespacedVerbs(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"principals":[{"id":"admin"}],"page":{"hasMore":false}}'));
        $principals = $this->client($connection)->principals;

        $this->assertSame([['id' => 'admin']], $principals->list()->items);
        $this->assertSame(self::BASE . '/principals', $connection->url);

        $principals->effective('principal:alex', new EffectiveGrantsOptions('team/eng', asOf: '2026-01-01'));
        $this->assertSame(
            self::BASE . '/principals/principal%3Aalex/effective?path=team%2Feng&asOf=2026-01-01',
            $connection->url,
        );

        $principals->grant('principal:alex', new GrantOptions('team/*', [Verb::MEMORY_READ, 'memory:write']));
        $this->assertSame('POST', $connection->method);
        $this->assertSame(self::BASE . '/principals/principal%3Aalex/grants', $connection->url);
        $this->assertSame('{"path":"team/*","verbs":["memory:read","memory:write"]}', $connection->body);

        $principals->revoke('principal:alex', new GrantOptions('team/*', [Verb::SCOPE_CREATE]));
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame('{"path":"team/*","verbs":["scope:create"]}', $connection->body);
    }

    public function testScopesEndpoints(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"scopes":[{"path":"/"}],"page":{"hasMore":false},"forgotten":2}'));
        $scopes = $this->client($connection)->scopes;

        $this->assertSame([['path' => '/']], $scopes->list(new ScopeListOptions(limit: 10))->items);
        $this->assertSame(self::BASE . '/scopes?limit=10', $connection->url);

        $scopes->register(new ScopeRegisterOptions('team/eng', displayName: 'Engineering'));
        $this->assertSame(self::BASE . '/scopes', $connection->url);
        $this->assertSame('{"path":"team/eng","displayName":"Engineering"}', $connection->body);

        $scopes->delete('team/eng');
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame(self::BASE . '/scopes?path=team%2Feng', $connection->url);

        $scopes->forget(new ScopeForgetOptions('team/eng'));
        $this->assertSame(self::BASE . '/scopes/forget', $connection->url);
        $this->assertSame('{"path":"team/eng"}', $connection->body);
    }

    public function testKeysEndpoints(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"id":"key-1","key":"sp-key-1-secret","keys":[{"name":"ci"}],"page":{"hasMore":false}}'));
        $keys = $this->client($connection)->keys;

        $keys->create(new KeyCreateOptions(name: 'ci', ttlSeconds: 3600));
        $this->assertSame(self::BASE . '/keys?ttlSeconds=3600', $connection->url);
        $this->assertSame('{"name":"ci"}', $connection->body);

        // An all-default create sends no body, mirroring the JS client.
        $keys->create();
        $this->assertSame(self::BASE . '/keys', $connection->url);
        $this->assertNull($connection->body);

        $this->assertSame([['name' => 'ci']], $keys->list(new PageOptions(count: true))->items);
        $this->assertSame(self::BASE . '/keys?count=true', $connection->url);

        $keys->delete('ci');
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame(self::BASE . '/keys/ci', $connection->url);

        $keys->rotate('ci', new KeyRotateOptions(ttlSeconds: 60));
        $this->assertSame(self::BASE . '/keys/ci/rotate?ttlSeconds=60', $connection->url);
    }

    public function testBlankPathSegmentsAreRejectedBeforeAnyRequest(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{}'));
        $client = $this->client($connection);
        $calls = [
            'document id' => static fn () => $client->documents->get(''),
            'uncertainty id' => static fn () => $client->uncertainty->resolve(' ', 'CTO'),
            'entity name' => static fn () => $client->entities->get('person', ''),
            'key name' => static fn () => $client->keys->delete(''),
            // An empty path addresses the root scope.
            'scope path' => static fn () => $client->scopes->delete(''),
            'blank scope path' => static fn () => $client->scopes->delete(' '),
            'session context query' => static fn () => $client->sessions->attach('s1')->context(' '),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("A blank {$name} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($connection->url, 'no request may be sent');
    }

    public function testListsTolerateANullBody(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], 'null'));

        $page = $this->client($connection)->keys->list();

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame([], $page->items);
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->next());
    }
}
