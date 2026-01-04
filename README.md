# NanoORM

A lightweight, full-featured PHP ORM with fluent query builder, relationships, migrations, and zero dependencies.

## Features

- **Single file** (~1,800 lines) - easy to audit and include
- **Zero dependencies** - only PHP 8.1+ and PDO required
- **Fluent query builder** - chainable, expressive queries
- **Relationships** - HasOne, HasMany, BelongsTo, BelongsToMany with eager loading
- **Soft deletes** - built-in support with `withTrashed()`, `onlyTrashed()`
- **Timestamps** - automatic `created_at`/`updated_at` management
- **Attribute casting** - JSON, datetime, boolean, and more
- **Identity map** - prevents duplicate model instances
- **Migrations** - simple migration system with schema builder
- **Query logging** - built-in debugging tools
- **Multi-database** - MySQL, PostgreSQL, SQLite, SQL Server

## Installation

```bash
composer require paigejulianne/nanoorm
```

Or simply include `NanoORM.php` directly.

## Quick Start

### Configuration

Create a `.connections` file in your project root:

```ini
[default]
DSN=mysql:host=localhost;dbname=myapp;charset=utf8mb4
USER=root
PASS=secret

[testing]
DSN=sqlite::memory:
```

Or configure programmatically:

```php
use NanoORM\Model;

Model::addConnection('default', 'mysql:host=localhost;dbname=myapp', 'user', 'pass');
```

### Define Models

```php
use NanoORM\Model;

class User extends Model
{
    // Optional: customize table name (default: users)
    protected const ?string TABLE = 'users';

    // Enable features
    protected const bool TIMESTAMPS = true;
    protected const bool SOFT_DELETES = true;

    // Attribute casting
    protected const array CASTS = [
        'is_admin' => 'boolean',
        'settings' => 'json',
    ];

    // Hide from JSON/array output
    protected const array HIDDEN = ['password'];

    // Relationships
    public function posts(): \NanoORM\HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): \NanoORM\HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function roles(): \NanoORM\BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

class Post extends Model
{
    protected const bool TIMESTAMPS = true;

    public function author(): \NanoORM\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): \NanoORM\HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
```

### Basic CRUD

```php
// Create
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// Or create manually
$user = new User(['name' => 'Jane']);
$user->email = 'jane@example.com';
$user->save();

// Read
$user = User::find(1);
$user = User::findOrFail(1);
$users = User::all();

// Update
$user->name = 'John Smith';
$user->save();

// Delete
$user->delete();          // Soft delete if enabled
$user->forceDelete();     // Permanent delete
$user->restore();         // Restore soft-deleted
```

### Query Builder

```php
// Fluent queries
$users = User::where('active', true)
    ->where('role', 'admin')
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// Multiple conditions
$users = User::where([
    'active' => true,
    'verified' => true,
])->get();

// OR conditions
$users = User::where('role', 'admin')
    ->orWhere('role', 'moderator')
    ->get();

// Nested conditions
$users = User::where('active', true)
    ->where(function ($query) {
        $query->where('role', 'admin')
              ->orWhere('is_super', true);
    })
    ->get();

// Various WHERE clauses
User::whereIn('id', [1, 2, 3])->get();
User::whereNotIn('status', ['banned', 'suspended'])->get();
User::whereNull('deleted_at')->get();
User::whereNotNull('verified_at')->get();
User::whereBetween('age', 18, 65)->get();
User::whereRaw('YEAR(created_at) = ?', [2024])->get();

// Ordering
User::orderBy('name')->get();
User::orderBy('created_at', 'DESC')->get();
User::latest()->get();           // ORDER BY created_at DESC
User::oldest()->get();           // ORDER BY created_at ASC

// Pagination
$result = User::where('active', true)->paginate(15, $page);
// Returns: ['data' => [...], 'total' => 100, 'per_page' => 15, ...]

// Chunking (memory efficient)
User::where('active', true)->chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});

// Aggregates
$count = User::where('active', true)->count();
$total = Order::where('status', 'completed')->sum('amount');
$avg = Product::avg('price');
$max = Order::max('total');
$min = Product::min('stock');

// Pluck single column
$emails = User::where('active', true)->pluck('email');
$names = User::pluck('name', 'id');  // ['id' => 'name', ...]

// Check existence
if (User::where('email', $email)->exists()) {
    // Email taken
}
```

### Relationships

