# Models

Models represent database tables and provide an intuitive way to interact with your data.

## Defining Models

Create a model by extending the `Model` class:

```php
use NanoORM\Model;

class User extends Model
{
    public const ?string TABLE = 'users';
}
```

## Model Configuration

### Table Name

By default, NanoORM derives the table name from the class name using snake_case and pluralization:

| Class Name | Table Name |
|------------|------------|
| `User` | `users` |
| `BlogPost` | `blog_posts` |
| `Category` | `categories` |

Override with the `TABLE` constant:

```php
class User extends Model
{
    public const ?string TABLE = 'app_users';
}
```

### Primary Key

The default primary key is `id`. Override if needed:

```php
class User extends Model
{
    public const string PRIMARY_KEY = 'user_id';
}
```

### Database Connection

Specify which connection a model should use:

```php
class AnalyticsEvent extends Model
{
    public const string CONNECTION = 'analytics';
}
```

### Timestamps

Enable automatic `created_at` and `updated_at` columns:

```php
class User extends Model
{
    public const bool TIMESTAMPS = true;

    // Customize column names (optional)
    public const string CREATED_AT = 'created_at';
    public const string UPDATED_AT = 'updated_at';
}
```

### Soft Deletes

Enable soft deletes to mark records as deleted without removing them:

```php
class User extends Model
{
    public const bool SOFT_DELETES = true;

    // Customize column name (optional)
    public const string DELETED_AT = 'deleted_at';
}
```

Query behavior with soft deletes:

```php
// Normal queries exclude soft-deleted records
User::all();                              // Active users only

// Include soft-deleted records
User::query()->withTrashed()->get();      // All users

// Only soft-deleted records
User::query()->onlyTrashed()->get();      // Deleted users only

// Check if record is deleted
if ($user->trashed()) { ... }

// Restore a soft-deleted record
$user->restore();

// Permanently delete
$user->forceDelete();
```

## Attribute Casting

Automatically cast attributes to PHP types:

```php
class User extends Model
{
    public const array CASTS = [
        'is_admin' => 'boolean',
        'settings' => 'json',
        'login_count' => 'integer',
        'balance' => 'float',
        'email_verified_at' => 'datetime',
    ];
}
```

### Available Cast Types

| Cast | Description |
|------|-------------|
| `int`, `integer` | Cast to integer |
| `float`, `double`, `real` | Cast to float |
| `string` | Cast to string |
| `bool`, `boolean` | Cast to boolean |
| `array`, `json` | JSON decode to array |
| `object` | JSON decode to object |
| `datetime`, `date` | Cast to DateTime |
| `timestamp` | Cast to Unix timestamp |

### JSON Casting Example

```php
class User extends Model
{
    public const array CASTS = [
        'settings' => 'json',
    ];
}

// Automatically encoded/decoded
$user = User::create([
    'name' => 'John',
    'settings' => ['theme' => 'dark', 'notifications' => true],
]);

// Access as array
echo $user->settings['theme']; // 'dark'
```

## Hidden and Visible Attributes

Control which attributes appear in array/JSON output:

```php
class User extends Model
{
    // These attributes are excluded from arrays/JSON
    public const array HIDDEN = ['password', 'remember_token'];

    // If set, ONLY these attributes are included
    public const array VISIBLE = ['id', 'name', 'email'];
}
```

## Mass Assignment Protection

Protect against mass assignment vulnerabilities:

### Using Fillable (Whitelist)

```php
class User extends Model
{
    // Only these attributes can be mass assigned
    public const array FILLABLE = ['name', 'email', 'password'];
}
```

### Using Guarded (Blacklist)

```php
class User extends Model
{
    // These attributes cannot be mass assigned
    public const array GUARDED = ['id', 'is_admin'];
}
```

### Force Filling

Bypass mass assignment protection when needed:

```php
$user = new User();
$user->forceFill([
    'name' => 'John',
    'is_admin' => true,  // Would be blocked by GUARDED
]);
```

## Accessors and Mutators

### Accessors

Transform attribute values when reading:

