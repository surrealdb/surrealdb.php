# surrealdb.php

The official SurrealDB library for PHP.

[![](https://img.shields.io/badge/status-v1.0.0-ff00bb.svg?style=flat-square)](https://github.com/surrealdb/surrealdb.js)
[![](https://img.shields.io/badge/docs-view-44cc11.svg?style=flat-square)](https://surrealdb.com/docs/integration/libraries/javascript)
[![](https://img.shields.io/badge/license-Apache_License_2.0-00bfff.svg?style=flat-square)](https://github.com/surrealdb/surrealdb.php)

## Quickstart-Guide

---

### Installation

You install the SurrealDB SDK via Composer. If you don't have Composer installed yet, you can download it [here](https://getcomposer.org/download/).
```bash
composer require surrealdb/surrealdb
```

### Getting started

To get started, you need to create a new instance of the SurrealDB HTTP or Websocket Class.
```php
$db = new Surreal\Core\Client\SurrealHttp(
    host: "http://localhost:8000",
    target: [
        "namespace" => "test",
        "database" => "test"
    ]    
);

// or

$db = new Surreal\Core\Client\SurrealWebsocket(
    host: "ws://localhost:8000",
    target: [
        "namespace" => "test",
        "database" => "test"
    ]
);
```

### Basic Quering
In the PHP SDK, We have a simple API that allows you to interact with SurrealDB. The following example shows how to interact with the database.
```php

// Connect set the specified namespace and database.
$db = new Surreal\Core\Client\SurrealHttp(
    host: "http://localhost:8000",
    target: [
        "namespace" => "test",
        "database" => "test"
    ]    
);

// We can also specify the namespace and database after the connection.
$db->use([
    "namespace" => "test",
    "database" => "test"
]);

// We want to authenticate as a root user.
$token = $db->signin([
    "user" => "root",
    "pass" => "root"
]);

// Assign the token to the database.
$db->setToken($token);

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
$person = $db->select("person:john");

// Update a person record with a specific id
$record = new Surreal\Cbor\Types\RecordId("person", "john");
$person = $db->merge($record, ["age" => 31]);

// Select all people records.
$people = $db->select("person");

// Perform a custom advanced query.
$groups = $db->query('SELECT marketing, count() FROM $tb GROUP BY marketing', [
    "tb" => \Surreal\Cbor\Types\Table::fromString("person")->toString()
]);

```

## More informations

---

The docs of this libary are located at https://surrealdb.com/docs/integration/libraries/php

## Contribution notes

---

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