```php
// Lazy loading (loads when accessed)
$user = User::find(1);
$posts = $user->posts;           // Triggers query
$profile = $user->profile;

// Eager loading (prevents N+1)
$users = User::with('posts', 'profile')->get();

// Nested eager loading
$users = User::with('posts.comments')->get();

// Relationship queries
$recentPosts = $user->posts()
    ->where('published', true)
    ->orderBy('created_at', 'DESC')
    ->limit(5)
    ->get();

// Many-to-many operations
$user->roles()->attach(1);                    // Add role
$user->roles()->attach([1, 2, 3]);            // Add multiple
$user->roles()->detach(1);                    // Remove role
$user->roles()->detach();                     // Remove all
$user->roles()->sync([1, 2, 3]);              // Replace all
$user->roles()->toggle([1, 2]);               // Toggle roles

// With pivot attributes
$user->roles()->attach(1, ['assigned_at' => now()]);
```

### Soft Deletes

```php
class Post extends Model
{
    protected const bool SOFT_DELETES = true;
}

$post->delete();                              // Sets deleted_at

// Querying
Post::all();                                  // Excludes deleted
Post::withTrashed()->get();                   // Includes deleted
Post::onlyTrashed()->get();                   // Only deleted

// Restoring
$post->restore();

// Permanent delete
$post->forceDelete();

// Check if deleted
if ($post->trashed()) {
    // ...
}
```

### Timestamps

```php
class Post extends Model
{
    protected const bool TIMESTAMPS = true;

    // Customize column names (optional)
    protected const string CREATED_AT = 'created_at';
    protected const string UPDATED_AT = 'updated_at';
}

// Timestamps are automatically managed
$post = Post::create(['title' => 'Hello']);
echo $post->created_at;  // Set automatically

$post->title = 'Updated';
$post->save();
echo $post->updated_at;  // Updated automatically
```

### Attribute Casting

```php
class User extends Model
{
    protected const array CASTS = [
        'is_admin' => 'boolean',
        'settings' => 'json',
        'birthday' => 'datetime',
        'score' => 'float',
        'views' => 'integer',
    ];
}

$user = User::find(1);

// Casts automatically applied
$user->settings = ['theme' => 'dark'];  // Stored as JSON
$settings = $user->settings;             // Retrieved as array

$user->is_admin = true;                  // Stored as 1/0
if ($user->is_admin) { }                 // Retrieved as boolean
```

### Atomic Operations

```php
$post->increment('views');           // views + 1
$post->increment('views', 5);        // views + 5
$post->decrement('stock');           // stock - 1
$post->decrement('stock', 2);        // stock - 2

// With additional updates
$post->increment('views', 1, ['last_viewed_at' => date('Y-m-d H:i:s')]);
```

### Transactions

```php
use NanoORM\Model;

// Manual transactions
Model::beginTransaction();
try {
    $user = User::create(['name' => 'John']);
    $profile = Profile::create(['user_id' => $user->getKey()]);
    Model::commit();
} catch (Exception $e) {
    Model::rollback();
    throw $e;
}

// Callback-based (auto rollback on exception)
Model::transaction(function () {
    $user = User::create(['name' => 'John']);
    Profile::create(['user_id' => $user->getKey()]);
});
```

### Bulk Operations

```php
// Bulk insert (single query)
User::insert([
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
]);

// Bulk insert with IDs returned
$ids = User::insertGetIds([
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
]);

// Bulk update
User::where('active', false)->update(['status' => 'inactive']);

// Bulk delete
User::where('last_login', '<', '2020-01-01')->delete();
```

### Find or Create

```php
// Find first matching or create new
$user = User::firstOrCreate(
    ['email' => 'john@example.com'],          // Search by
    ['name' => 'John Doe', 'role' => 'user']  // Additional attributes
);

// Find and update, or create new
$user = User::updateOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe', 'last_login' => now()]
);
```

### Dirty Checking

```php
$user = User::find(1);
$user->name = 'New Name';

$user->isDirty();            // true
$user->isDirty('name');      // true
$user->isDirty('email');     // false
$user->isClean();            // false

$user->getDirty();           // ['name' => 'New Name']
$user->getOriginal('name');  // 'Old Name'
$user->getOriginal();        // All original values

$user->save();
$user->isDirty();            // false
```

### Model Events

```php
class User extends Model
{
    // Method-based hooks
    protected function onCreating(): void
    {
        $this->uuid = bin2hex(random_bytes(16));
    }

    protected function onCreated(): void
    {
        // Send welcome email
    }

    protected function onDeleting(): void
    {
        // Clean up related data
    }
}

// Or register listeners
User::on('created', function ($user) {
    Mail::sendWelcome($user->email);
});

// Available events:
// creating, created
// updating, updated
// saving, saved
// deleting, deleted
// restoring, restored (soft deletes)
// forceDeleting, forceDeleted
```

### Query Logging

```php
Model::enableQueryLog();

$users = User::where('active', true)->get();
$posts = Post::with('author')->get();

$log = Model::getQueryLog();
// [
//     ['sql' => 'SELECT...', 'bindings' => [...], 'time' => 1.23],
//     ...
// ]

Model::flushQueryLog();
Model::disableQueryLog();
```

