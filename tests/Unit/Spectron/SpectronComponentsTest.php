<?php

namespace SurrealDB\Tests\Unit\Spectron;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SurrealDB\Spectron\Enum\Verb;
use SurrealDB\Spectron\Options\DocumentChunkOptions;
use SurrealDB\Spectron\Options\DocumentListOptions;
use SurrealDB\Spectron\Options\DocumentQueryOptions;
use SurrealDB\Spectron\Options\DocumentUploadOptions;
use SurrealDB\Spectron\Options\EffectiveGrantsOptions;
use SurrealDB\Spectron\Options\EntityListOptions;
use SurrealDB\Spectron\Options\GrantOptions;
use SurrealDB\Spectron\Options\KeyCreateOptions;
use SurrealDB\Spectron\Options\KeyRotateOptions;
use SurrealDB\Spectron\Options\KeywordListOptions;
use SurrealDB\Spectron\Options\KeywordSearchOptions;
use SurrealDB\Spectron\Options\ScopeForgetOptions;
use SurrealDB\Spectron\Options\ScopeRegisterOptions;
use SurrealDB\Spectron\Options\SessionCreateOptions;
use SurrealDB\Spectron\Options\TraceListOptions;
use SurrealDB\Spectron;
use SurrealDB\Spectron\SpectronOptions;
use SurrealDB\Tests\Fakes\RecordingHttpConnection;

final class SpectronComponentsTest extends TestCase
{
    private const string BASE = 'https://spectron.example.com/api/v1/acme-prod';

    private function client(RecordingHttpConnection $connection): Spectron
    {
        return new Spectron(
            new SpectronOptions('https://spectron.example.com', 'acme-prod', 'secret'),
            $connection,
        );
    }

    public function testDocumentUploadSendsMultipartWithMetadataBeforeFile(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"id":"doc-1","status":"queued"}'));

        $upload = $this->client($connection)->documents->upload(new DocumentUploadOptions(
            file: 'PDFBYTES',
            title: 'Handbook',
            scopes: 'team/eng',
        ));

        $this->assertSame(self::BASE . '/documents', $connection->url);
        $this->assertSame('POST', $connection->method);
        $this->assertStringStartsWith('multipart/form-data; boundary=', $connection->headers['Content-Type']);

