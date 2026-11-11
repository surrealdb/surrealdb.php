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

> **v2 is a rewrite.** The API below is not compatible with 1.x: the entry point is `SurrealDB\Surreal`, statements are built with fluent query builders, and authentication and options are typed objects. See [Upgrading from 1.x](#upgrading-from-1x).

## Documentation

View the SDK documentation [here](https://surrealdb.com/docs/integration/libraries/php).

## Installation

v2 is in alpha, so ask Composer for it explicitly (a plain `composer require` installs 1.x):

```sh
composer require surrealdb/surrealdb.php:^2.0@alpha
```

Requires PHP 8.4 or later. The WebSocket engine needs nothing extra for queries. The HTTP engine, `health()`, `import()` and `export()` on any connection, and OpenTelemetry export need a PSR-18 client and PSR-17 factories (under `Runtime::amp()`, only the factories), found through `php-http/discovery`. The [Agent Memory](#agent-memory) client needs them too:

```sh
composer require guzzlehttp/guzzle php-http/discovery
```

Without discovery, pass the implementations for the HTTP engine, `health()`, `import()` and `export()` through `DriverOptions` (`httpClient`, `requestFactory`, `streamFactory`). OpenTelemetry export doesn't read those, so it still needs discovery unless you pass your own span and metric exporters to `OtelObservability`.

The SDK supports SurrealDB 2.x, 3.x and 4.x. Sessions, transactions, `api()` and refresh tokens need SurrealDB 3.0, and `gql()` / `graphql()` need 3.2.

## Getting started

```php
use SurrealDB\Auth\RootAuth;
use SurrealDB\Connection\ConnectOptions;
use SurrealDB\Surreal;
use SurrealDB\Types\RecordId;
use SurrealDB\Types\Table;

$db = new Surreal();

// ws:// or wss:// for WebSocket, http:// or https:// for HTTP.
$db->connect('ws://127.0.0.1:8000/rpc', new ConnectOptions(
    namespace: 'test',
    database: 'test',
    authentication: new RootAuth('root', 'root'),
));

// Create a record with a specific id.
$tobie = $db->create(new RecordId('person', 'tobie'))
    ->content(['name' => 'Tobie', 'marketing' => true])
    ->execute();

// Select one record, or a whole table.
$person = $db->select(new RecordId('person', 'tobie'))->execute();
$people = $db->select(new Table('person'))->where('marketing = true')->limit(10)->execute();

// Update a record.
$db->update(new RecordId('person', 'tobie'))->merge(['age' => 31])->execute();

// Run raw SurrealQL, with bound parameters. One result per statement.
[$groups] = $db->run('SELECT marketing, count() FROM type::table($tb) GROUP BY marketing', [
    'tb' => 'person',
]);

$db->close();
```

A plain string target is always a **table name**, escaped as an identifier. To target a record, use a `RecordId` (or `StringRecordId` for an id you already have as text, such as `'person:tobie'`); a string is never interpolated into the query.

### Authentication

Pass credentials in `ConnectOptions::$authentication` to have the SDK sign in, renew tokens before they expire, and sign in again after a reconnect. Or sign in explicitly:

```php
use SurrealDB\Auth\DatabaseAuth;
use SurrealDB\Auth\NamespaceAuth;
use SurrealDB\Auth\RecordAccessAuth;

$tokens = $db->signin(new RecordAccessAuth('test', 'test', 'user', ['email' => $email, 'pass' => $pass]));
$db->signup(new RecordAccessAuth('test', 'test', 'user', ['email' => $email, 'pass' => $pass]));
$db->signin(new NamespaceAuth('test', 'admin', 'secret'));
$db->signin(new DatabaseAuth('test', 'test', 'admin', 'secret'));

$db->authenticate($tokens->access);
$me = $db->auth()->execute();   // the signed-in record
$db->invalidate();
```

### Query builders

Every builder runs with `execute()`; `compile()` returns the SurrealQL and bindings without running it, and `json()` returns plain JSON-compatible values instead of SurrealDB types. Live queries are the exception: their notifications always carry SurrealDB types.

```php
use SurrealDB\Enum\Output;

$db->select(new Table('person'))->fields('name', 'age')->where('age > 18')->start(20)->limit(10)->fetch('friends')->execute();
$db->create(new Table('person'))->content(['name' => 'Jaime'])->output(Output::AFTER)->execute();
$db->update(new Table('person'))->merge(['active' => true])->where('last_seen > time::now() - 1w')->execute();
$db->upsert(new RecordId('person', 'tobie'))->replace(['name' => 'Tobie'])->execute();
$db->delete(new RecordId('person', 'tobie'))->execute();
$db->insert(new Table('person'), [['name' => 'A'], ['name' => 'B']])->ignore()->execute();
$db->relate(new RecordId('person', 'tobie'), new Table('knows'), new RecordId('person', 'jaime'), ['since' => 2020])->execute();
$db->run('DEFINE FUNCTION fn::greet($name: string) { RETURN "Hello, " + $name }');
$db->call('fn::greet', args: ['Tobie'])->execute();   // "Hello, Tobie"
```

Each `where()` adds a condition, and a record must meet all of them; earlier 2.0 alphas kept only the last one. Each condition goes in parentheses, so a `--` comment or a `;` in it cannot reach the rest of the statement. An empty array given to `content()`, `merge()` or `replace()` is an empty record. `call()` takes names made of `::`-joined parts (`fn::greet`, `string::len`) and the built-ins `count`, `not`, `rand` and `sleep`, with a `major.minor.patch` version; anything else throws `ExpressionException` before it is sent. A `RecordId` whose id is a `Range` targets every record in the range, and returns a list. A range written as text in a `StringRecordId` is refused, because servers read it in different ways, and so is a range at either end of `relate()`. `output(Output::NONE)` on one record returns `None` on every server version.

Multi-statement SurrealQL can be streamed, or inspected without throwing on failed statements:

```php
use SurrealDB\Query\Frame\DoneFrame;
use SurrealDB\Query\Frame\ErrorFrame;
use SurrealDB\Query\Frame\ValueFrame;

foreach ($db->statements('SELECT * FROM person; SELECT * FROM post')->stream() as $frame) {
    if ($frame instanceof ValueFrame) {
        // $frame->query is the statement index, $frame->value one record
    } elseif ($frame instanceof ErrorFrame) {
        // $frame->error; the stream continues with the next statement
    }
}

foreach ($db->statements('RETURN 1; THROW "boom"')->responses() as $response) {
    $response->success ? $response->result : $response->error;
}
```

### Data types

SurrealDB values map to classes in `SurrealDB\Types`: `RecordId`, `RecordIdRange`, `StringRecordId`, `Table`, `Uuid`, `DateTime`, `Duration`, `Decimal`, `Range`, `Set`, `File`, `Bytes`, `Future`, `None` and the `Geometry*` types. They are sent and received as CBOR (the default) and keep their type in both directions, with one exception: a `StringRecordId` comes back as a `RecordId`, because the server never returns the string form. Text must be valid UTF-8, so send binary data as `Bytes`; it comes back as `Bytes` too (earlier 2.0 alphas returned a plain `string`, which `(string) $bytes` still gives). `Set` and `File` need SurrealDB 3.0 or later, and sending one to an older server throws `UnavailableFeatureException`; `File` also needs a server with the experimental files capability enabled. `Future` is only for SurrealDB 2.x, since 3.0 removed futures.

`RecordId::equals()` compares ids the way the server does: `person:1` and `person:'1'` are different records, `person:[1]` and `person:[1.0]` are the same one, and the order of an object id's keys does not count. An object used in a record id, a `Set` or a range bound must be one of the `SurrealDB\Types` values, a `stdClass` or a `JsonSerializable`; any other object, a `Stringable` included, throws `InvalidValueException` rather than being written into the query as its text. A `Set` keeps `1`, `'1'` and `true` apart. A `Duration` can be longer than 292 years; `totalNanoseconds()` throws past that, so read `$seconds` and `$nanoseconds` instead. `new Decimal($float)` keeps every digit the float holds, and decimal text from the server that is not a number throws `SerializationException`. The credentials classes, `Token`, `Tokens` and the session state show `<redacted>` in `var_dump()` and `print_r()`, so a password or token does not end up in a log.

## Sessions and transactions

On a WebSocket connection, `Surreal` is the default session. Open more sessions on the same connection, each with its own namespace, database, variables and authentication; every builder works on them.

```php
$session = $db->newSession();               // signed in only by ConnectOptions::$authentication
$session->use('test', 'other');
$session->let('tenant', 'acme');
$session->select(new Table('person'))->execute();

$fork = $db->forkSession();                 // copies ns, db, variables and the access token, e.g. after signin()
$db->sessions();                            // ids of the attached sessions
$fork->closeSession();
```

A fork does not copy the refresh token, which the server lets only one session use. A source whose access token has expired is renewed first, so the fork signs in with the new token. Once the copied access token expires, the fork renews through `ConnectOptions::$authentication` if the source was signed in by it; otherwise sign the fork in again.

`let()` keeps a copy of the value, so changing it afterwards does not change the variable, and refuses the names the server keeps for itself: `access`, `auth`, `token` and `session`.

Interactive transactions run every query made through them until they are committed or cancelled:

```php
$txn = $db->beginTransaction();             // or $session->beginTransaction()

try {
    $txn->update(new RecordId('account', 'a'))->merge(['balance' => 50])->execute();
    $txn->update(new RecordId('account', 'b'))->merge(['balance' => 150])->execute();
    $txn->commit();
} catch (\Throwable $error) {
    // A commit the server rejected (a write conflict, say) has already
    // ended the transaction.
    if (!$txn->isFinished()) {
        $txn->cancel();
    }

    throw $error;
}
```

Transactions need the default CBOR format. Resetting or closing the session, or losing the connection, also ends them; `isFinished()` reports it.

## Live queries

Live queries need a WebSocket connection. Over HTTP, `execute()` throws `UnsupportedFeatureException`.

```php
use SurrealDB\Live\LiveAction;

$live = $db->live(new Table('person'))->where('age >= 18')->execute();

// Iteration waits for the next notification, so stop from inside the loop.
foreach ($live as $message) {
    match ($message->action) {
        LiveAction::Create, LiveAction::Update => handle($message->value),
        LiveAction::Delete => forget($message->record),
        LiveAction::Killed => null, // the last message
    };

    if (shouldStop()) {
        $live->kill();              // ends the iteration
    }
}
```

Live queries the SDK started are re-issued after a reconnect, and iteration carries on across it. `liveOf($id)` attaches to a live query started elsewhere (for example by `LIVE SELECT` in `run()`), and `kill($id)` stops one by id.

A reconnect restarts a live query in the namespace and database it started in, and only as the user who started it. A query whose session is signed in as someone else by then, or signed out, ends instead; so does one whose session the auth provider renews as another user. Reading a subscription that ended because the connection was lost throws `ConnectionUnavailableException`, once what arrived before has been read: when reconnecting gave up or is off, for a `liveOf()` subscription (which a reconnect does not restart), and for a query the server refused to start again. A killed subscription, or one whose connection you closed, ends quietly.

> Earlier 2.0 alphas took a live query id in `live($id)`. That is now `liveOf($id)`: `live()` builds a `LIVE SELECT`, and iterating the builder instead of what `execute()` returns throws.

## Connections

`ConnectOptions::$reconnect` (on by default) reconnects with exponential backoff and restores every session's namespace, database and variables. It restores a session's authentication when it can. It reuses an access token that expires more than a minute from now. Failing that, it uses a refresh token, or, for a session you didn't sign in or out explicitly, signs in again with `ConnectOptions::$authentication`. A session where none of these works comes back signed out, and an `auth` event reports `null`. With `ConnectOptions::$invalidateOnExpiry`, a session is signed out when its token expires instead of renewed, and stays signed out; a reconnect before then still reuses the token. One example is a session signed in explicitly with a token about to expire and no refresh token. Under the default synchronous scheduler the SDK notices a dropped connection when it next uses it. A call that finds the socket dead fails, and the next one runs on the new connection; `status()` checks the socket and reconnects on the spot, so it reports `Connected` once that has worked.

If the auth provider is refused while renewing a session (a fixed token that has expired, or a password that changed), the session keeps its token until it expires, is then signed out, and an `error` event reports it; on a reconnect, it comes back signed out. A refresh token the server refuses is dropped and reported the same way. A WebSocket frame that takes more than 30 seconds to write, because the server stopped reading, closes the connection, which then reconnects; so does a read that fails part way.

Over HTTP the SDK keeps each session's variables and sends them with every query. The namespace and database go in headers, which take printable ASCII only, so `use()` refuses other names over HTTP; a WebSocket takes any. Sign-in requests go without the session's old token, so a session whose token has expired signs in again. Behind a proxy that refuses requests without a token, the version request (sent before signing in) may be refused: the SDK then signs in with `ConnectOptions::$authentication` and asks again.

```php
$db->connect('wss://example.com/rpc', new ConnectOptions(
    reconnect: ['attempts' => 10, 'retryDelay' => 0.5, 'retryDelayMax' => 30],
));

$db->subscribe('reconnecting', fn () => $logger->warning('Reconnecting to SurrealDB'));
$db->status();          // ConnectionStatus::Connecting, Connected, Reconnecting or Disconnected
$db->version();
```

Connection events are also dispatched as PSR-14 events (`SurrealDB\Events\*`) through `DriverOptions::$events`, and the SDK logs through any PSR-3 logger in `DriverOptions::$logger`.

### Async runtimes

The default runtime is blocking. For long-running workers, use a runtime preset. Each needs its own packages:

```sh
composer require openswoole/core                     # Runtime::swoole(), plus the openswoole extension
composer require revolt/event-loop amphp/websocket-client amphp/http-client   # Runtime::amp()
```

```php
use SurrealDB\Runtime\Runtime;

$db = new Surreal(Runtime::swoole());   // inside a coroutine, e.g. Co\run() or an OpenSwoole server
$db = new Surreal(Runtime::amp());      // inside the Revolt event loop, e.g. FrankenPHP worker mode
```

These run a background read loop, keepalive pings and token renewal timers. While a lost connection is re-established, calls from other tasks fail with `ConnectionUnavailableException`, until every session is restored on the new socket: signed in, back in its namespace and database, with its variables and live queries. From then on `status()` is `Connected` again, and calls go ahead.

## More

```php
// User-defined API endpoints (DEFINE API, SurrealDB 3.0+).
$response = $db->api('/v1')->post('orders', ['item' => 'book'])->execute();   // ApiResponse
$body = $db->api('/v1')->get('orders/1')->value()->execute();                   // body, throws on non-2xx

// ISO GQL graph queries (SurrealDB 3.3+, or a 3.2 server started with
// `--allow-experimental gql`).
$db->gql('MATCH (a:person)-[:knows]->(b:person) WHERE a.name = $name RETURN b.name AS friend', ['name' => 'Tobie']);

// GraphQL over tables with defined fields (SurrealDB 3.2+; needs `DEFINE CONFIG GRAPHQL`).
$db->graphql('query Person($id: ID!) { person(id: $id) { name } }', ['id' => 'tobie'], 'Person')->dataOrThrow();

// Export SurrealQL, then import it into another, empty database.
$surql = $db->export();
$db->run('DEFINE DATABASE IF NOT EXISTS copy');   // strict servers don't create it on use()
$db->use('test', 'copy');
$db->import($surql);
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

Requires `open-telemetry/sdk`, `open-telemetry/exporter-otlp` and discoverable PSR-17 factories (see [Installation](#installation)). The Amp runtime also needs `amphp/http-client-psr7` as its PSR-18 client; the other runtimes need a PSR-18 client from that installation.

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
- The deprecated offset parameters `page` and `pageSize` (on `documents->list()`, `documents->chunks()`, and `documents->keywords->list()`) are not offered: page with `limit` and `cursor`.
- A lone optional value is a named argument, not an options class: `uncertainty->resolve($id, $value, note: …)`, `facts->allEdgesOf($entity, label: …, limit: …)`, `$session->context($query)`, and `max:` on the collectors.
- Retries: a 503 is retried for every request, `Retry-After` is honoured, idempotent requests are also retried on 408 and 429 but not on 501 or 505, and an idempotent write waits out its own attempt in progress.
- A chat stream that ends before its `done` frame raises a `StreamException` instead of ending quietly.
- An upload with no known media type is sent without one, so the server sniffs the bytes; `@surrealdb/memory` declares `application/octet-stream`, which the server refuses. An explicit `contentType` also wins over a `DocumentFile`'s type, while `@surrealdb/memory` ignores it for a `File`.
- 409 and 415 raise `ConflictException` and `UnsupportedMediaTypeException`, `Retry-After` is read for every status rather than only 429, and invalid input fails with an `InvalidArgumentException` before any request.
- A chat frame with an event name this client does not know has a `null` `$chunk->event`, while `@surrealdb/memory` keeps the string.
- There are no per-request timeouts, cancellation signals, or upload progress listeners: timeouts belong to the PSR-18 client (see [Timeouts](#timeouts)).

What PHP adds:

- the `AgentMemoryRuntime` presets for OpenSwoole and Amp (see [Async runtimes](#agent-memory-on-async-runtimes));
- `EntityRef`; the `Triple::attribute()`, `relation()`, `action()`, and `entity()` builders; and `Message::user()`, `assistant()`, `system()`, and `tool()`;
- `$memory->sessions->attach()`, for a session created earlier;
- `Page::next()` and `autoPaginate()` (`Page::collect()` is the form of `collectPages`);
- `DocumentFile`;
- the enums `MemoryQueryMode`, `ResultFamily`, `TraceKind`, `GraphEdgeKind`, and `ChatEvent`;
- API fields `@surrealdb/memory` does not send yet: `observedAt` and `addressees` on `remember`, `includeDuplicates` on `recall`, `dryRun` on `forget`, and `observedAt` and `observedAtHeader` on uploads;
- the `Contracts` interfaces and the PHPStan response types.

### Agent Memory on async runtimes

Pass `runtime: AgentMemoryRuntime::swoole()` (OpenSwoole coroutines) or `runtime: AgentMemoryRuntime::amp()` (Amp/Revolt fibers, for example FrankenPHP worker mode) to `AgentMemoryOptions`, so requests and retry back-off cooperate with the event loop.

## Upgrading from 1.x

| 1.x | 2.x |
| --- | --- |
| `new \Surreal\Surreal()` | `new \SurrealDB\Surreal()` |
| `connect($url, ['namespace' => ..., 'database' => ...])` | `connect($url, new ConnectOptions(namespace: ..., database: ...))` |
| `signin(['user' => ..., 'pass' => ...])` | `signin(new RootAuth(...))` (or `NamespaceAuth`, `DatabaseAuth`, `RecordAccessAuth`) |
| `create('person', $data)` | `create(new Table('person'))->content($data)->execute()` |
| `select($record)` | `select($record)->execute()` |
| `merge($record, $data)` | `update($record)->merge($data)->execute()` |
| `query($surql, $vars)` | `run($surql, $vars)` |
| `RecordId::create('person', 'john')` | `new RecordId('person', 'john')` |

## Contributing

### Requirements
- PHP 8.4 or higher
- Composer
- A SurrealDB server for the integration tests (CI runs v2.6.2, v3.3.4, v4.0.0 and nightly)

### Run static analysis and the code style check
```bash
composer analyse
composer cs
```

### Run tests

The integration tests connect to `SURREALDB_HTTP_URL` and `SURREALDB_WS_URL` (default `127.0.0.1:8000`, user `root`/`root`) and skip without a server unless `SURREALDB_REQUIRE_LOCAL=1`.

Start a server and leave it running:

```bash
surreal start --user root --pass root --allow-all memory
```

Then, in another terminal:

```bash
composer test
composer test:frankenphp   # Amp/Revolt runtime
composer test:openswoole   # needs the openswoole extension
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
- `tests/Unit` - Unit tests
- `tests/Feature` - Integration tests against a real server
- `tests/Integration` - Agent Memory tests against a real Agent Memory server
- `tests/Runtime` - Async runtime tests (FrankenPHP/Amp, OpenSwoole)
