# Querying

NanoORM provides a fluent query builder for constructing database queries.

## Starting a Query

```php
// Create a query builder instance
$query = User::query();

// Shortcut static methods
$users = User::where('active', true)->get();
```

## Retrieving Results

### Get All Results

```php
// All records
$users = User::all();

// With conditions
$users = User::where('active', true)->get();
```

### Get Single Record

```php
// First matching record
$user = User::where('email', 'john@example.com')->first();

// First or null
$user = User::where('role', 'admin')->first(); // null if not found

// First or exception
$user = User::where('role', 'admin')->firstOrFail();

// Find by primary key
$user = User::find(1);
$user = User::findOrFail(1);

// Find multiple
$users = User::findMany([1, 2, 3]);
```

### Check Existence

```php
if (User::where('email', 'test@example.com')->exists()) {
    // Record exists
}

if (User::where('role', 'superadmin')->doesntExist()) {
    // No records found
}
```

## WHERE Clauses

### Basic Where

```php
// Equality (shorthand)
User::where('active', true)->get();

// With operator
User::where('age', '>=', 18)->get();
User::where('role', '!=', 'guest')->get();

// Array of conditions
User::where([
    'active' => true,
    'role' => 'admin',
])->get();
```

### Supported Operators

| Operator | Description |
|----------|-------------|
| `=` | Equal |
| `!=`, `<>` | Not equal |
| `<`, `>` | Less than, greater than |
| `<=`, `>=` | Less/greater than or equal |
| `LIKE`, `NOT LIKE` | Pattern matching |
| `ILIKE`, `NOT ILIKE` | Case-insensitive pattern (PostgreSQL) |
| `IN`, `NOT IN` | In array |
| `BETWEEN`, `NOT BETWEEN` | Range |
| `IS`, `IS NOT` | Null comparison |
| `REGEXP`, `NOT REGEXP` | Regular expression |

### OR Where

```php
User::where('role', 'admin')
    ->orWhere('role', 'moderator')
    ->get();
```

### Where In / Not In

```php
User::whereIn('status', ['active', 'pending'])->get();
User::whereNotIn('role', ['guest', 'banned'])->get();
```

### Where Null / Not Null

```php
User::whereNull('email_verified_at')->get();
User::whereNotNull('last_login_at')->get();
```

### Where Between

```php
User::whereBetween('age', 18, 65)->get();
User::whereNotBetween('created_at', '2023-01-01', '2023-12-31')->get();
```

### Nested Where Clauses

Group conditions with closures:

```php
User::where('active', true)
    ->where(function ($query) {
        $query->where('role', 'admin')
              ->orWhere('role', 'moderator');
    })
    ->get();

// SQL: WHERE active = 1 AND (role = 'admin' OR role = 'moderator')
```

### Raw Where

Use raw SQL when needed:

```php
User::whereRaw('YEAR(created_at) = ?', [2024])->get();
```

### Column Comparisons

Compare two columns:

```php
// Where updated_at differs from created_at
Post::whereColumn('updated_at', '!=', 'created_at')->get();

// Shorthand for equality
Post::whereColumn('created_at', 'published_at')->get();
```

## Ordering

```php
// Ascending (default)
User::orderBy('name')->get();

// Descending
User::orderBy('created_at', 'DESC')->get();

// Multiple orders
User::orderBy('role')->orderBy('name')->get();

// Convenience methods
User::latest()->get();              // ORDER BY created_at DESC
User::oldest()->get();              // ORDER BY created_at ASC
User::latest('updated_at')->get();  // ORDER BY updated_at DESC
```

## Limiting and Offsetting

```php
// Limit results
User::limit(10)->get();

// Offset for pagination
User::offset(20)->limit(10)->get();

// Take (alias for limit)
User::take(5)->get();

// Skip (alias for offset)
User::skip(10)->take(5)->get();
```

## Selecting Columns

```php
// Specific columns
User::select('id', 'name', 'email')->get();

// With alias using raw
User::select('id', 'name AS full_name')->get();
```

### Select Subquery

Add a subquery as a column:

```php
User::select('*')
    ->selectSub(
        fn($q) => $q->from('posts')
            ->select('COUNT(*)')
            ->whereColumn('posts.user_id', 'users.id'),
        'posts_count'
    )
    ->get();
```

### Select Raw

```php
User::selectRaw('COUNT(*) as total, role')
    ->groupBy('role')
    ->get();
```

## Aggregates

```php
// Count
$total = User::count();
$activeCount = User::where('active', true)->count();

// Sum
$total = Order::sum('amount');

// Average
$avgAge = User::avg('age');

// Min/Max
$oldest = User::min('birth_date');
$highest = Order::max('amount');
```

## Plucking Values

Extract values from a single column:

