# Events & Observers

NanoORM provides lifecycle hooks and observers for reacting to model events.

## Model Events

Models fire events during their lifecycle that you can hook into.

### Available Events

| Event | Triggered When |
|-------|----------------|
| `creating` | Before a new model is saved |
| `created` | After a new model is saved |
| `updating` | Before an existing model is saved |
| `updated` | After an existing model is saved |
| `saving` | Before any save (create or update) |
| `saved` | After any save (create or update) |
| `deleting` | Before a model is deleted |
| `deleted` | After a model is deleted |
| `restoring` | Before a soft-deleted model is restored |
| `restored` | After a soft-deleted model is restored |
| `forceDeleting` | Before a model is force deleted |
| `forceDeleted` | After a model is force deleted |

## Model Hooks

Define event handlers directly in your model:

```php
class User extends Model
{
    protected function onCreating(): void
    {
        // Generate UUID before saving
        if (empty($this->uuid)) {
            $this->uuid = $this->generateUuid();
        }
    }

    protected function onCreated(): void
    {
        // Send welcome email after creation
        EmailService::sendWelcome($this);
    }

    protected function onUpdating(): void
    {
        // Log changes
        Logger::info('User updating', [
            'id' => $this->getKey(),
            'changes' => $this->getDirty(),
        ]);
    }

    protected function onDeleting(): void
    {
        // Clean up related data
        $this->tokens()->delete();
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### Hook Method Naming

Use `on` prefix + PascalCase event name:

| Event | Method |
|-------|--------|
| `creating` | `onCreating()` |
| `created` | `onCreated()` |
| `updating` | `onUpdating()` |
| `updated` | `onUpdated()` |
| `saving` | `onSaving()` |
| `saved` | `onSaved()` |
| `deleting` | `onDeleting()` |
| `deleted` | `onDeleted()` |

## Event Listeners

Register event listeners externally:

```php
// Single event
User::on('created', function (User $user) {
    EmailService::sendWelcome($user);
});

// Multiple events
User::on('saving', function (User $user) {
    $user->slug = Str::slug($user->name);
});

User::on('deleted', function (User $user) {
    CacheService::forget("user:{$user->getKey()}");
});
```

### Registering Listeners

Typically in your bootstrap or service provider:

```php
// bootstrap.php or AppServiceProvider
require_once 'NanoORM.php';

User::on('created', [WelcomeEmailHandler::class, 'handle']);
User::on('updated', [UserCacheInvalidator::class, 'handle']);
Post::on('created', [PostIndexer::class, 'index']);
```

## Observers

Observers group multiple event handlers in a single class.

### Creating an Observer

```php
class UserObserver
{
    public function creating(User $user): void
    {
        $user->uuid = Uuid::generate();
    }

    public function created(User $user): void
    {
        EmailService::sendWelcome($user);
        Analytics::track('user.registered', $user);
    }

    public function updating(User $user): void
    {
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
    }

    public function updated(User $user): void
    {
        CacheService::forget("user:{$user->getKey()}");
    }

    public function deleting(User $user): void
    {
        // Archive user data before deletion
        Archive::user($user);
    }

    public function deleted(User $user): void
    {
        // Clean up external services
        SearchIndex::remove('users', $user->getKey());
    }

    public function restored(User $user): void
    {
        // Re-index restored user
        SearchIndex::add('users', $user);
    }
}
```

### Registering Observers

```php
// Register observer class
User::observe(UserObserver::class);

// Or with instance
User::observe(new UserObserver());
```

### Multiple Observers

```php
User::observe(UserObserver::class);
User::observe(AuditObserver::class);
User::observe(CacheObserver::class);
```

## Event Order

Events fire in this order:

**Creating a new model:**
1. `saving`
2. `creating`
3. (INSERT query)
4. `created`
5. `saved`

**Updating an existing model:**
1. `saving`
2. `updating`
3. (UPDATE query)
4. `updated`
5. `saved`

**Deleting a model:**
1. `deleting`
2. (DELETE query)
3. `deleted`

**Restoring a soft-deleted model:**
1. `restoring`
2. (UPDATE query)
3. `restored`

## Use Cases

### Auto-generating Slugs

```php
class Post extends Model
{
    protected function onSaving(): void
    {
        if ($this->isDirty('title') || empty($this->slug)) {
            $this->slug = $this->generateSlug($this->title);
        }
    }

