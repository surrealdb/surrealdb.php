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

## Contributing

### Requirements
- PHP 8.1 or higher
- Composer
- SurrealDB 1.4.0 or higher

### Run tests
```bash
./vendor/bin/phpunit -c phpunit.xml
```

### Directory Structure

- `src` - The source code of the library
- `tests` - The unit tests of the library
