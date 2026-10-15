<br>

<p align="center">
    <img width=120 src="https://raw.githubusercontent.com/surrealdb/icons/main/surreal.svg" />
    &nbsp;
    <img width=120 src="https://raw.githubusercontent.com/surrealdb/icons/main/php.svg" />
</p>

<h3 align="center">The official SurrealDB SDK for PHP.</h3>

<br>

<p align="center">
    <a href="https://github.com/surrealdb/surrealdb.php"><img src="https://img.shields.io/badge/status-beta-ff00bb.svg?style=flat-square"></a>
    &nbsp;
    <a href="https://surrealdb.com/docs/integration/libraries/php"><img src="https://img.shields.io/badge/docs-view-44cc11.svg?style=flat-square"></a>
    &nbsp;
    <a href="https://packagist.org/packages/surrealdb/surrealdb.php"><img src="https://img.shields.io/packagist/v/surrealdb/surrealdb.php?style=flat-square"></a>
    &nbsp;
    <a href="https://packagist.org/packages/surrealdb/surrealdb.php"><img src="https://img.shields.io/packagist/dm/surrealdb/surrealdb.php?style=flat-square"></a>
</p>

<p align="center">
    <a href="https://surrealdb.com/discord"><img src="https://img.shields.io/discord/902568124350599239?label=discord&style=flat-square&color=5a66f6"></a>
    &nbsp;
    <a href="https://twitter.com/surrealdb"><img src="https://img.shields.io/badge/twitter-follow_us-1d9bf0.svg?style=flat-square"></a>
    &nbsp;
    <a href="https://www.linkedin.com/company/surrealdb/"><img src="https://img.shields.io/badge/linkedin-connect_with_us-0a66c2.svg?style=flat-square"></a>
    &nbsp;
    <a href="https://www.youtube.com/channel/UCjf2teVEuYVvvVC-gFZNq6w"><img src="https://img.shields.io/badge/youtube-subscribe-fc1c1c.svg?style=flat-square"></a>
</p>

# surrealdb.php

The official SurrealDB SDK for PHP.

## Documentation