    private function generateSlug(string $title): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $original = $slug;
        $count = 1;
        while (Post::where('slug', $slug)->where('id', '!=', $this->getKey())->exists()) {
            $slug = $original . '-' . $count++;
        }

        return $slug;
    }
}
```

### Audit Logging

```php
class AuditObserver
{
    public function created(Model $model): void
    {
        $this->log('created', $model);
    }

    public function updated(Model $model): void
    {
        $this->log('updated', $model, $model->getDirty());
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model);
    }

    private function log(string $action, Model $model, array $changes = []): void
    {
        AuditLog::create([
            'action' => $action,
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'changes' => json_encode($changes),
            'user_id' => Auth::id(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}

// Apply to all models
User::observe(AuditObserver::class);
Post::observe(AuditObserver::class);
Comment::observe(AuditObserver::class);
```

### Cache Invalidation

```php
class CacheObserver
{
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $table = $model::getTable();
        $id = $model->getKey();

        Cache::forget("{$table}:{$id}");
        Cache::forget("{$table}:all");
    }
}
```

### Sending Notifications

```php
class OrderObserver
{
    public function created(Order $order): void
    {
        // Notify customer
        Notification::send($order->customer, new OrderConfirmation($order));

        // Notify warehouse
        Notification::send($order->warehouse, new NewOrderAlert($order));
    }

    public function updated(Order $order): void
    {
        if ($order->isDirty('status')) {
            match ($order->status) {
                'shipped' => Notification::send(
                    $order->customer,
                    new OrderShipped($order)
                ),
                'delivered' => Notification::send(
                    $order->customer,
                    new OrderDelivered($order)
                ),
                default => null,
            };
        }
    }
}
```

### Cascading Deletes

```php
class User extends Model
{
    protected function onDeleting(): void
    {
        // Delete related records
        foreach ($this->posts as $post) {
            $post->delete();  // Triggers Post's deleting event
        }

        $this->profile?->delete();
        $this->comments()->delete();
    }
}
```

## Best Practices

### 1. Keep Handlers Fast

Avoid slow operations in event handlers. Use queues for heavy tasks:

```php
public function created(User $user): void
{
    // Bad: Slow operation blocks response
    $this->sendWelcomeEmail($user);
    $this->syncToMailchimp($user);
    $this->indexInElasticsearch($user);

    // Good: Queue for background processing
    Queue::push(new ProcessNewUser($user->getKey()));
}
```

### 2. Avoid Infinite Loops

Be careful with save operations in event handlers:

```php
// Bad: Infinite loop
protected function onUpdating(): void
{
    $this->updated_count++;
    $this->save();  // Triggers updating again!
}

// Good: Use increment (atomic, no events)
protected function onUpdated(): void
{
    $this->increment('updated_count');
}
```

### 3. Use Observers for Cross-Cutting Concerns

Group related logic in observers rather than scattering across models:

```php
// Good: Centralized audit logic
class AuditObserver { ... }
User::observe(AuditObserver::class);
Post::observe(AuditObserver::class);

// Bad: Duplicate logic in each model
class User { protected function onCreated() { $this->audit(); } }
class Post { protected function onCreated() { $this->audit(); } }
```

### 4. Handle Failures Gracefully

```php
protected function onCreated(): void
{
    try {
        ExternalService::notify($this);
    } catch (\Exception $e) {
        Logger::error('Failed to notify external service', [
            'model' => static::class,
            'id' => $this->getKey(),
            'error' => $e->getMessage(),
        ]);
        // Don't rethrow - model creation succeeded
    }
}
```

### 5. Document Side Effects

```php
/**
 * User Model
 *
 * Event Side Effects:
 * - creating: Generates UUID
 * - created: Sends welcome email, creates default settings
 * - deleting: Archives data, cleans up tokens
 */
class User extends Model { ... }
```
