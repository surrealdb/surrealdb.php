# surrealdb.php

The official SurrealDB library for PHP.

*TODO*: Add build and badge here

## Client Setup

The client uses a singleton approach with a static config so, once configured, it can be used anywhere in your application.

This allows it to work with any framework/application.

```php

use Surreal\Client;
use Surreal\Config;

Client::configure(
    (new Config())
        ->username('root')
        ->password('secret')
        ->url('http://127.0.0.1:4269')
        ->namespace('tests')
        ->db('tests')
);
```

## Usage

### Simple raw queries:

```php
use Surreal\Client;

$result = Client::query('SELECT * FROM users');

// Get all results
// array of user objects
$result->all();

// Get first result
// user object
$result->first();

```

### Using "models"/"dto" objects:

```php

use Surreal\Client;
use Surreal\Model\SurrealModel;

// This docblock helps with auto-completion in your ide
/** @extends SurrealModel<UserModel> */
class UserModel extends SurrealModel
{
	public ?string $id   = null;
	public ?string $name = null;
}

// Using the query builder:
UserModel::query()->all();
UserModel::query()->first();

// Using the client:
Client::queryModel(UserModel::class, 'SELECT * FROM users')->all();
```

### Using the query builder:

The query builder is still very simple and doesn't handle everything... but it's a starting point.

```php
UserModel::query()
    ->where('name', 'John')
    ->where('age', '>', 18)
    ->get();

UserModel::query()
    ->where('name', 'John')
    ->where('age', '>', 18)
    ->first(); 
```
