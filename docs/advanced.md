# Advanced Queries

This guide covers advanced query builder features including joins, subqueries, unions, and performance optimizations.

## Joins

### Inner Join

```php
// Basic join
User::join('posts', 'users.id', '=', 'posts.user_id')
    ->select('users.*', 'posts.title AS post_title')
    ->get();

// SQL: SELECT users.*, posts.title AS post_title
//      FROM users
//      INNER JOIN posts ON users.id = posts.user_id
```

### Left Join

Include all records from the left table:

```php
User::leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
    ->select('users.*', 'profiles.bio')
    ->get();

// Returns all users, with NULL for bio if no profile
```

### Right Join

Include all records from the right table:

```php
User::rightJoin('orders', 'users.id', '=', 'orders.user_id')
    ->get();
```

### Cross Join

Cartesian product of two tables:

```php
Size::crossJoin('colors')->get();

// Every size combined with every color
```

### Table Aliases

```php
User::join('posts AS p', 'users.id', '=', 'p.user_id')
    ->where('p.published', true)
    ->get();
```

### Raw Joins

For complex join conditions:

```php
User::joinRaw(
    'LEFT JOIN posts ON posts.user_id = users.id AND posts.published = ?',
    [true]
)->get();
```

### Multiple Joins

```php
User::join('profiles', 'users.id', '=', 'profiles.user_id')
    ->join('settings', 'users.id', '=', 'settings.user_id')
    ->select('users.*', 'profiles.bio', 'settings.theme')
    ->get();
```

## Subqueries

### Where Exists

Find records where a related record exists:

```php
// Users who have at least one post
User::whereExists(function ($query) {
    $query->from('posts')
          ->whereColumn('posts.user_id', 'users.id');
})->get();

// SQL: SELECT * FROM users WHERE EXISTS (
//        SELECT * FROM posts WHERE posts.user_id = users.id
//      )
```

### Where Not Exists

Find records where no related record exists:

```php
// Users with no posts
User::whereNotExists(function ($query) {
    $query->from('posts')
          ->whereColumn('posts.user_id', 'users.id');
})->get();
```

### Where In Subquery

```php
// Users who have written a published post
User::whereInSubquery('id', function ($query) {
    $query->from('posts')
          ->select('user_id')
          ->where('published', true);
})->get();

// SQL: SELECT * FROM users WHERE id IN (
//        SELECT user_id FROM posts WHERE published = 1
//      )
```

### Where Not In Subquery

```php
// Users who haven't written any published posts
User::whereNotInSubquery('id', function ($query) {
    $query->from('posts')
          ->select('user_id')
          ->where('published', true);
})->get();
```

### Select Subquery

Add a subquery as a column:

```php
User::select('*')
    ->selectSub(function ($query) {
        $query->from('posts')
              ->selectRaw('COUNT(*)')
              ->whereColumn('posts.user_id', 'users.id');
    }, 'posts_count')
    ->get();

// SQL: SELECT *, (SELECT COUNT(*) FROM posts WHERE posts.user_id = users.id) AS posts_count
//      FROM users
```

### Column Comparisons

Compare two columns in a WHERE clause:

```php
// Posts where updated_at differs from created_at
Post::whereColumn('updated_at', '!=', 'created_at')->get();

// Equality shorthand
Post::whereColumn('approved_by', 'created_by')->get();
```

## Union Queries

### Union (Distinct)

Combine results, removing duplicates:

```php
User::where('role', 'admin')
    ->union(function ($query) {
        $query->where('role', 'moderator');
    })
    ->get();

// SQL: SELECT * FROM users WHERE role = 'admin'
//      UNION
//      SELECT * FROM users WHERE role = 'moderator'
```

### Union All

Keep all rows including duplicates:

```php
User::where('active', true)
    ->unionAll(function ($query) {
        $query->where('premium', true);
    })
    ->get();
```

### Multiple Unions

```php
User::where('role', 'admin')
    ->union(fn($q) => $q->where('role', 'moderator'))
    ->union(fn($q) => $q->where('role', 'editor'))
    ->orderBy('name')
    ->get();
```

## Row Locking

For transaction safety with concurrent updates.

### Lock For Update

Exclusive lock - other transactions wait:

```php
Model::transaction(function () {
    $user = User::where('id', 1)
        ->lockForUpdate()
        ->first();

    $user->balance += 100;
    $user->save();
});
```

### Shared Lock

Read lock - others can read but not write:

```php
Model::transaction(function () {
    $user = User::where('id', 1)
        ->sharedLock()
        ->first();

    // Read operations...
});
```

### Database-Specific Locking

NanoORM generates appropriate SQL:

| Database | For Update | Shared |
|----------|------------|--------|
| MySQL | `FOR UPDATE` | `LOCK IN SHARE MODE` |
| PostgreSQL | `FOR UPDATE` | `FOR SHARE` |
| SQL Server | `WITH (UPDLOCK, ROWLOCK)` | `WITH (HOLDLOCK, ROWLOCK)` |
| SQLite | No-op (use WAL mode) | No-op |

