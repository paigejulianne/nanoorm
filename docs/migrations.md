# Migrations

NanoORM includes a migration system for managing database schema changes.

## Overview

Migrations allow you to:
- Define database schema in PHP code
- Version control your database structure
- Share schema changes across team members
- Roll back changes when needed

## Creating Migrations

### Migration Structure

Create migration files in a migrations directory:

```
migrations/
├── 001_create_users_table.php
├── 002_create_posts_table.php
├── 003_add_status_to_users.php
└── 004_create_comments_table.php
```

### Migration File

Each migration returns an array with `up` and `down` closures:

```php
<?php
// migrations/001_create_users_table.php

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => function () {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    },

    'down' => function () {
        Schema::dropIfExists('users');
    },
];
```

## Running Migrations

### Using the Migrator

```php
use NanoORM\Model;
use NanoORM\Schema;
use NanoORM\Migrator;

// Configure connection
$pdo = Model::getConnection();
Schema::connection($pdo);

// Create migrator
$migrator = new Migrator($pdo, __DIR__ . '/migrations');

// Run all pending migrations
$ran = $migrator->migrate();

foreach ($ran as $migration) {
    echo "Migrated: $migration\n";
}
```

### Rolling Back

```php
// Roll back last batch
$rolledBack = $migrator->rollback();

// Roll back specific number of migrations
$rolledBack = $migrator->rollback(3);

// Reset all migrations
$migrator->reset();
```

### Fresh Migration

Drop all tables and re-run migrations:

```php
$migrator->fresh();
```

## Schema Builder

### Creating Tables

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});
```

### Modifying Tables

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('phone')->nullable();
    $table->index('phone');
});
```

### Dropping Tables

```php
Schema::drop('users');
Schema::dropIfExists('users');
```

### Renaming Tables

```php
Schema::rename('users', 'members');
```

### Check Table Existence

```php
if (Schema::hasTable('users')) {
    // ...
}
```

## Column Types

### Numeric Types

```php
$table->id();                    // Auto-incrementing primary key
$table->bigInteger('votes');     // BIGINT
$table->integer('count');        // INTEGER
$table->smallInteger('rank');    // SMALLINT
$table->tinyInteger('level');    // TINYINT
$table->decimal('price', 8, 2);  // DECIMAL(8,2)
$table->float('rating');         // FLOAT
$table->double('amount');        // DOUBLE
```

### String Types

```php
$table->string('name', 100);     // VARCHAR(100)
$table->char('code', 2);         // CHAR(2)
$table->text('description');     // TEXT
$table->mediumText('content');   // MEDIUMTEXT
$table->longText('body');        // LONGTEXT
```

### Date/Time Types

```php
$table->date('birth_date');      // DATE
$table->dateTime('published_at'); // DATETIME
$table->time('start_time');      // TIME
$table->timestamp('created_at'); // TIMESTAMP
$table->timestamps();            // created_at + updated_at
$table->softDeletes();           // deleted_at
```

### Other Types

```php
$table->boolean('active');       // BOOLEAN
$table->json('settings');        // JSON
$table->binary('data');          // BLOB
$table->uuid('uuid');            // CHAR(36) or UUID type
$table->enum('status', ['pending', 'active', 'closed']);
```

## Column Modifiers

### Nullability

```php
$table->string('nickname')->nullable();
```

### Default Values

```php
$table->boolean('active')->default(true);
$table->integer('sort_order')->default(0);
$table->string('role')->default('user');
```

### Unsigned

```php
$table->integer('votes')->unsigned();
```

### Auto Increment

```php
$table->integer('id')->autoIncrement();
```

### After (MySQL)

```php
$table->string('phone')->after('email');
```

## Indexes

### Primary Key

```php
$table->primary('id');
$table->primary(['user_id', 'role_id']);  // Composite
```

### Unique Index

```php
$table->string('email')->unique();
// or
$table->unique('email');
$table->unique(['email', 'tenant_id']);  // Composite
```

### Regular Index

```php
$table->index('created_at');
$table->index(['user_id', 'created_at']);  // Composite
```

### Dropping Indexes

```php
Schema::table('users', function (Blueprint $table) {
    $table->dropIndex('users_email_index');
    $table->dropUnique('users_email_unique');
    $table->dropPrimary('users_pkey');
});
```

