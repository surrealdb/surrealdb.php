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

> **v2 is a rewrite.** The API below is not compatible with 1.x: the entry point is `SurrealDB\SDK\Surreal`, statements are built with fluent query builders, and authentication and options are typed objects. See [Upgrading from 1.x](#upgrading-from-1x).

## Documentation

View the SDK documentation [here](https://surrealdb.com/docs/integration/libraries/php).

## Installation

v2 is in alpha, so ask Composer for it explicitly (a plain `composer require` installs 1.x):

```sh
composer require surrealdb/surrealdb.php:^2.0@alpha
```

Requires PHP 8.4 or later. The WebSocket engine needs nothing extra for queries. The HTTP engine, and `health()`, `import()` and `export()` on any connection, need a PSR-18 client and PSR-17 factories, found through `php-http/discovery`:

```sh
composer require guzzlehttp/guzzle php-http/discovery
```

Without discovery, pass the implementations yourself through `DriverOptions` (`httpClient`, `requestFactory`, `streamFactory`).

The SDK supports SurrealDB 2.x and 3.x, and accepts 4.x servers. Sessions, transactions, `api()` and refresh tokens need SurrealDB 3.0, and `gql()` / `graphql()` need 3.2.

## Getting started

```php
use SurrealDB\SDK\Auth\RootAuth;
use SurrealDB\SDK\Connection\ConnectOptions;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Types\RecordId;
use SurrealDB\SDK\Types\Table;

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
use SurrealDB\SDK\Auth\DatabaseAuth;
use SurrealDB\SDK\Auth\NamespaceAuth;
use SurrealDB\SDK\Auth\RecordAccessAuth;

$tokens = $db->signin(new RecordAccessAuth('test', 'test', 'user', ['email' => $email, 'pass' => $pass]));
$db->signup(new RecordAccessAuth('test', 'test', 'user', ['email' => $email, 'pass' => $pass]));
$db->signin(new NamespaceAuth('test', 'admin', 'secret'));
$db->signin(new DatabaseAuth('test', 'test', 'admin', 'secret'));

$db->authenticate($tokens->access);
$me = $db->auth()->execute();   // the signed-in record
$db->invalidate();
```

### Query builders

Every builder runs with `execute()`; `compile()` returns the SurrealQL and bindings without running it, and `json()` returns plain JSON-compatible values instead of SurrealDB types.

```php
use SurrealDB\SDK\Enum\Output;

$db->select(new Table('person'))->fields('name', 'age')->where('age > 18')->start(20)->limit(10)->fetch('friends')->execute();
$db->create(new Table('person'))->content(['name' => 'Jaime'])->output(Output::AFTER)->execute();
$db->update(new Table('person'))->merge(['active' => true])->where('last_seen > time::now() - 1w')->execute();
$db->upsert(new RecordId('person', 'tobie'))->replace(['name' => 'Tobie'])->execute();
$db->delete(new RecordId('person', 'tobie'))->execute();
$db->insert(new Table('person'), [['name' => 'A'], ['name' => 'B']])->ignore()->execute();
$db->relate(new RecordId('person', 'tobie'), new Table('knows'), new RecordId('person', 'jaime'), ['since' => 2020])->execute();
$db->call('fn::greet', args: ['Tobie'])->execute();
```

Multi-statement SurrealQL can be streamed, or inspected without throwing on failed statements:

```php
use SurrealDB\SDK\Query\Frame\DoneFrame;
use SurrealDB\SDK\Query\Frame\ErrorFrame;
use SurrealDB\SDK\Query\Frame\ValueFrame;

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

SurrealDB values map to classes in `SurrealDB\SDK\Types`: `RecordId`, `RecordIdRange`, `StringRecordId`, `Table`, `Uuid`, `DateTime`, `Duration`, `Decimal`, `Range`, `Set`, `File`, `Bytes`, `Future`, `None` and the `Geometry*` types. They are sent and received as CBOR (the default) and keep their type in both directions, except `Bytes`: it is sent as a byte string, which comes back as a plain PHP `string`. `Set`, `Range` and `RecordIdRange` need SurrealDB 3.0 or later, and `File` also needs a server with the experimental files capability enabled.

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

A fork does not copy the refresh token, which the server lets only one session use. Once the copied access token expires, the fork renews through `ConnectOptions::$authentication` if the source was signed in by it; otherwise sign the fork in again.

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

```php
use SurrealDB\SDK\Live\LiveAction;

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

## Connections

`ConnectOptions::$reconnect` (on by default) reconnects with exponential backoff and restores every session's namespace, database, variables and authentication. Under the default synchronous scheduler the SDK notices a dropped connection when it next uses it. A call that finds the socket dead fails, and the next one runs on the new connection; `status()` checks the socket and reconnects on the spot, so it reports `Connected` once that has worked.

```php
$db->connect('wss://example.com/rpc', new ConnectOptions(
    reconnect: ['attempts' => 10, 'retryDelay' => 0.5, 'retryDelayMax' => 30],
));

$db->subscribe('reconnecting', fn () => $logger->warning('Reconnecting to SurrealDB'));
$db->status();          // ConnectionStatus::Connecting, Connected, Reconnecting or Disconnected
$db->version();
```

Connection events are also dispatched as PSR-14 events (`SurrealDB\SDK\Events\*`) through `DriverOptions::$events`, and the SDK logs through any PSR-3 logger in `DriverOptions::$logger`.

### Async runtimes

The default runtime is blocking. For long-running workers, use a runtime preset. Each needs its own packages:

```sh
composer require openswoole/core                     # Runtime::swoole(), plus the openswoole extension
composer require revolt/event-loop amphp/websocket-client amphp/http-client   # Runtime::amp()
```

```php
use SurrealDB\SDK\Runtime\Runtime;

$db = new Surreal(Runtime::swoole());   // inside a coroutine, e.g. Co\run() or an OpenSwoole server
$db = new Surreal(Runtime::amp());      // inside the Revolt event loop, e.g. FrankenPHP worker mode
```

These run a background read loop, keepalive pings and token renewal timers.

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
$db->use('test', 'copy');
$db->import($surql);
```

## Observability

The SDK emits OpenTelemetry traces and metrics for every RPC. Enable it per runtime through the `Runtime` presets, which pick the export strategy that fits each environment:

- **PHP-FPM / CLI** (`Runtime::sync`): spans are buffered in a batch processor and flushed once the request finishes (after `fastcgi_finish_request()` under FPM), so export never adds to user-visible latency.
- **OpenSwoole / FrankenPHP (Amp)** (`Runtime::swoole` / `Runtime::amp`): each span is exported directly with no batching, over a non-blocking transport (Swoole runtime hooks, or the Amp PSR-18 client).

```php
use SurrealDB\SDK\Runtime\Runtime;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Telemetry\OpenTelemetry\ObservabilityOptions;

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
use SurrealDB\SDK\Connection\DriverOptions;
use SurrealDB\SDK\Surreal;
use SurrealDB\SDK\Telemetry\OpenTelemetry\ObservabilityOptions;
use SurrealDB\SDK\Telemetry\OpenTelemetry\OtelObservability;

$telemetry = OtelObservability::batched(new ObservabilityOptions(serviceName: "my-app"));

$db = new Surreal(new DriverOptions(
    tracer: $telemetry->tracer(),
    meter: $telemetry->meter(),
));

// Laravel: flush after the response has been sent to the client.
app()->terminating(static fn () => $telemetry->forceFlush());
```

## Upgrading from 1.x

| 1.x | 2.x |
| --- | --- |
| `new \Surreal\Surreal()` | `new \SurrealDB\SDK\Surreal()` |
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
- A SurrealDB server for the integration tests (CI runs v2.6.2, v3.3.4 and nightly)

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

### Directory Structure

- `src` - The source code of the library
- `tests/Unit` - Unit tests
- `tests/Feature` - Integration tests against a real server
- `tests/Runtime` - Async runtime tests (FrankenPHP/Amp, OpenSwoole)