## Bulk Operations

### Upsert

Insert or update on conflict:

```php
User::upsert(
    [
        ['email' => 'john@example.com', 'name' => 'John', 'visits' => 1],
        ['email' => 'jane@example.com', 'name' => 'Jane', 'visits' => 1],
    ],
    ['email'],           // Unique column(s)
    ['name', 'visits']   // Columns to update on conflict
);
```

**Database Behavior:**
- MySQL: `ON DUPLICATE KEY UPDATE`
- PostgreSQL/SQLite: `ON CONFLICT DO UPDATE`

### Insert or Ignore

Insert and silently skip conflicts:

```php
User::insertOrIgnore([
    ['email' => 'john@example.com', 'name' => 'John'],
    ['email' => 'existing@example.com', 'name' => 'Skipped'],
]);
```

### Insert and Get IDs

```php
$ids = User::insertGetIds([
    ['name' => 'John'],
    ['name' => 'Jane'],
]);
// [1, 2]
```

## Pagination

### Offset Pagination

Traditional page-based pagination:

```php
$page = User::orderBy('id')
    ->paginate(15, 2);  // 15 per page, page 2

[
    'data' => Collection,
    'total' => 150,
    'per_page' => 15,
    'current_page' => 2,
    'last_page' => 10,
    'from' => 16,
    'to' => 30,
]
```

### Cursor Pagination

More efficient for large datasets:

```php
// First page
$page1 = User::orderBy('id')
    ->cursorPaginate(15, 'id');

[
    'data' => Collection,
    'next_cursor' => '15',
    'prev_cursor' => null,
    'has_more' => true,
]

// Next page using cursor
$page2 = User::orderBy('id')
    ->cursorPaginate(15, 'id', $page1['next_cursor']);
```

**Why Cursor Pagination?**
- Offset pagination: `OFFSET 10000` still scans 10,000 rows
- Cursor pagination: `WHERE id > 10000` uses index efficiently

## Transactions

### Basic Transaction

```php
Model::transaction(function () {
    User::create(['name' => 'John']);
    Profile::create(['user_id' => 1, 'bio' => 'Hello']);
});
// Automatically commits on success, rolls back on exception
```

### Manual Transactions

```php
$pdo = Model::getConnection();

$pdo->beginTransaction();

try {
    User::create(['name' => 'John']);
    Profile::create(['user_id' => 1]);

    $pdo->commit();
} catch (\Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

## Query Logging

### Enable Logging

```php
Model::enableQueryLog();
Model::flushQueryLog();

// Run queries...
$users = User::where('active', true)->get();
$posts = Post::limit(10)->get();

// Get log
$log = Model::getQueryLog();

foreach ($log as $entry) {
    echo "SQL: " . $entry['sql'] . "\n";
    echo "Bindings: " . json_encode($entry['bindings']) . "\n";
    echo "Time: " . $entry['time'] . "ms\n";
}

Model::disableQueryLog();
```

### Debug Single Query

```php
$query = User::where('active', true)->orderBy('name');

[$sql, $bindings] = $query->toSql();

echo $sql;
// SELECT * FROM users WHERE active = ? ORDER BY name ASC

print_r($bindings);
// [true]
```

## Raw Expressions

### Select Raw

```php
User::selectRaw('COUNT(*) as total, role')
    ->groupBy('role')
    ->get();
```

### Where Raw

```php
User::whereRaw('YEAR(created_at) = ?', [2024])->get();
User::whereRaw('age BETWEEN ? AND ?', [18, 65])->get();
```

### Having Raw

```php
Post::selectRaw('user_id, COUNT(*) as count')
    ->groupBy('user_id')
    ->havingRaw('COUNT(*) > ?', [5])
    ->get();
```

### Order By Raw

```php
User::orderByRaw('FIELD(role, "admin", "moderator", "user")')->get();
```

## Performance Tips

### 1. Use Eager Loading

```php
// Bad: N+1 queries
$users = User::all();
foreach ($users as $user) {
    echo $user->profile->bio;  // Query per user
}

// Good: 2 queries
$users = User::with('profile')->get();
foreach ($users as $user) {
    echo $user->profile->bio;
}
```

### 2. Select Only Needed Columns

```php
// Bad: Select all columns
$users = User::all();

// Good: Select specific columns
$users = User::select('id', 'name', 'email')->get();
```

### 3. Use Cursor Pagination for Large Datasets

```php
// Bad for large offsets
User::paginate(15, 1000);  // OFFSET 14985

// Good: Uses index efficiently
User::cursorPaginate(15, 'id', $lastId);
```

### 4. Use Database-Side Counting

```php
// Bad: Loads all records
$count = User::all()->count();

// Good: COUNT(*) query
$count = User::count();
```

### 5. Chunk for Batch Processing

```php
User::query()->chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
});
```

### 6. Use Indexes

Ensure columns in WHERE, ORDER BY, and JOIN clauses are indexed in your database.