View the SDK documentation [here](https://surrealdb.com/docs/integration/libraries/php).

## How to install

You install the SurrealDB SDK via [Composer](https://getcomposer.org/). If you don't have Composer installed, you can download it [here](https://getcomposer.org/download/).

```sh
composer require surrealdb/surrealdb.php
```

WebSocket connections (`ws://`, `wss://`) need nothing else. HTTP connections (`http://`, `https://`) and the Agent Memory client also need a PSR-18 HTTP client and the package that discovers it, for example Guzzle:

```sh
composer require guzzlehttp/guzzle php-http/discovery
```

## Getting started

Create a `Surreal` instance and connect it to your server. Pass the namespace, the database, and the credentials in `ConnectOptions`: the SDK signs in when it connects, and again each time it reconnects.

```php
use SurrealDB\Auth\RootAuth;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Surreal;

$db = new Surreal();

// Use ws:// or wss:// for a WebSocket connection (live queries need one), or http:// or https://.
$db->connect("ws://localhost:8000", new ConnectOptions(
    namespace: "test",
    database: "test",
    authentication: new RootAuth("root", "root"),
));
```

To sign in later or as another user, call `$db->signin()` with a `RootAuth`, `NamespaceAuth`, `DatabaseAuth`, or `RecordAccessAuth`. It returns the session's `Tokens`.

On SurrealDB 3, the namespace and database must exist before you write to them. The next example shows how to define them.

### Basic Querying

The statement builders (`create`, `select`, `update`, `upsert`, `delete`, `insert`, `relate`) run when you call `execute()`. `run()` executes raw SurrealQL with bound parameters.

> The example below requires SurrealDB to be [installed](https://surrealdb.com/install) and running on port 8000 with a root user, for example `surreal start --user root --pass root`.

```php
use SurrealDB\Auth\RootAuth;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Surreal;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;

$db = new Surreal();

// Connect, sign in as the root user, and select the namespace and database.
$db->connect("ws://localhost:8000", new ConnectOptions(
    namespace: "test",
    database: "test",
    authentication: new RootAuth("root", "root"),
));

// SurrealDB 3 does not create a namespace or database on first write, so define
// them. IF NOT EXISTS makes this safe to run every time.
$db->run('DEFINE NAMESPACE IF NOT EXISTS test; DEFINE DATABASE IF NOT EXISTS test;');

// Create a person with a specific record id.
$tobie = new RecordId("person", "tobie");

$person = $db->create($tobie)
    ->content([
        "title" => "Founder & CEO",
        "name" => [
            "first" => "Tobie",
            "last" => "Morgan Hitchcock",
        ],
        "marketing" => true,
    ])
    ->execute();

// Select the person by its record id.
$person = $db->select($tobie)->execute();

// Merge new fields into the person.
$person = $db->update($tobie)->merge(["age" => 31])->execute();

// Select every person.
$people = $db->select(new Table("person"))->execute();

// Run SurrealQL with bound parameters. There is one result per statement.
[$groups] = $db->run('SELECT marketing, count() FROM $tb GROUP BY marketing', [
    "tb" => new Table("person"),
]);

// Delete the person.
$db->delete($tobie)->execute();

// Close the connection.
$db->close();
```

## Observability

The SDK emits OpenTelemetry traces and metrics for every RPC. Enable it per runtime through the `Runtime` presets, which pick the export strategy that fits each environment:

- **PHP-FPM / CLI** (`Runtime::sync`): spans are buffered in a batch processor and flushed once the request finishes (after `fastcgi_finish_request()` under FPM), so export never adds to user-visible latency.
- **OpenSwoole / FrankenPHP (Amp)** (`Runtime::swoole` / `Runtime::amp`): each span is exported directly with no batching, over a non-blocking transport (Swoole runtime hooks, or the Amp PSR-18 client).

```php
use SurrealDB\Runtime\Runtime;
use SurrealDB\Surreal;
use SurrealDB\Telemetry\OpenTelemetry\ObservabilityOptions;

$observability = new ObservabilityOptions(
    endpoint: "http://localhost:4318", // OTLP collector
    serviceName: "my-app",
);

// PHP-FPM / CLI: batch in memory, flush after the request.
$db = new Surreal(Runtime::sync(observability: $observability));

// OpenSwoole / FrankenPHP: direct, non-blocking export.
$db = new Surreal(Runtime::swoole(observability: $observability));
$db = new Surreal(Runtime::amp(observability: $observability));
```

Requires `open-telemetry/sdk` and `open-telemetry/exporter-otlp` (plus `amphp/http-client-psr7` for the Amp runtime).

### Flushing after the response in a framework

When the framework owns the response lifecycle (e.g. Laravel), build the providers yourself and flush in a terminable hook rather than relying on the shutdown handler:

```php
use SurrealDB\Connection\DriverOptions;
use SurrealDB\Surreal;
use SurrealDB\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\Telemetry\OpenTelemetry\OtelObservability;

$telemetry = OtelObservability::batched(new ObservabilityOptions(serviceName: "my-app"));

$db = new Surreal(new DriverOptions(
    tracer: $telemetry->tracer(),
    meter: $telemetry->meter(),
));

// Laravel: flush after the response has been sent to the client.
app()->terminating(static fn () => $telemetry->forceFlush());
```

## Agent Memory

The SDK includes a typed client for Agent Memory, the memory and knowledge layer for AI agents built on SurrealDB. It writes facts from text or structured triples, recalls them, answers "what do you know about X?", ingests documents, and chats over memory. A client is pinned to one Agent Memory context and authenticates with a context API key.

```php
use SurrealDB\AgentMemory;
use SurrealDB\AgentMemory\AgentMemoryOptions;
use SurrealDB\AgentMemory\Options\RecallOptions;

$memory = new AgentMemory(new AgentMemoryOptions(
    endpoint: "https://memory.example.com",
    context: "acme_prod", // letters, digits, and underscores
    apiKey: $apiKey, // a context API key, "sp-…"
));

$memory->remember("I just got promoted to CTO at Acme");

$hits = $memory->recall("What is my role?", new RecallOptions(k: 5));

foreach ($memory->chatStream("What do you know about my job?") as $chunk) {
    echo $chunk->delta;
}
```

The API follows [`@surrealdb/memory`](https://github.com/surrealdb/surrealdb.js/tree/main/packages/memory), the JavaScript client: the same namespaces and method names, with options as readonly PHP classes built with named arguments (`new RecallOptions(k: 5)`). Enum-typed options also accept the raw wire string. [Differences from `@surrealdb/memory`](#differences-from-surrealdbmemory) lists where PHP departs from it.

### Namespaces

| Namespace | Methods |
| --- | --- |
| `$memory` | `remember`, `rememberMany`, `recall`, `lookup`, `forget`, `chat`, `chatStream`, `context`, `reflect`, `consolidate`, `elaborate`, `fsck`, `inspect`, `audit`, `auditAll`, `state`, `profile`, `whoami`, `health`, `onBehalfOf` |
| `$memory->documents` | `upload`, `reprocess`, `get`, `raw`, `chunks`, `allChunks`, `list`, `listAll`, `count`, `delete`, `query`, `recomputeLinks`, `keywords->*` |
| `$memory->documents->keywords` | `list`, `listAll`, `search`, `get`, `forDocument` |
| `$memory->entities` | `list`, `listAll`, `count`, `search`, `top`, `get`, `neighbours`, `allNeighbours`, `changes`, `allChanges`, `history`, `delete` |
| `$memory->facts` | `attributes`, `allAttributes`, `relations`, `allRelations`, `allEdgesOf`, `actions`, `allActions` |
| `$memory->uncertainty` | `list`, `listAll`, `count`, `resolve` |
| `$memory->sessions` | `create`, `attach` → `Session` (`turns`, `allTurns`, `context`, `close`) |
| `$memory->lifecycle` | `expire`, `decay` |
| `$memory->traces` | `list`, `listAll`, `get`, `stats` |
| `$memory->principals` | `list`, `listAll`, `get`, `effective`, `grant`, `revoke` |
| `$memory->scopes` | `list`, `listAll`, `register`, `delete`, `forget` |
| `$memory->keys` | `create`, `list`, `listAll`, `delete`, `rotate` |

`AgentMemory` and each namespace implement an interface in `SurrealDB\AgentMemory\Contracts` (`AgentMemoryInterface`, `EntitiesInterface`, and so on), where the methods are documented. Type your code against the interfaces to substitute a test double or a decorator. The namespaces are interface properties, so a PHPUnit stub returns them through `PropertyHook::get()`, and `new Page($rows)` stands in for a collection:

```php
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use SurrealDB\AgentMemory\Contracts\AgentMemoryInterface;
use SurrealDB\AgentMemory\Contracts\EntitiesInterface;
use SurrealDB\AgentMemory\Page;

$entities = $this->createStub(EntitiesInterface::class);
$entities->method("list")->willReturn(new Page([["type" => "person", "name" => "alice"]]));

$memory = $this->createStub(AgentMemoryInterface::class);
$memory->method(PropertyHook::get("entities"))->willReturn($entities);
```

### Structured facts and lookup

Write facts verbatim, with no model extraction, as triples. Then ask what memory knows about a subject: `lookup()` is deterministic and returns only stored rows.

```php
use SurrealDB\AgentMemory\Enum\InferMode;
use SurrealDB\AgentMemory\Options\RememberOptions;
use SurrealDB\AgentMemory\Triple;

$memory->remember(options: new RememberOptions(
    infer: InferMode::TRIPLES,
    triples: [
        Triple::attribute("person/alice", "role", "CTO"),
        Triple::relation("person/alice", "works_at", "organisation/acme"),
        Triple::action("person/alice", "shipped", object: "the v2 release", occurredAt: "2026-09-01T10:00:00Z"),
    ],
));

$answer = $memory->lookup("alice");
$answer["resolution"]["kind"]; // "entity", "ambiguous", "topic", or "empty"
$answer["facts"]["items"];     // the subject's attributes, ranked by importance
```

The fact families are also collections of their own (`$memory->facts->attributes()`, `->relations()`, `->actions()`), next to `$memory->entities` (search, rankings, `changes()` history, and `neighbours()`) and `$memory->uncertainty` (open questions and their settlement). `Triple::entity("person/alice")` records an entity with no facts, and `rememberMany()` takes `Message::user()`, `assistant()`, `system()`, and `tool()` turns.

### Uploads

Upload a `DocumentFile`, which carries the bytes with their filename and media type. Ingestion runs in the background: the upload returns a handle, and `get()` reports the document's `status` until it is `ready` (or `failed`).

```php
use SurrealDB\AgentMemory\DocumentFile;
use SurrealDB\AgentMemory\Options\DocumentUploadOptions;

$upload = $memory->documents->upload(new DocumentUploadOptions(
    DocumentFile::fromPath("handbook.pdf"),
    title: "Employee handbook",
));

while (!in_array($memory->documents->get($upload["id"])["status"], ["ready", "failed"], true)) {
    sleep(1);
}
```

Build a `DocumentFile` with `fromString()`, `fromPath()`, `fromStream()` (read whole, from the start when the stream is seekable), or `fromUploadedFile()` (a PSR-7 upload, keeping the client's filename and media type unless that is `application/octet-stream`). The media type sent is the first of:

1. the `contentType` option;
2. the `DocumentFile`'s type;
3. the type of the filename's extension (the `filename` option, else the file's), for the formats the server ingests;
4. none, so the server sniffs the bytes: text is ingested as `text/plain`, and other bytes are refused with an `UnsupportedMediaTypeException`.

A blank `contentType` or `filename` option counts as none. A plain string or a PSR-7 stream also works as the `file`. Bodies are read into memory before they are sent, as in `@surrealdb/memory`.

### Pagination

Collections return a `Page`: its rows in `items`, plus `hasMore`, `nextCursor`, `totalSize` (only when the request set `count: true`, which costs the server a full count), and the decoded response in `raw`. Iterating a page walks its rows, `next()` fetches the following page, and `autoPaginate()` lazily walks every page, keeping the original filters.

Every listing also has a collector that walks the pages for you and returns one list: `listAll()` on each namespace that lists, `auditAll()`, `$session->allTurns()`, and the `all*()` methods. They read the whole collection, so use them when the collection is small or you need all of it. Six of them take a `max`: `documents->allChunks()`, `entities->allChanges()`, `entities->allNeighbours()`, and `facts->allAttributes()`, `allRelations()`, and `allActions()`. They stop after the page that reaches `max`, so up to one page more can come back. `max` must be at least 1, and a collector refuses options that set `cursor` or `count` with an `InvalidArgumentException`.

`count()` on documents, entities, and uncertainty sends one `limit=1&count=true` request and returns the total. `facts->allEdgesOf()` returns every relation that touches an entity: its outbound edges, then its inbound ones, with no de-duplication, so a self-loop appears twice.

```php
use SurrealDB\AgentMemory\Options\ActionListOptions;
use SurrealDB\AgentMemory\Options\EntityListOptions;

foreach ($memory->entities->list(new EntityListOptions(type: "person"))->autoPaginate() as $person) {
    echo $person["name"], PHP_EOL;
}

$people = $memory->entities->count(new EntityListOptions(type: "person"));
$recent = $memory->facts->allActions(new ActionListOptions(actor: "person/alice"), max: 200);
$edges = $memory->facts->allEdgesOf("person/alice");
```

### Scope

Write calls (`remember`, `rememberMany`, `chat`, `sessions->create`, and `documents->upload`) take a `scopes` option, and read calls (`recall`, `context`, `documents->query`, and `documents->keywords->search`) take the same shape as `lens`. The wire format is a DNF selector, `string[][]`: the outer array is an OR of clauses, and each inner array is an AND of `key/value` slash-paths. So `[["team/a"], ["team/b", "clearance/secret"]]` means `team/a OR (team/b AND clearance/secret)`.

A bare string is a single-path clause, and a flat list is an OR of single-path clauses. `Scope::normalise()` turns every form into the wire shape, dropping empty paths and empty clauses. Omit `scopes` to write to the key's default region.

```php
use SurrealDB\AgentMemory\Options\RecallOptions;
use SurrealDB\AgentMemory\Options\RememberOptions;

$memory->remember("…", new RememberOptions(scopes: "team/eng"));                 // [["team/eng"]]
$memory->remember("…", new RememberOptions(scopes: ["team/eng", "org/acme"]));   // OR: [["team/eng"], ["org/acme"]]
$memory->remember("…", new RememberOptions(scopes: [["team/eng", "org/acme"]])); // AND: [["team/eng", "org/acme"]]

$memory->recall("…", new RecallOptions(lens: "team/eng/*"));
```

Register a scope path with `$memory->scopes->register()` before you write to it: a write to an unregistered path fails with a `ValidationException`. A read lens narrows the region a query reads from, and `/*` selects a subtree. It can only narrow: the key's grants still apply, so a lens outside them returns no rows rather than a 403.

### Delegation

`onBehalfOf()` returns a new client whose every request runs on behalf of another principal. The original client is unchanged.

```php
$asAlex = $memory->onBehalfOf("principal:alex");
$asAlex->remember("Reviewed the Q3 plan");
$asAlex->recall("What did Alex review?");
```

The client sends the id in the `X-Spectron-On-Behalf-Of` header. That name is a wire constant, not branding: the server reads exactly that spelling. The target principal must allow delegation, and requests run with the intersection of the caller's grants and the target's. A blank id throws an `InvalidArgumentException`, because the server would run the requests as the caller.

### Streaming chat

`chatStream()` is the PHP form of `chat(message, { stream: true })` in `@surrealdb/memory`. It yields a `ChatChunk` per frame, tagged with a `ChatEvent`: `META` (the trace and session ids), one `CHUNK` per piece of reply text in `delta`, then `DONE`, whose chunk carries the full `reply`, its `citations`, and the `memoryUpdates`.

```php
use SurrealDB\AgentMemory\Enum\ChatEvent;

foreach ($memory->chatStream("What do you know about my job?") as $chunk) {
    if ($chunk->event === ChatEvent::CHUNK) {
        echo $chunk->delta;
    }

    if ($chunk->done) {
        $citations = $chunk->citations;
    }
}
```

The message is checked when you call `chatStream()`; the request is sent when the iteration starts. The stream raises a `StreamException` when the server sends an `error` event, or when it ends before its `done` frame. Tokens arrive as they are written only when the PSR-18 client streams response bodies (with Guzzle, set `"stream" => true`); a buffering client yields every chunk at once.

### Errors and retries

Failed requests throw a subclass of `SurrealDB\AgentMemory\Exceptions\AgentMemoryException`, chosen by status. Invalid input fails before any request is sent:

| Cause | Exception |
| --- | --- |
| invalid input, before any request | `InvalidArgumentException` |
| 400, 422 | `ValidationException` |
| 401 | `AuthException` |
| 403 | `ScopeException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 415 | `UnsupportedMediaTypeException` |
| 429 | `RateLimitException` |
| 5xx | `ServerException` |
| network failure | `ConnectionException` |
| chat stream `error` event or cut-off stream | `StreamException` |

`InvalidArgumentException` extends PHP's `\InvalidArgumentException`, not `AgentMemoryException`. Every exception the SDK raises itself, in this client and in the database client, implements `SurrealDB\Exceptions\SurrealExceptionInterface`, and this client also wraps PSR-18 client failures in `ConnectionException`:

```php
use SurrealDB\AgentMemory\Exceptions\RateLimitException;
use SurrealDB\Exceptions\SurrealExceptionInterface;

try {
    $hits = $memory->recall($question);
} catch (RateLimitException $e) {
    // still throttled after the retries; the server asked for $e->retryAfter seconds
} catch (SurrealExceptionInterface $e) {
    // any other SDK error: invalid input, a failed request, or a lost connection
}
```

`NotFoundException`, `ServerException`, and `ValidationException` exist in both `SurrealDB\Exceptions` and `SurrealDB\AgentMemory\Exceptions`, so import the Agent Memory ones.

Retries follow what each status guarantees:

- A 503 means the server shed load or could not reach a dependency, and did no work, so every request is retried on it.
- `GET` reads and the requests marked idempotent are also retried on connection failures and on 408, 429, 500, 502, and 504. Those are the fact writes `remember` and `rememberMany`, and the read-only POSTs `recall`, `lookup`, `context`, `documents->query`, `documents->keywords->search`, and `$session->context`. They carry an `Idempotency-Key`: the server de-duplicates the writes by it and ignores it on the reads. A 409 is retried when it says the first attempt of the same write is still in progress.
- Other requests (such as `forget`, `chat`, and uploads) are not retried on those, because the first attempt may already have done its work.

The back-off waits 250 ms, 500 ms, then 1 s between retries, up to `maxRetries` of them (3 by default). A longer `Retry-After` from the server raises the wait, up to 4 s; it is also exposed as `$e->retryAfter`.

### Timeouts

The client sets no timeouts of its own: the PSR-18 client owns them, so configure them there. `@surrealdb/memory` defaults to 30 seconds per attempt, and sets none on uploads. With Guzzle:

```php
$memory = new AgentMemory(new AgentMemoryOptions(
    endpoint: "https://memory.example.com",
    context: "acme_prod",
    apiKey: $apiKey,
    httpClient: new \GuzzleHttp\Client(["timeout" => 30, "connect_timeout" => 5]),
));
```

The request and stream factories are still discovered. A chat stream stays open for as long as the reply takes, so give the client you stream with a longer `timeout`.

### Typed responses

Responses are plain decoded arrays. For static analysis, each method that returns JSON declares the PHPStan array shape of its response. The shapes are generated from the server's OpenAPI description into `SurrealDB\AgentMemory\Schema\Schemas`, and named as in that description and in `@surrealdb/memory` (`DocumentJson`, `LookupResponseJson`, and so on). A collection is a `Page<DocumentJson>`, and its collector returns a `list<DocumentJson>`. `Page` is covariant, so a `Page<DocumentJson>` is also accepted where a plain `Page` is declared. Enum values are literal string unions, so keep a default arm when you `match` on one: the server can add values.

Import a shape to use it in your own types:

```php
use SurrealDB\AgentMemory\Contracts\AgentMemoryInterface;
use SurrealDB\AgentMemory\Enum\DocumentStatus;
use SurrealDB\AgentMemory\Options\DocumentListOptions;
use SurrealDB\AgentMemory\Schema\Schemas;

/**
 * @phpstan-import-type DocumentJson from Schemas
 */
final class ReadyDocuments
{
    public function __construct(private AgentMemoryInterface $memory) {}

    /** @return list<DocumentJson> */
    public function all(): array
    {
        return $this->memory->documents->listAll(new DocumentListOptions(status: DocumentStatus::READY));
    }
}
```

### Differences from @surrealdb/memory

Where the PHP client departs from the JavaScript one on purpose:

- Streaming chat is `chatStream()`, because PHP cannot vary a return type on an option.
- `elaborate()` requires `ElaborateOptions` with an `entityRef` or `sweep: true`: the server refuses a request with neither with a 400.
- Collections return a `Page` instead of the response envelope, which stays in `$page->raw`.
- Retries: a 503 is retried for every request, `Retry-After` is honoured, idempotent requests are also retried on 408 and 429 but not on 501 or 505, and an idempotent write waits out its own attempt in progress.
- A chat stream that ends before its `done` frame raises a `StreamException` instead of ending quietly.
- An upload with no known media type is sent without one, so the server sniffs the bytes; `@surrealdb/memory` declares `application/octet-stream`, which the server refuses. An explicit `contentType` also wins over a `DocumentFile`'s type, while `@surrealdb/memory` ignores it for a `File`.
- 409 and 415 raise `ConflictException` and `UnsupportedMediaTypeException`, `Retry-After` is read for every status rather than only 429, and invalid input fails with an `InvalidArgumentException` before any request.
- A chat frame with an event name this client does not know has a `null` `$chunk->event`, while `@surrealdb/memory` keeps the string.
- There are no per-request timeouts, cancellation signals, or upload progress listeners: timeouts belong to the PSR-18 client (see [Timeouts](#timeouts)).

What PHP adds:

- the `AgentMemoryRuntime` presets for OpenSwoole and Amp (see [Async runtimes](#async-runtimes));
- `EntityRef`; the `Triple::attribute()`, `relation()`, `action()`, and `entity()` builders; and `Message::user()`, `assistant()`, `system()`, and `tool()`;
- `$memory->sessions->attach()`, for a session created earlier;
- `Page::next()`, `autoPaginate()`, and `collect()`;
- `DocumentFile`;
- the enums `MemoryQueryMode`, `ResultFamily`, `TraceKind`, `GraphEdgeKind`, and `ChatEvent`;
- API fields `@surrealdb/memory` does not send yet: `observedAt` and `addressees` on `remember`, `includeDuplicates` on `recall`, `dryRun` on `forget`, and `observedAt` and `observedAtHeader` on uploads;
- the `Contracts` interfaces and the PHPStan response types.

### Async runtimes

Pass `runtime: AgentMemoryRuntime::swoole()` (OpenSwoole coroutines) or `runtime: AgentMemoryRuntime::amp()` (Amp/Revolt fibers, for example FrankenPHP worker mode) to `AgentMemoryOptions`, so requests and retry back-off cooperate with the event loop.

## Contributing

### Requirements
- PHP 8.4 or higher
- Composer
- SurrealDB 2.6.2 or higher

### Run static analysis
```bash
composer analyse
```

### Run tests
```bash
composer test
```

### Run the Agent Memory tests

The unit tests need no server:

```bash
composer test:agent-memory
```

The integration tests run against a real Agent Memory server. Build `spectrond` from [surrealdb/spectron](https://github.com/surrealdb/spectron) with `cargo build --bin spectrond`, install [SurrealDB](https://surrealdb.com/install), then start a throwaway server. The script bootstraps an in-memory database with one context and writes the client settings to `.agent-memory/env`:

```bash
SPECTROND_BIN=../spectron/target/debug/spectrond scripts/agent-memory-server.sh start
```

Load the settings and run the suite:

```bash
set -a && . .agent-memory/env && set +a && composer test:agent-memory-integration
```

Stop the server when you are done:

```bash
scripts/agent-memory-server.sh stop
```

Without model keys the server runs on placeholders: everything except recall, context, forget, chat, and document ingestion works, and the tests that need those skip. Set `GEMINI_API_KEY` before `start` to run them too.

Set `AGENT_MEMORY_OPENAPI_SPEC` to spectron's `doc/spec/enduser.swagger.json` to also check the client against the server's API description. That check needs only the file, not a server, and it also fails when the generated response types no longer match the description:

```bash
AGENT_MEMORY_OPENAPI_SPEC=../spectron/doc/spec/enduser.swagger.json vendor/bin/phpunit tests/Integration/AgentMemory/SpecCoverageTest.php
```

When the description changes, regenerate the types in `src/AgentMemory/Schema/Schemas.php` instead of editing them:

```bash
composer generate:agent-memory-types -- ../spectron/doc/spec/enduser.swagger.json
```

The [`Agent Memory` workflow](.github/workflows/agent-memory.yml) runs all of this in CI. Pushes and pull requests build spectron at the `SPECTRON_REF` repository variable, or at `main` when it is unset, so an upstream change to a response schema can fail an unrelated pull request until the types are regenerated. Set `SPECTRON_REF` to a known-good branch, tag, or SHA to pin them; the nightly run always tests `main`.

### Directory Structure

- `src` - The source code of the library
- `tests` - The unit tests of the library