```php
class User extends Model
{
    // Accessor for 'full_name' attribute
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    // Accessor for existing 'name' attribute
    public function getNameAttribute(mixed $value): string
    {
        return ucfirst($value);
    }
}

// Usage
echo $user->full_name;  // "John Doe"
echo $user->name;       // "John" (capitalized)
```

### Mutators

Transform attribute values when setting:

```php
class User extends Model
{
    // Mutator for 'password' attribute
    public function setPasswordAttribute(mixed $value): string
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    // Mutator for 'email' attribute
    public function setEmailAttribute(mixed $value): string
    {
        return strtolower(trim($value));
    }
}

// Usage
$user->password = 'secret123';  // Automatically hashed
$user->email = '  JOHN@EXAMPLE.COM  ';  // Stored as "john@example.com"
```

### Naming Convention

- Accessors: `get{AttributeName}Attribute()`
- Mutators: `set{AttributeName}Attribute()`

Convert snake_case attributes to PascalCase in the method name:
- `full_name` → `getFullNameAttribute()`
- `email_verified_at` → `getEmailVerifiedAtAttribute()`

## Attribute Access

### Getting Attributes

```php
$user = User::find(1);

// Property access
echo $user->name;

// Get method
echo $user->getAttribute('name');

// Get all attributes
$attributes = $user->getAttributes();

// Get original value (before changes)
echo $user->getOriginal('name');
```

### Setting Attributes

```php
// Property access
$user->name = 'John';

// Set method
$user->setAttribute('name', 'John');

// Fill multiple
$user->fill(['name' => 'John', 'email' => 'john@example.com']);
```

### Checking Attribute Existence

```php
if (isset($user->name)) { ... }

// Remove attribute
unset($user->name);
```

## Dirty Checking

Track which attributes have changed:

```php
$user = User::find(1);
$user->name = 'New Name';

// Check if any attributes changed
if ($user->isDirty()) { ... }

// Check specific attribute
if ($user->isDirty('name')) { ... }

// Check if clean (no changes)
if ($user->isClean()) { ... }

// Get changed attributes
$dirty = $user->getDirty();
// ['name' => 'New Name']

// Get original values
$original = $user->getOriginal();
```

## Model State

```php
$user = new User(['name' => 'John']);

// Check if model exists in database
$user->exists();  // false

$user->save();
$user->exists();  // true

// Get primary key value
$id = $user->getKey();

// Get table name
$table = User::getTable();
```

## Refreshing Models

Reload model data from the database:

```php
$user = User::find(1);
// ... data may have changed in database

$user->refresh();  // Reloads attributes from database
```

## Atomic Operations

Increment or decrement column values atomically:

```php
$post = Post::find(1);

// Increment by 1
$post->increment('view_count');

// Increment by specific amount
$post->increment('view_count', 5);

// Decrement
$post->decrement('stock_count');

// With additional updates
$post->increment('view_count', 1, ['last_viewed_at' => date('Y-m-d H:i:s')]);
```

## Replication

Create a copy of a model without saving:

```php
$user = User::find(1);
$newUser = $user->replicate();

// Modify and save
$newUser->email = 'newemail@example.com';
$newUser->save();
```

## Converting to Array/JSON

```php
$user = User::with('posts')->find(1);

// Convert to array (respects HIDDEN/VISIBLE, includes relationships)
$array = $user->toArray();

// Convert to JSON
$json = $user->toJson();
$json = $user->toJson(JSON_PRETTY_PRINT);

// JsonSerializable interface
echo json_encode($user);
```

## Model Events

Hook into model lifecycle events:

```php
class User extends Model
{
    protected function onCreating(): void
    {
        $this->uuid = generate_uuid();
    }

    protected function onCreated(): void
    {
        // Send welcome email
    }

    protected function onUpdating(): void
    {
        // Validate changes
    }

    protected function onDeleting(): void
    {
        // Clean up related data
    }
}
```

See [Events & Observers](events.md) for more on model events.

## Identity Map

NanoORM uses an identity map to ensure only one instance of each model exists:

```php
$user1 = User::find(1);
$user2 = User::find(1);

$user1 === $user2;  // true - same instance
```

Clear the identity map when needed:

```php
Model::clearIdentityMap();
```
