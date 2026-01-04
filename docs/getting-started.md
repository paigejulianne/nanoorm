# Getting Started

This guide will help you get NanoORM up and running in your PHP project.

## Requirements

- PHP 8.1 or higher
- PDO extension
- Database-specific PDO driver:
  - `pdo_mysql` for MySQL/MariaDB
  - `pdo_pgsql` for PostgreSQL
  - `pdo_sqlite` for SQLite
  - `pdo_sqlsrv` for SQL Server

## Installation

NanoORM is a single-file ORM. Simply download `NanoORM.php` and include it in your project:

```php
<?php
require_once 'path/to/NanoORM.php';

use NanoORM\Model;
```

### Composer Installation

```bash
composer require paigejulianne/nanoorm
```

Then autoload it:

```php
<?php
require_once 'vendor/autoload.php';

use NanoORM\Model;
```

## Database Configuration

### Basic Configuration

Configure your database connection using the `addConnection` method:

```php
use NanoORM\Model;

// SQLite
Model::addConnection('default', 'sqlite:/path/to/database.db');

// MySQL
Model::addConnection('default', 'mysql:host=localhost;dbname=myapp', 'username', 'password');

// PostgreSQL
Model::addConnection('default', 'pgsql:host=localhost;dbname=myapp', 'username', 'password');

// SQL Server
Model::addConnection('default', 'sqlsrv:Server=localhost;Database=myapp', 'username', 'password');
```

### PDO Options

Pass additional PDO options as the fourth parameter:

```php
Model::addConnection('default', 'mysql:host=localhost;dbname=myapp', 'user', 'pass', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
]);
```

### Using a Connections File

For cleaner configuration, create a `.connections` file in your project root:

```php
<?php
// .connections
return [
    'default' => [
        'dsn' => 'mysql:host=localhost;dbname=myapp',
        'username' => 'root',
        'password' => 'secret',
    ],
    'testing' => [
        'dsn' => 'sqlite::memory:',
    ],
];
```

Then tell NanoORM where to find it:

```php
Model::setConnectionsFile(__DIR__ . '/.connections');
```

### Multiple Connections

You can configure multiple database connections:

```php
Model::addConnection('default', 'mysql:host=localhost;dbname=main');
Model::addConnection('analytics', 'mysql:host=analytics.local;dbname=stats');
```

Then specify which connection a model should use:

```php
class AnalyticsEvent extends Model
{
    public const string CONNECTION = 'analytics';
}
```

## Defining Your First Model

Create a model by extending the `Model` class:

```php
use NanoORM\Model;

class User extends Model
{
    // Table name (optional - defaults to pluralized class name)
    public const ?string TABLE = 'users';

    // Enable automatic timestamps (created_at, updated_at)
    public const bool TIMESTAMPS = true;

    // Enable soft deletes (deleted_at)
    public const bool SOFT_DELETES = true;
}
```

## Basic CRUD Operations

### Creating Records

```php
// Method 1: Create and save in one step
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// Method 2: Create instance, then save
$user = new User([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
]);
$user->save();
```

### Reading Records

```php
// Find by ID
$user = User::find(1);

// Find or throw exception
$user = User::findOrFail(1);

// Find multiple by IDs
$users = User::findMany([1, 2, 3]);

// Get all records
$users = User::all();

// Query with conditions
$activeUsers = User::where('active', true)->get();

// Get first matching record
$admin = User::where('role', 'admin')->first();
```

### Updating Records

```php
// Method 1: Update instance
$user = User::find(1);
$user->name = 'Updated Name';
$user->save();

// Method 2: Bulk update
User::where('active', false)->update(['status' => 'inactive']);
```

### Deleting Records

```php
// Soft delete (if enabled)
$user = User::find(1);
$user->delete();

// Force delete (permanent)
$user->forceDelete();

// Restore soft-deleted record
$user->restore();
```

## Query Builder

NanoORM provides a fluent query builder:

```php
$users = User::query()
    ->where('active', true)
    ->where('age', '>=', 18)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();
```

See [Querying](querying.md) for the full query builder reference.

## Relationships

Define relationships in your models:

```php
class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

Access relationships as properties:

```php
$user = User::find(1);
$posts = $user->posts;      // Collection of posts
$profile = $user->profile;  // Profile model or null
```

See [Relationships](relationships.md) for the full relationships guide.

## Eager Loading

Prevent N+1 queries with eager loading:

```php
// Load relationship with query
$users = User::with('posts')->get();

// Load multiple relationships
$users = User::with('posts', 'profile')->get();

// Nested eager loading
$users = User::with('posts.comments')->get();

// Load on existing model
$user = User::find(1);
$user->load('posts');
```

## Transactions

Wrap operations in a transaction:

```php
Model::transaction(function () {
    $user = User::create(['name' => 'John']);
    Profile::create(['user_id' => $user->getKey(), 'bio' => 'Hello!']);
});
```

If any exception is thrown, the transaction is rolled back.

## Next Steps

- [Models](models.md) - Learn about model configuration and features
- [Querying](querying.md) - Master the query builder
- [Relationships](relationships.md) - Define and use model relationships
- [Collections](collections.md) - Work with query results
- [Migrations](migrations.md) - Manage your database schema
