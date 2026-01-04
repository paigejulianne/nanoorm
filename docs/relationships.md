# Relationships

NanoORM supports four types of relationships between models.

## Relationship Types

| Type | Description |
|------|-------------|
| `HasOne` | One-to-one (e.g., User has one Profile) |
| `HasMany` | One-to-many (e.g., User has many Posts) |
| `BelongsTo` | Inverse of HasOne/HasMany (e.g., Post belongs to User) |
| `BelongsToMany` | Many-to-many with pivot table (e.g., Post has many Tags) |

## Defining Relationships

### Has One

A one-to-one relationship where the related model has a foreign key pointing to this model.

```php
use NanoORM\Model;
use NanoORM\HasOne;

class User extends Model
{
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}

class Profile extends Model
{
    // profiles table has user_id column
}
```

**Parameters:**

```php
$this->hasOne(
    Profile::class,     // Related model class
    'user_id',          // Foreign key on related model (default: {table}_id)
    'id'                // Local key (default: primary key)
);
```

**Usage:**

```php
$user = User::find(1);
$profile = $user->profile;  // Profile instance or null
```

### Has Many

A one-to-many relationship where multiple related models have foreign keys pointing to this model.

```php
use NanoORM\HasMany;

class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    // posts table has user_id column
}
```

**Parameters:**

```php
$this->hasMany(
    Post::class,        // Related model class
    'user_id',          // Foreign key on related model
    'id'                // Local key
);
```

**Usage:**

```php
$user = User::find(1);
$posts = $user->posts;  // Collection of Post models
```

### Belongs To

The inverse of HasOne or HasMany. This model has a foreign key pointing to the related model.

```php
use NanoORM\BelongsTo;

class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

**Parameters:**

```php
$this->belongsTo(
    User::class,        // Related model class
    'user_id',          // Foreign key on this model
    'id'                // Owner key on related model
);
```

**Usage:**

```php
$post = Post::find(1);
$author = $post->author;  // User instance or null
```

### Belongs To Many

A many-to-many relationship using a pivot table.

```php
use NanoORM\BelongsToMany;

class Post extends Model
{
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }
}

class Tag extends Model
{
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }
}
```

**Pivot Table Structure:**

```sql
CREATE TABLE post_tag (
    post_id INTEGER,
    tag_id INTEGER,
    PRIMARY KEY (post_id, tag_id)
);
```

**Parameters:**

```php
$this->belongsToMany(
    Tag::class,         // Related model class
    'post_tag',         // Pivot table name
    'post_id',          // This model's foreign key in pivot
    'tag_id'            // Related model's foreign key in pivot
);
```

**Default Pivot Table Naming:**

If not specified, NanoORM generates the pivot table name by:
1. Taking the singular form of both table names
2. Sorting them alphabetically
3. Joining with underscore

Example: `posts` + `tags` → `post_tag`

## Accessing Relationships

Access relationships as properties (lazy loading):

```php
$user = User::find(1);

// Lazy load - queries database on first access
$posts = $user->posts;     // Collection
$profile = $user->profile; // Model or null
```

Relationships are cached after first access:

```php
$posts1 = $user->posts;  // Queries database
$posts2 = $user->posts;  // Returns cached result (no query)
```

## Eager Loading

Prevent N+1 query problems by eager loading relationships:

### With Query

```php
// Load single relationship
$users = User::with('posts')->get();

// Load multiple relationships
$users = User::with('posts', 'profile')->get();

// Nested relationships
$users = User::with('posts.comments')->get();

// Multiple with array
$users = User::with(['posts', 'profile', 'comments'])->get();
```

### After Loading

```php
$user = User::find(1);

// Load relationships on existing model
$user->load('posts', 'profile');
```

### Query Count Comparison

**Without eager loading (N+1 problem):**

```php
$users = User::all();  // 1 query

foreach ($users as $user) {
    echo $user->profile->bio;  // N queries (one per user)
}
// Total: N+1 queries
```

**With eager loading:**

```php
$users = User::with('profile')->get();  // 2 queries

foreach ($users as $user) {
    echo $user->profile->bio;  // No additional queries
}
// Total: 2 queries
```

## Managing BelongsToMany

### Attach

Add records to the pivot table:

```php
$post = Post::find(1);

// Attach single
$post->tags()->attach($tagId);

// Attach multiple
$post->tags()->attach([1, 2, 3]);
```

### Detach

Remove records from the pivot table:

```php
// Detach single
$post->tags()->detach($tagId);

// Detach multiple
$post->tags()->detach([1, 2]);

// Detach all
$post->tags()->detach();
```

### Sync

Synchronize the pivot table to match given IDs:

```php
// Sets exactly these tags (adds new, removes old)
$result = $post->tags()->sync([1, 2, 3]);

// Returns:
[
    'attached' => [2, 3],   // IDs that were added
    'detached' => [4, 5],   // IDs that were removed
]
```

## Setting Relationships Manually

Useful for testing or when eager loading externally:

```php
$user = new User(['name' => 'John']);

$posts = [
    new Post(['title' => 'First']),
    new Post(['title' => 'Second']),
];

$user->setRelation('posts', $posts);

echo count($user->posts);  // 2 (no database query)
```

## Relationship Methods

Access the relationship object instead of results:

```php
$user = User::find(1);

// Get relationship instance (QueryBuilder-like)
$postsRelation = $user->posts();

// Add constraints
$recentPosts = $user->posts()
    ->where('created_at', '>', '2024-01-01')
    ->orderBy('created_at', 'DESC')
    ->get();

// Count without loading
$postCount = $user->posts()->count();
```

## Foreign Key Conventions

NanoORM uses these defaults for foreign keys:

| Relationship | Foreign Key Pattern |
|--------------|-------------------|
| HasOne/HasMany | `{singular_table}_id` on related model |
| BelongsTo | `{related_singular_table}_id` on this model |
| BelongsToMany | `{singular_table}_id` on pivot table |

Examples:
- `User` model with `users` table → `user_id`
- `BlogPost` model with `blog_posts` table → `blog_post_id`

## Complete Example

```php
<?php
use NanoORM\Model;
use NanoORM\HasOne;
use NanoORM\HasMany;
use NanoORM\BelongsTo;
use NanoORM\BelongsToMany;

class User extends Model
{
    public const bool TIMESTAMPS = true;

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}

class Profile extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

class Post extends Model
{
    public const bool TIMESTAMPS = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }
}

class Comment extends Model
{
    public const bool TIMESTAMPS = true;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

class Tag extends Model
{
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }
}
```

**Usage:**

```php
// Get user with all relationships
$user = User::with('profile', 'posts.comments', 'posts.tags')->find(1);

// Access data
echo $user->profile->bio;

foreach ($user->posts as $post) {
    echo $post->title;

    foreach ($post->tags as $tag) {
        echo $tag->name;
    }

    foreach ($post->comments as $comment) {
        echo $comment->body;
        echo $comment->author->name;
    }
}
```
