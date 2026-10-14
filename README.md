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

Options are readonly classes built with named arguments (`new RecallOptions(k: 5)`), and enum-typed options also accept the raw wire string.

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

The fact families are also collections of their own (`$memory->facts->attributes()`, `->relations()`, `->actions()`), next to `$memory->entities` (search, rankings, history, and graph neighbourhood) and `$memory->uncertainty` (open questions and their settlement).

### Pagination

Collections return a `Page`. Iterating a page walks its rows, `next()` fetches the following page, and `autoPaginate()` lazily walks every page, keeping the original filters:

```php
use SurrealDB\AgentMemory\Options\EntityListOptions;

$people = $memory->entities->list(new EntityListOptions(type: "person", count: true));
echo $people->totalSize;

foreach ($people->autoPaginate() as $person) {
    echo $person["name"], PHP_EOL;
}
```

### Errors and retries

Failed requests throw a subclass of `SurrealDB\AgentMemory\Exceptions\AgentMemoryException`, chosen by status:

| Status | Exception |
| --- | --- |
| 400, 422 | `ValidationException` |
| 401 | `AuthException` |
| 403 | `ScopeException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 415 | `UnsupportedMediaTypeException` |
| 429 | `RateLimitException` |
| 5xx | `ServerException` |
| network failure | `ConnectionException` |

Retries follow what each status guarantees:

- A 503 means the server shed load or could not reach a dependency, and did no work, so every request is retried on it.
- `GET` reads and the idempotent writes (`remember`, `rememberMany`) are also retried on connection failures and on 408, 429, 500, 502, and 504. A 409 is retried when it says the first attempt of the same write is still in progress.
- Other requests (such as `recall` and `chat`) are not retried on those, because the first attempt may already have done its work.

The back-off honours the server's `Retry-After` (also exposed as `$e->retryAfter`). A chat stream raises a `StreamException` when the server sends an `error` event, or when the stream ends before its final frame.

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

Without model keys the server runs on placeholders: everything except recall, context, forget, chat, and document ingestion works, and the tests that need those skip. Set `GEMINI_API_KEY` before `start` to run them too. Set `AGENT_MEMORY_OPENAPI_SPEC` to spectron's `doc/spec/enduser.swagger.json` to also check the client against the server's API description. The [`Agent Memory` workflow](.github/workflows/agent-memory.yml) runs all of this in CI.

### Directory Structure

- `src` - The source code of the library
- `tests` - The unit tests of the library
