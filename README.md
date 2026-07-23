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

## Getting started

To get started, you need to create a new instance of the SurrealDB HTTP or WebSocket Class.

```php
// Make a new instance of the SurrealDB class. Use the ws or wss protocol for having WebSocket functionality.
$db = new \Surreal\Surreal();

$db->connect("http://localhost:8000", [
    "namespace" => "test",
    "database" => "test"
]);
```

### Basic Querying

In the PHP SDK, We have a simple API that allows you to interact with SurrealDB. The following example shows how to interact with the database.

> The example below requires SurrealDB to be [installed](https://surrealdb.com/install) and running on port 8000.

```php
// Connect set the specified namespace and database.
$db = new \Surreal\Surreal();

$db->connect("http://localhost:8000", [
    "namespace" => "test",
    "database" => "test"
]);

// We want to authenticate as a root user.
$token = $db->signin([
    "user" => "root",
    "pass" => "root"
]);

// Create a new person in the database with a custom id.
$person = $db->create("person", [
    "title" => "Founder & CEO",
    "name" => [
        "first" => "Tobie",
        "last" => "Morgan Hitchcock" 
    ],
    "marketing" => true
]); 

// Get the person with the name "John Doe".
$record = \Surreal\Cbor\Types\Record\RecordId::create("person", "john");
$person = $db->select($record);

// Update a person record with a specific id
$record = \Surreal\Cbor\Types\Record\RecordId::create("person", "john");
$person = $db->merge($record, ["age" => 31]);

// Select all people records.
$people = $db->select("person");  

// Perform a custom advanced query.
$groups = $db->query('SELECT marketing, count() FROM $tb GROUP BY marketing', [
    "tb" => \Surreal\Cbor\Types\Table::create("person")
]);

// Close the connection between the application and the database.
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

### Directory Structure

- `src` - The source code of the library
- `tests` - The unit tests of the library