### Debugging

```php
// Get SQL without executing
$sql = User::where('active', true)->toRawSql();
// SELECT * FROM "users" WHERE "active" = 1

// Dump and die
User::where('active', true)->dd();
```

## Migrations

### Creating Migrations

Create migration files in a `migrations` directory:

```php
// migrations/2024_01_15_000001_create_users_table.php
<?php
use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->boolean('is_admin')->default(false);
        $table->json('settings')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }),

    'down' => Schema::drop('users'),
];
```

### Running Migrations

```php
use NanoORM\Migrator;
use NanoORM\Model;

$pdo = Model::getConnection();
$migrator = new Migrator($pdo, __DIR__ . '/migrations');

// Run pending migrations
$ran = $migrator->migrate();

// Rollback last batch
$rolledBack = $migrator->rollback();

// Reset (rollback all)
$migrator->reset();

// Refresh (reset + migrate)
$migrator->refresh();

// Check status
$pending = $migrator->getPendingMigrations();
$ran = $migrator->getRanMigrations();
```

### Schema Builder

```php
use NanoORM\Schema;
use NanoORM\Blueprint;

// Create table
$sql = Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->foreignId('user_id');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->timestamps();
});

// Drop table
$sql = Schema::drop('posts');

// Column types
$table->id();                        // BIGINT AUTO_INCREMENT PRIMARY KEY
$table->uuid();                      // UUID/CHAR(36)
$table->string('name', 100);         // VARCHAR(100)
$table->text('body');                // TEXT
$table->integer('count');            // INT
$table->bigInteger('views');         // BIGINT
$table->decimal('price', 8, 2);      // DECIMAL(8,2)
$table->float('rating');             // FLOAT
$table->boolean('active');           // TINYINT(1)/BOOLEAN
$table->date('birthday');            // DATE
$table->datetime('published_at');    // DATETIME
$table->timestamp('verified_at');    // TIMESTAMP
$table->json('metadata');            // JSON/TEXT
$table->enum('status', ['draft', 'published']);

// Modifiers
$table->string('name')->nullable();
$table->string('role')->default('user');
$table->integer('votes')->unsigned();
$table->string('email')->unique();
$table->integer('user_id')->index();

// Shortcuts
$table->timestamps();                // created_at + updated_at
$table->softDeletes();               // deleted_at
$table->foreignIdFor(User::class);   // user_id + foreign key

// Foreign keys
$table->foreign('user_id')
    ->references('id')
    ->on('users')
    ->onDelete('CASCADE')
    ->onUpdate('CASCADE');
```

## Multiple Connections

```php
class Analytics extends Model
{
    protected const string CONNECTION = 'analytics';
}

// In .connections file
[analytics]
DSN=mysql:host=analytics.example.com;dbname=analytics
USER=reader
PASS=secret

// Or programmatically
Model::addConnection('analytics', 'mysql:...', 'user', 'pass');

// Query using specific connection
$data = Analytics::where('date', today())->get();
```

## API Reference

### Model Methods

| Method | Description |
|--------|-------------|
| `find($id)` | Find by primary key |
| `findOrFail($id)` | Find or throw exception |
| `findMany([$ids])` | Find multiple by IDs |
| `all()` | Get all records |
| `create([...])` | Create and save |
| `firstOrCreate([...], [...])` | Find or create |
| `updateOrCreate([...], [...])` | Update or create |
| `insert([[...]])` | Bulk insert |
| `save()` | Save model |
| `delete()` | Delete (soft if enabled) |
| `forceDelete()` | Permanent delete |
| `restore()` | Restore soft-deleted |
| `refresh()` | Reload from database |

### Query Builder Methods

| Method | Description |
|--------|-------------|
| `where($col, $op, $val)` | Add WHERE clause |
| `orWhere(...)` | Add OR WHERE |
| `whereIn($col, [...])` | WHERE IN |
| `whereNull($col)` | WHERE IS NULL |
| `whereBetween($col, $min, $max)` | WHERE BETWEEN |
| `orderBy($col, $dir)` | Add ORDER BY |
| `limit($n)` / `take($n)` | Set LIMIT |
| `offset($n)` / `skip($n)` | Set OFFSET |
| `get()` | Execute and get results |
| `first()` | Get first result |
| `find($id)` | Find by ID |
| `count()` | Count results |
| `sum($col)` | Sum of column |
| `avg($col)` | Average |
| `min($col)` / `max($col)` | Min/max |
| `pluck($col)` | Get column values |
| `exists()` | Check if any exist |
| `paginate($perPage)` | Paginate results |
| `chunk($size, $callback)` | Process in chunks |
| `update([...])` | Bulk update |
| `delete()` | Bulk delete |

## License

MIT License. See LICENSE file.