```php
// Array of names
$names = User::pluck('name');

// Keyed by ID
$names = User::pluck('name', 'id');
// [1 => 'John', 2 => 'Jane', ...]
```

## GROUP BY and HAVING

```php
// Group by role
$stats = User::select('role')
    ->selectRaw('COUNT(*) as count')
    ->groupBy('role')
    ->get();

// With having
$popularTags = Post::select('tag')
    ->selectRaw('COUNT(*) as count')
    ->groupBy('tag')
    ->having('count', '>', 10)
    ->get();

// Raw having
Post::groupBy('user_id')
    ->havingRaw('COUNT(*) > ?', [5])
    ->get();
```

## Joins

### Inner Join

```php
User::join('posts', 'users.id', '=', 'posts.user_id')
    ->select('users.*', 'posts.title')
    ->get();
```

### Left Join

```php
User::leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
    ->get();
```

### Right Join

```php
User::rightJoin('orders', 'users.id', '=', 'orders.user_id')
    ->get();
```

### Cross Join

```php
Size::crossJoin('colors')->get();
```

### Raw Join

```php
User::joinRaw('LEFT JOIN posts ON posts.user_id = users.id AND posts.published = ?', [true])
    ->get();
```

### Table Aliases

```php
User::join('posts AS p', 'users.id', '=', 'p.user_id')->get();
```

## Subqueries

### Where Exists

```php
// Users who have posts
User::whereExists(function ($query) {
    $query->from('posts')
          ->whereColumn('posts.user_id', 'users.id');
})->get();

// Users who don't have posts
User::whereNotExists(function ($query) {
    $query->from('posts')
          ->whereColumn('posts.user_id', 'users.id');
})->get();
```

### Where In Subquery

```php
// Users whose ID is in the posts table
User::whereInSubquery('id', function ($query) {
    $query->from('posts')->select('user_id');
})->get();
```

## Union Queries

Combine multiple queries:

```php
// Get all admins and all editors
User::where('role', 'admin')
    ->union(fn($q) => $q->where('role', 'editor'))
    ->get();

// Union all (keeps duplicates)
User::where('role', 'admin')
    ->unionAll(fn($q) => $q->where('role', 'editor'))
    ->get();
```

## Pagination

### Offset Pagination

```php
$page = User::orderBy('id')->paginate(15, 1);  // 15 per page, page 1

// Returns:
[
    'data' => Collection,   // The models
    'total' => 100,         // Total records
    'per_page' => 15,
    'current_page' => 1,
    'last_page' => 7,
    'from' => 1,
    'to' => 15,
]
```

### Cursor Pagination

More efficient for large datasets:

```php
$page = User::orderBy('id')
    ->cursorPaginate(15, 'id', null);  // First page

// Returns:
[
    'data' => Collection,
    'next_cursor' => '15',   // Use for next page
    'prev_cursor' => null,
    'has_more' => true,
]

// Get next page
$nextPage = User::orderBy('id')
    ->cursorPaginate(15, 'id', $page['next_cursor']);
```

## Row Locking

For transaction safety:

```php
Model::transaction(function () {
    // Lock for update (exclusive lock)
    $user = User::where('id', 1)->lockForUpdate()->first();

    // Shared lock (read lock)
    $user = User::where('id', 1)->sharedLock()->first();

    $user->balance += 100;
    $user->save();
});
```

## Bulk Operations

### Insert Multiple Records

```php
User::insert([
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
]);
```

### Upsert (Insert or Update)

```php
// Insert or update based on unique column
User::upsert(
    [
        ['email' => 'john@example.com', 'name' => 'John Updated'],
        ['email' => 'jane@example.com', 'name' => 'Jane Updated'],
    ],
    ['email'],           // Unique column(s)
    ['name']             // Columns to update on conflict
);
```

### Insert or Ignore

```php
// Insert, ignore if conflicts
User::insertOrIgnore([
    ['email' => 'john@example.com', 'name' => 'John'],
]);
```

### Update Multiple Records

```php
User::where('active', false)->update(['status' => 'inactive']);
```

### Delete Multiple Records

```php
User::where('last_login_at', '<', '2020-01-01')->delete();
```

## Debugging Queries

### Query Logging

```php
Model::enableQueryLog();
Model::flushQueryLog();

// Run queries...
$users = User::where('active', true)->get();
$posts = Post::limit(10)->get();

// Get log
$queries = Model::getQueryLog();
foreach ($queries as $query) {
    echo $query['sql'];
    print_r($query['bindings']);
    echo $query['time'] . 'ms';
}

Model::disableQueryLog();
```

### Get SQL

```php
$query = User::where('active', true)->orderBy('name');

[$sql, $bindings] = $query->toSql();
// $sql: "SELECT * FROM users WHERE active = ? ORDER BY name ASC"
// $bindings: [true]
```
