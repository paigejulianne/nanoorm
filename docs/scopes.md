# Scopes

Scopes allow you to define reusable query constraints that can be applied to queries.

## Local Scopes

Local scopes are methods on your model that encapsulate common query constraints.

### Defining Local Scopes

Prefix methods with `scope` and accept a QueryBuilder as the first parameter:

```php
class User extends Model
{
    public function scopeActive(QueryBuilder $query): QueryBuilder
    {
        return $query->where('active', true);
    }

    public function scopeRole(QueryBuilder $query, string $role): QueryBuilder
    {
        return $query->where('role', $role);
    }

    public function scopeCreatedAfter(QueryBuilder $query, string $date): QueryBuilder
    {
        return $query->where('created_at', '>=', $date);
    }
}
```

### Using Local Scopes

Call scopes as methods on the query builder (without the `scope` prefix):

```php
// Simple scope
$activeUsers = User::query()->active()->get();

// Scope with parameter
$admins = User::query()->role('admin')->get();

// Chaining scopes
$recentAdmins = User::query()
    ->active()
    ->role('admin')
    ->createdAfter('2024-01-01')
    ->get();
```

### Scope Examples

```php
class Post extends Model
{
    // Published posts
    public function scopePublished(QueryBuilder $query): QueryBuilder
    {
        return $query->where('is_published', true)
                     ->whereNotNull('published_at');
    }

    // Draft posts
    public function scopeDraft(QueryBuilder $query): QueryBuilder
    {
        return $query->where('is_published', false);
    }

    // Posts by author
    public function scopeByAuthor(QueryBuilder $query, int $userId): QueryBuilder
    {
        return $query->where('user_id', $userId);
    }

    // Popular posts (view count threshold)
    public function scopePopular(QueryBuilder $query, int $minViews = 1000): QueryBuilder
    {
        return $query->where('view_count', '>=', $minViews);
    }

    // Recent posts
    public function scopeRecent(QueryBuilder $query, int $days = 7): QueryBuilder
    {
        return $query->where('created_at', '>=', date('Y-m-d', strtotime("-$days days")));
    }
}

// Usage
$posts = Post::query()
    ->published()
    ->popular(5000)
    ->recent(30)
    ->orderBy('view_count', 'DESC')
    ->get();
```

## Global Scopes

Global scopes are automatically applied to all queries for a model.

### Defining Global Scopes

Add global scopes in your model using `addGlobalScope`:

```php
class User extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        // Only return active users by default
        static::addGlobalScope('active', function (QueryBuilder $query) {
            $query->where('active', true);
        });
    }
}
```

Or add them dynamically:

```php
// In your bootstrap or service provider
User::addGlobalScope('active', function ($query) {
    $query->where('active', true);
});
```

### Common Global Scope Use Cases

**1. Soft Delete Alternative:**

```php
class Post extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->whereNull('deleted_at');
        });
    }
}
```

**2. Multi-Tenancy:**

```php
class BaseModel extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('tenant', function ($query) {
            $query->where('tenant_id', app()->getCurrentTenantId());
        });
    }
}
```

**3. Default Ordering:**

```php
class Comment extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('ordered', function ($query) {
            $query->orderBy('created_at', 'DESC');
        });
    }
}
```

### Removing Global Scopes

Remove scopes for specific queries:

```php
// Remove single scope
User::query()->withoutGlobalScope('active')->get();

// Remove multiple scopes
User::query()->withoutGlobalScopes(['active', 'tenant'])->get();

// Remove all global scopes
User::query()->withoutGlobalScopes()->get();
```

### Global Scopes with Parameters

For parameterized global scopes, use a class:

```php
class TenantScope
{
    private int $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function __invoke(QueryBuilder $query): void
    {
        $query->where('tenant_id', $this->tenantId);
    }
}

// Apply
User::addGlobalScope('tenant', new TenantScope($currentTenantId));
```

## Combining Scopes

### Local + Global Scopes

```php
class User extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        // Global: Only active users
        static::addGlobalScope('active', fn($q) => $q->where('active', true));
    }

    // Local: Premium users
    public function scopePremium(QueryBuilder $query): QueryBuilder
    {
        return $query->where('subscription', 'premium');
    }
}

// Both scopes applied
$premiumUsers = User::query()->premium()->get();
// WHERE active = 1 AND subscription = 'premium'

// Only local scope
$allPremium = User::query()
    ->withoutGlobalScope('active')
    ->premium()
    ->get();
// WHERE subscription = 'premium'
```

### Scope Dependencies

Scopes can call other scopes:

```php
class Post extends Model
{
    public function scopePublished(QueryBuilder $query): QueryBuilder
    {
        return $query->where('is_published', true);
    }

    public function scopeFeatured(QueryBuilder $query): QueryBuilder
    {
        // Featured posts must be published
        return $query->published()
                     ->where('is_featured', true);
    }
}
```

## Dynamic Scopes

Create scopes at runtime:

```php
class Post extends Model
{
    public function scopeStatus(QueryBuilder $query, string $status): QueryBuilder
    {
        return match ($status) {
            'published' => $query->where('is_published', true),
            'draft' => $query->where('is_published', false),
            'scheduled' => $query->where('is_published', false)
                                  ->whereNotNull('scheduled_at'),
            default => $query,
        };
    }
}

$posts = Post::query()->status('published')->get();
```

## Testing with Scopes

### Testing Local Scopes

```php
public function testActiveScope(): void
{
    User::create(['name' => 'Active', 'active' => true]);
    User::create(['name' => 'Inactive', 'active' => false]);

    $activeUsers = User::query()->active()->get();

    $this->assertCount(1, $activeUsers);
    $this->assertEquals('Active', $activeUsers[0]->name);
}
```

### Testing Global Scopes

```php
public function testTenantScope(): void
{
    User::addGlobalScope('tenant', fn($q) => $q->where('tenant_id', 1));

    User::create(['name' => 'Tenant 1', 'tenant_id' => 1]);
    User::create(['name' => 'Tenant 2', 'tenant_id' => 2]);

    // Global scope applied
    $users = User::all();
    $this->assertCount(1, $users);

    // Without global scope
    $allUsers = User::query()->withoutGlobalScopes()->get();
    $this->assertCount(2, $allUsers);
}
```

## Best Practices

### 1. Keep Scopes Focused

Each scope should do one thing:

```php
// Good: Single responsibility
public function scopeActive($query) { ... }
public function scopeVerified($query) { ... }

// Bad: Multiple responsibilities
public function scopeActiveAndVerified($query) { ... }
```

### 2. Document Parameter Scopes

```php
/**
 * Filter posts by minimum view count.
 *
 * @param QueryBuilder $query
 * @param int $minViews Minimum number of views (default: 100)
 */
public function scopePopular(QueryBuilder $query, int $minViews = 100): QueryBuilder
{
    return $query->where('view_count', '>=', $minViews);
}
```

### 3. Use Descriptive Names

```php
// Good
scopePublishedLastWeek()
scopeWithActiveSubscription()
scopeOwnedBy($userId)

// Bad
scopeFilter()
scopeQuery()
scopeGet()
```

### 4. Consider Query Performance

Scopes that add JOINs or subqueries can impact performance:

```php
// Be careful with complex scopes
public function scopeWithCommentCount(QueryBuilder $query): QueryBuilder
{
    return $query->selectSub(
        fn($q) => $q->from('comments')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('comments.post_id', 'posts.id'),
        'comments_count'
    );
}
```
