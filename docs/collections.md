# Collections

NanoORM returns query results as `Collection` objects, providing powerful methods for working with data.

## Overview

```php
use NanoORM\Collection;

// Query results are Collections
$users = User::where('active', true)->get();

// Create manually
$collection = new Collection([1, 2, 3]);
$collection = Collection::make([1, 2, 3]);
```

## Basic Operations

### Accessing Items

```php
$users = User::all();

// Get all as array
$array = $users->all();

// Get first item
$first = $users->first();

// Get last item
$last = $users->last();

// Get by key/index
$user = $users->get(0);
$user = $users[0];  // ArrayAccess

// Count
$count = $users->count();
$count = count($users);  // Countable

// Check if empty
if ($users->isEmpty()) { ... }
if ($users->isNotEmpty()) { ... }
```

### Iterating

```php
// Foreach (IteratorAggregate)
foreach ($users as $user) {
    echo $user->name;
}

// Each with callback
$users->each(function ($user, $key) {
    echo $user->name;
});

// Break early by returning false
$users->each(function ($user) {
    if ($user->id > 5) return false;
    echo $user->name;
});
```

## Transformation

### Map

Transform each item:

```php
$names = $users->map(fn($user) => $user->name);
// Collection(['John', 'Jane', ...])

$formatted = $users->map(fn($user) => [
    'id' => $user->id,
    'display' => strtoupper($user->name),
]);
```

### Filter

Filter items with callback:

```php
// With callback
$admins = $users->filter(fn($user) => $user->role === 'admin');

// Without callback (removes falsy values)
$nonEmpty = $collection->filter();
```

### Reject

Inverse of filter:

```php
$nonAdmins = $users->reject(fn($user) => $user->role === 'admin');
```

### Reduce

Reduce to single value:

```php
$total = $orders->reduce(
    fn($carry, $order) => $carry + $order->amount,
    0
);
```

### Flatten

Flatten nested arrays/collections:

```php
$nested = Collection::make([[1, 2], [3, [4, 5]]]);

$flat = $nested->flatten();    // [1, 2, 3, 4, 5]
$flat = $nested->flatten(1);   // [1, 2, 3, [4, 5]]
```

### Chunk

Split into smaller collections:

```php
$chunks = $users->chunk(10);  // Collection of Collections

foreach ($chunks as $chunk) {
    // Process 10 users at a time
}
```

## Accessing Data

### Pluck

Extract column values:

```php
// Get all names
$names = $users->pluck('name');
// Collection(['John', 'Jane', ...])

// Key by another column
$names = $users->pluck('name', 'id');
// Collection([1 => 'John', 2 => 'Jane', ...])
```

### First/Last with Callback

```php
// First matching
$admin = $users->first(fn($user) => $user->role === 'admin');

// Last matching
$lastAdmin = $users->last(fn($user) => $user->role === 'admin');

// With default
$admin = $users->first(fn($u) => $u->role === 'admin', new User());
```

### Group By

Group items by key or callback:

```php
// By column
$byRole = $users->groupBy('role');
// Collection(['admin' => Collection([...]), 'user' => Collection([...])])

// By callback
$byDomain = $users->groupBy(fn($user) =>
    explode('@', $user->email)[1]
);
```

### Key By

Re-key collection by column:

```php
$byId = $users->keyBy('id');
// Collection([1 => User, 2 => User, ...])

$byEmail = $users->keyBy('email');
// Collection(['john@example.com' => User, ...])
```

## Sorting

### Sort

```php
// Natural sort
$sorted = $collection->sort();

// With comparison function
$sorted = $users->sort(fn($a, $b) => $a->age <=> $b->age);
```

### Sort By

```php
// By column
$sorted = $users->sortBy('name');

// Descending
$sorted = $users->sortByDesc('created_at');

// By callback
$sorted = $users->sortBy(fn($user) => strlen($user->name));
```

### Reverse

```php
$reversed = $collection->reverse();
```

## Aggregates

