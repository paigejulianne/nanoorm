# NanoORM Documentation

NanoORM is a lightweight, single-file PHP ORM designed for simplicity and performance. Despite being contained in a single file, it provides enterprise-grade features comparable to larger ORMs.

## Features

- **Single File Architecture** - Easy to include and deploy (~6,600 lines)
- **Active Record Pattern** - Intuitive model-based database interaction
- **Fluent Query Builder** - Chainable, expressive query construction
- **Relationships** - HasOne, HasMany, BelongsTo, BelongsToMany with eager loading
- **Collection Class** - Powerful array manipulation with Laravel-style methods
- **Migrations** - Schema builder with blueprint-based table definitions
- **Multi-Database Support** - MySQL, PostgreSQL, SQLite, SQL Server

## Requirements

- PHP 8.1 or higher
- PDO extension
- Database-specific PDO driver (pdo_mysql, pdo_pgsql, pdo_sqlite, etc.)

## Table of Contents

1. [Getting Started](getting-started.md) - Installation and basic setup
2. [Models](models.md) - Defining models, attributes, and configuration
3. [Querying](querying.md) - Query builder, conditions, and pagination
4. [Relationships](relationships.md) - Model relationships and eager loading
5. [Collections](collections.md) - Working with query results
6. [Advanced Queries](advanced.md) - Joins, subqueries, unions, and locking
7. [Scopes](scopes.md) - Local and global query scopes
8. [Events & Observers](events.md) - Lifecycle hooks and observers
9. [Migrations](migrations.md) - Schema management and migrations

## Quick Example

```php
<?php
require_once 'NanoORM.php';

use NanoORM\Model;

// Configure database
Model::addConnection('default', 'sqlite:database.db');

// Define a model
class User extends Model
{
    public const ?string TABLE = 'users';
    public const bool TIMESTAMPS = true;

    public function posts(): \NanoORM\HasMany
    {
        return $this->hasMany(Post::class);
    }
}

// Create a user
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// Query users
$activeUsers = User::query()
    ->where('active', true)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Eager load relationships
$usersWithPosts = User::with('posts')->get();
```

## License

MIT License