## Foreign Keys

### Creating Foreign Keys

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->integer('user_id')->unsigned();
    $table->string('title');
    $table->timestamps();

    $table->foreign('user_id')
          ->references('id')
          ->on('users')
          ->onDelete('CASCADE');
});
```

### Shorthand Syntax

```php
$table->foreignId('user_id')->constrained();
// Creates: user_id INT UNSIGNED, FOREIGN KEY (user_id) REFERENCES users(id)

$table->foreignId('author_id')->constrained('users');
// References users table instead of authors
```

### Foreign Key Actions

```php
$table->foreign('user_id')
      ->references('id')
      ->on('users')
      ->onDelete('CASCADE')
      ->onUpdate('CASCADE');

// Available actions: CASCADE, SET NULL, RESTRICT, NO ACTION
```

### Dropping Foreign Keys

```php
$table->dropForeign('posts_user_id_foreign');
$table->dropForeign(['user_id']);  // By column name
```

## Complete Migration Examples

### Users Table

```php
<?php
use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => function () {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'role']);
        });
    },

    'down' => function () {
        Schema::dropIfExists('users');
    },
];
```

### Posts Table with Foreign Key

```php
<?php
use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => function () {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unsigned();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('view_count')->unsigned()->default(0);
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('CASCADE');

            $table->index(['is_published', 'published_at']);
        });
    },

    'down' => function () {
        Schema::dropIfExists('posts');
    },
];
```

### Pivot Table

```php
<?php
use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => function () {
        Schema::create('post_tag', function (Blueprint $table) {
            $table->integer('post_id')->unsigned();
            $table->integer('tag_id')->unsigned();
            $table->timestamp('created_at')->nullable();

            $table->primary(['post_id', 'tag_id']);

            $table->foreign('post_id')
                  ->references('id')
                  ->on('posts')
                  ->onDelete('CASCADE');

            $table->foreign('tag_id')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('CASCADE');
        });
    },

    'down' => function () {
        Schema::dropIfExists('post_tag');
    },
];
```

### Adding Columns

```php
<?php
use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => function () {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('avatar')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    },

    'down' => function () {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar', 'last_login_at']);
        });
    },
];
```

## Migration Best Practices

### 1. One Change Per Migration

```php
// Good: Single responsibility
// 003_add_phone_to_users.php
// 004_add_avatar_to_users.php

// Bad: Multiple unrelated changes
// 003_add_columns_to_users.php
```

### 2. Always Include Down Migration

```php
return [
    'up' => function () {
        Schema::create('posts', ...);
    },

    'down' => function () {
        Schema::dropIfExists('posts');  // Always reversible
    },
];
```

### 3. Use Descriptive Names

```
001_create_users_table.php
002_create_posts_table.php
003_add_phone_to_users.php
004_create_post_tag_pivot_table.php
005_add_index_to_posts_published_at.php
```

### 4. Order Dependencies Correctly

Create parent tables before tables with foreign keys:
1. `001_create_users_table.php`
2. `002_create_posts_table.php` (references users)
3. `003_create_comments_table.php` (references users and posts)

### 5. Use Transactions (When Supported)

```php
return [
    'up' => function () {
        Model::transaction(function () {
            Schema::create('table1', ...);
            Schema::create('table2', ...);
        });
    },
    // ...
];
```

## CLI Integration Example

Create a simple CLI script for migrations:

```php
#!/usr/bin/env php
<?php
// bin/migrate

require_once __DIR__ . '/../vendor/autoload.php';

use NanoORM\Model;
use NanoORM\Schema;
use NanoORM\Migrator;

Model::addConnection('default', 'sqlite:database.db');
$pdo = Model::getConnection();
Schema::connection($pdo);

$migrator = new Migrator($pdo, __DIR__ . '/../migrations');

$command = $argv[1] ?? 'migrate';

match ($command) {
    'migrate' => array_map(fn($m) => echo "Migrated: $m\n", $migrator->migrate()),
    'rollback' => array_map(fn($m) => echo "Rolled back: $m\n", $migrator->rollback()),
    'reset' => $migrator->reset(),
    'fresh' => $migrator->fresh(),
    default => echo "Unknown command: $command\n",
};
```