```php
$orders = Order::all();

// Sum
$total = $orders->sum('amount');
$total = $orders->sum(fn($o) => $o->quantity * $o->price);

// Average
$avg = $orders->avg('amount');

// Min/Max
$min = $orders->min('amount');
$max = $orders->max('amount');

// Median
$median = $orders->median('amount');
```

## Searching and Filtering

### Contains

```php
// Contains value
$collection->contains(5);  // true/false

// Contains by key/value
$users->contains('name', 'John');

// Contains by callback
$users->contains(fn($user) => $user->role === 'admin');
```

### Where

Filter by column comparison:

```php
// Equality
$admins = $users->where('role', 'admin');

// With operator
$adults = $users->where('age', '>=', 18);

// Supported operators: =, ==, ===, !=, !==, <>, <, >, <=, >=
```

### Where In / Not In

```php
$selected = $users->whereIn('id', [1, 2, 3]);
$excluded = $users->whereNotIn('role', ['banned', 'suspended']);
```

### Where Null / Not Null

```php
$unverified = $users->whereNull('email_verified_at');
$verified = $users->whereNotNull('email_verified_at');
```

### Unique

```php
// Unique values
$unique = $collection->unique();

// Unique by column
$uniqueRoles = $users->unique('role');
```

## Set Operations

### Merge

```php
$combined = $collection1->merge($collection2);
$combined = $collection->merge([4, 5, 6]);
```

### Diff

```php
// Items in $a not in $b
$diff = $collectionA->diff($collectionB);
```

### Intersect

```php
// Items in both
$common = $collectionA->intersect($collectionB);
```

### Combine

```php
$keys = Collection::make(['name', 'email']);
$values = Collection::make(['John', 'john@example.com']);

$combined = $values->combine($keys);
// Collection(['name' => 'John', 'email' => 'john@example.com'])
```

## Slicing

### Take / Skip

```php
// First 5
$first5 = $collection->take(5);

// Last 5
$last5 = $collection->take(-5);

// Skip first 10
$rest = $collection->skip(10);
```

### Slice

```php
$slice = $collection->slice(5, 10);  // 10 items starting at index 5
```

### Pagination

```php
// Get page 2 with 15 items per page
$page2 = $collection->forPage(2, 15);
```

## Keys and Values

```php
// Reset keys (re-index)
$reindexed = $collection->values();

// Get just keys
$keys = $collection->keys();
```

## Stack Operations

```php
// Add to end
$collection->push($item);
$collection->push($item1, $item2);

// Remove from end
$last = $collection->pop();

// Remove from beginning
$first = $collection->shift();

// Add to beginning
$collection->prepend($item);
```

## Conversion

### To Array

```php
// Simple array
$array = $collection->all();

// With nested model conversion
$array = $collection->toArray();
```

### To JSON

```php
$json = $collection->toJson();
$json = $collection->toJson(JSON_PRETTY_PRINT);

// String casting
echo $collection;  // JSON output

// json_encode (JsonSerializable)
echo json_encode($collection);
```

## ArrayAccess

Collections implement ArrayAccess:

```php
// Check existence
isset($collection[0]);

// Get item
$item = $collection[0];

// Set item
$collection[0] = $newItem;
$collection[] = $newItem;  // Append

// Remove item
unset($collection[0]);
```

## Method Chaining

All transformation methods return new collections, enabling chaining:

```php
$result = User::all()
    ->filter(fn($u) => $u->active)
    ->sortBy('name')
    ->pluck('email')
    ->unique()
    ->values()
    ->take(10)
    ->toArray();
```

## Creating Collections

```php
// From array
$collection = new Collection([1, 2, 3]);
$collection = Collection::make([1, 2, 3]);

// Wrap any value
$collection = Collection::wrap($value);
// - Collection: returns copy
// - Array: wraps in collection
// - Other: wraps in single-item collection
```

## Working with Models

Collections work seamlessly with models:

```php
$users = User::with('posts')->get();

// Access relationships
$allPosts = $users->map(fn($user) => $user->posts)->flatten();

// Pluck from relationships
$postTitles = $users->map(fn($user) =>
    $user->posts->pluck('title')
)->flatten();

// Convert to array (converts nested models too)
$array = $users->toArray();
```