        $body = (string) $connection->body;
        $metadataAt = strpos($body, 'name="metadata"');
        $fileAt = strpos($body, 'name="file"; filename="upload"');
        $this->assertNotFalse($metadataAt);
        $this->assertNotFalse($fileAt);
        // The server reads multipart fields in declaration order.
        $this->assertLessThan($fileAt, $metadataAt);
        $this->assertStringContainsString('{"title":"Handbook","scopes":[["team/eng"]]}', $body);
        $this->assertStringContainsString("Content-Type: application/octet-stream\r\n\r\nPDFBYTES", $body);
        $this->assertSame('doc-1', $upload['id']);
    }

    public function testDocumentReadEndpointsBuildTheExpectedPaths(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"documents":[]}'));
        $documents = $this->client($connection)->documents;

        $documents->get('doc 1');
        $this->assertSame(self::BASE . '/documents/doc%201', $connection->url);

        $documents->chunks('doc-1', new DocumentChunkOptions(page: 2, pageSize: 50));
        $this->assertSame(self::BASE . '/documents/doc-1/chunks?page=2&pageSize=50', $connection->url);

        $documents->list(new DocumentListOptions(status: 'ready'));
        $this->assertSame(self::BASE . '/documents?status=ready', $connection->url);

        $documents->delete('doc-1');
        $this->assertSame('DELETE', $connection->method);

        $documents->query(new DocumentQueryOptions('handbook', k: 5, mode: 'hybrid'));
        $this->assertSame(self::BASE . '/documents/query', $connection->url);
        $this->assertSame('{"query":"handbook","k":5,"mode":"hybrid"}', $connection->body);

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
        $connection = new RecordingHttpConnection(new Response(200, [], '{"keywords":[{"normalised":"rust"}]}'));
        $keywords = $this->client($connection)->documents->keywords;

        $keywords->list(new KeywordListOptions(q: 'ru', minDocumentCount: 2));
        $this->assertSame(self::BASE . '/documents/keywords?q=ru&minDocumentCount=2', $connection->url);

        $keywords->search(new KeywordSearchOptions('rust', k: 3));
        $this->assertSame(self::BASE . '/documents/keywords/search', $connection->url);
        $this->assertSame('{"query":"rust","k":3}', $connection->body);

        $keywords->get('rust');
        $this->assertSame(self::BASE . '/documents/keywords/rust', $connection->url);

        $linked = $keywords->forDocument('doc-1');
        $this->assertSame(self::BASE . '/documents/doc-1/keywords', $connection->url);
        $this->assertSame([['normalised' => 'rust']], $linked);
    }

    public function testEntitiesUnwrapEnvelopes(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"entities":[{"name":"tobie"}],"history":[{"value":"CTO"}]}'));
        $entities = $this->client($connection)->entities;

        $this->assertSame([['name' => 'tobie']], $entities->list(new EntityListOptions(type: 'person')));
        $this->assertSame(self::BASE . '/entities?type=person', $connection->url);

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
            200,
            [],
            '{"id":"sess-1","createdAt":"2026-07-23T00:00:00Z","scopes":[["team/eng"]],"turns":[{"role":"user"}]}',
        ));
        $client = $this->client($connection);

        $session = $client->sessions->create(new SessionCreateOptions(
            scopes: 'team/eng',
            metadata: ['app' => 'demo'],
        ));

        $this->assertSame(self::BASE . '/sessions', $connection->url);
        $this->assertSame('{"scopes":[["team/eng"]],"metadata":{"app":"demo"}}', $connection->body);
        $this->assertSame('sess-1', $session->id);
        $this->assertSame([['team/eng']], $session->scopes);

        $this->assertSame([['role' => 'user']], $session->turns());
        $this->assertSame(self::BASE . '/sessions/sess-1/turns', $connection->url);

        $session->context('what changed?');
        $this->assertSame(self::BASE . '/sessions/sess-1/context', $connection->url);
        $this->assertSame('{"query":"what changed?"}', $connection->body);

        $session->close();
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame(self::BASE . '/sessions/sess-1', $connection->url);
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
        $connection = new RecordingHttpConnection(new Response(200, [], '{"traces":[{"id":"tr-1"}]}'));
        $traces = $this->client($connection)->traces;

        $this->assertSame([['id' => 'tr-1']], $traces->list(new TraceListOptions(limit: 5)));
        $this->assertSame(self::BASE . '/traces?limit=5', $connection->url);

        $traces->get('tr-1');
        $this->assertSame(self::BASE . '/traces/tr-1', $connection->url);

        $traces->stats();
        $this->assertSame(self::BASE . '/traces/stats', $connection->url);
    }

    public function testPrincipalsGrantAndRevokeSendVerbBodies(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '[]'));
        $principals = $this->client($connection)->principals;

        $principals->list();
        $this->assertSame(self::BASE . '/principals', $connection->url);

        $principals->effective('principal:alex', new EffectiveGrantsOptions('team/eng', asOf: '2026-01-01'));
        $this->assertSame(
            self::BASE . '/principals/principal%3Aalex/effective?path=team%2Feng&asOf=2026-01-01',
            $connection->url,
        );

        $principals->grant('principal:alex', new GrantOptions('team/*', [Verb::READ, 'write']));
        $this->assertSame('POST', $connection->method);
        $this->assertSame(self::BASE . '/principals/principal%3Aalex/grants', $connection->url);
        $this->assertSame('{"path":"team/*","verbs":["read","write"]}', $connection->body);

        $principals->revoke('principal:alex', new GrantOptions('team/*', [Verb::WRITE]));
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame('{"path":"team/*","verbs":["write"]}', $connection->body);
    }

    public function testScopesEndpoints(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], '{"forgotten":2}'));
        $scopes = $this->client($connection)->scopes;

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
        $connection = new RecordingHttpConnection(new Response(200, [], '{"id":"key-1","key":"sp-key-1-secret"}'));
        $keys = $this->client($connection)->keys;

        $keys->create(new KeyCreateOptions(name: 'ci', ttlSeconds: 3600));
        $this->assertSame(self::BASE . '/keys?ttlSeconds=3600', $connection->url);
        $this->assertSame('{"name":"ci"}', $connection->body);

        // An all-default create sends no body, mirroring the JS client.
        $keys->create();
        $this->assertSame(self::BASE . '/keys', $connection->url);
        $this->assertNull($connection->body);

        $keys->delete('ci');
        $this->assertSame('DELETE', $connection->method);
        $this->assertSame(self::BASE . '/keys/ci', $connection->url);

        $keys->rotate('ci', new KeyRotateOptions(ttlSeconds: 60));
        $this->assertSame(self::BASE . '/keys/ci/rotate?ttlSeconds=60', $connection->url);
    }

    public function testKeysListToleratesNullBody(): void
    {
        $connection = new RecordingHttpConnection(new Response(200, [], 'null'));

        $this->assertSame([], $this->client($connection)->keys->list());
    }
}
