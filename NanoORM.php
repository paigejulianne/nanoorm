<?php
/**
 * NanoORM - A Lightweight, Full-Featured PHP ORM
 *
 * A single-file PHP ORM (~3,500 lines) that provides an elegant, expressive
 * syntax for database operations. Inspired by Laravel's Eloquent but designed
 * to be lightweight and dependency-free.
 *
 * Features:
 * - Fluent QueryBuilder with chainable methods
 * - Model relationships: HasOne, HasMany, BelongsTo, BelongsToMany
 * - Eager loading to prevent N+1 query problems
 * - Soft deletes and automatic timestamps
 * - Attribute casting (json, boolean, datetime, etc.)
 * - Identity map to prevent duplicate model instances
 * - Schema builder and migration system
 * - Database-agnostic (MySQL, PostgreSQL, SQLite, SQL Server)
 * - Query logging for debugging
 * - Transaction support
 *
 * @version 1.0.0
 * @author Paige Julianne
 * @license MIT
 * @requires PHP 8.1+, PDO
 *
 * @link https://github.com/paigejulianne/nanoorm
 *
 * @example Basic Usage
 * ```php
 * // Define a model
 * class User extends Model {
 *     public const bool TIMESTAMPS = true;
 *     public const bool SOFT_DELETES = true;
 * }
 *
 * // Create and query
 * $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
 * $users = User::where('active', true)->orderBy('name')->get();
 * ```
 */

declare(strict_types=1);

namespace NanoORM;

use PDO;
use PDOStatement;
use PDOException;
use DateTime;
use DateTimeInterface;
use RuntimeException;
use InvalidArgumentException;
use TypeError;
use Throwable;
use ReflectionClass;

// ============================================================================
// NANO ORM - MAIN MODEL CLASS
// ============================================================================

/**
 * Abstract base class for all ORM models.
 *
 * Extend this class to create database-backed models with automatic
 * CRUD operations, relationships, and query building capabilities.
 *
 * @example
 * ```php
 * class User extends Model
 * {
 *     public const string TABLE = 'users';
 *     public const bool TIMESTAMPS = true;
 *     public const bool SOFT_DELETES = true;
 *     public const array CASTS = ['settings' => 'json'];
 *     public const array HIDDEN = ['password'];
 *
 *     public function posts(): HasMany
 *     {
 *         return $this->hasMany(Post::class);
 *     }
 * }
 * ```
 */
abstract class Model
{
    // ========== MODEL CONFIGURATION (Override in subclasses) ==========

    /** @var string|null Custom table name (null = auto from class name) */
    public const ?string TABLE = null;

    /** @var string Primary key column name */
    public const string PRIMARY_KEY = 'id';

    /** @var string Database connection name */
    public const string CONNECTION = 'default';

    /** @var bool Enable automatic type validation */
    public const bool VALIDATE_TYPES = true;

    /** @var bool Enable soft deletes */
    public const bool SOFT_DELETES = false;

    /** @var bool Enable automatic timestamps */
    public const bool TIMESTAMPS = false;

    /** @var string Created timestamp column */
    public const string CREATED_AT = 'created_at';

    /** @var string Updated timestamp column */
    public const string UPDATED_AT = 'updated_at';

    /** @var string Soft delete timestamp column */
    public const string DELETED_AT = 'deleted_at';

    /** @var array<string, string> Attribute casting definitions */
    public const array CASTS = [];

    /** @var array<string> Attributes that should be hidden from array/JSON */
    public const array HIDDEN = [];

    /** @var array<string> Only these attributes in array/JSON (if set) */
    public const array VISIBLE = [];

    /** @var array<string> Attributes that are mass assignable (whitelist) */
    public const array FILLABLE = [];

    /** @var array<string> Attributes that are NOT mass assignable (blacklist) */
    public const array GUARDED = ['*'];

    // ========== INSTANCE STATE ==========

    /** @var array<string, mixed> Current attribute values */
    protected array $attributes = [];

    /** @var array<string, mixed> Original values from database */
    protected array $original = [];

    /** @var array<string, mixed> Loaded relationship data */
    protected array $relations = [];

    /** @var bool Whether this model exists in the database */
    protected bool $exists = false;

    // ========== STATIC CACHES ==========

    /** @var array<string, PDO> PDO instances per connection */
    private static array $connections = [];

    /** @var array<string, array> Connection configurations */
    private static array $configs = [];

    /** @var array<string, array> Schema cache per table */
    private static array $schemas = [];

    /** @var array<string, array<mixed, static>> Identity map */
    private static array $identityMap = [];

    /** @var array<array{sql: string, bindings: array, time: float}> Query log */
    private static array $queryLog = [];

    /** @var bool Query logging enabled */
    private static bool $logging = false;

    /** @var string|null Path to connections file */
    private static ?string $connectionsFile = null;

    /** @var bool Connections loaded flag */
    private static bool $connectionsLoaded = false;

    /** @var string Regex for valid SQL identifiers */
    private const string IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /** @var array<string> Allowed SQL operators */
    private const array ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
        'IN', 'NOT IN', 'IS', 'IS NOT',
        'BETWEEN', 'NOT BETWEEN', 'REGEXP', 'NOT REGEXP'
    ];

    // ========== CONSTRUCTOR ==========

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes Initial attribute values
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ========== STATIC FACTORY METHODS ==========

    /**
     * Create a new model instance and persist it to the database.
     *
     * @param array<string, mixed> $attributes The attributes to set on the model
     * @return static The newly created and persisted model instance
     *
     * @throws RuntimeException If the insert fails
     *
     * @example
     * ```php
     * $user = User::create([
     *     'name' => 'John Doe',
     *     'email' => 'john@example.com',
     * ]);
     * ```
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Insert or update records in bulk (upsert).
     *
     * Inserts new records or updates existing ones based on unique key conflicts.
     * Useful for batch operations where you want to create or update many records
     * in a single query.
     *
     * @param array<array<string, mixed>> $values Array of records to upsert
     * @param array<string>|string $uniqueBy Column(s) to check for conflicts
     * @param array<string>|null $update Columns to update on conflict (null = all)
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * // Upsert products by SKU
     * Product::upsert(
     *     [
     *         ['sku' => 'ABC123', 'name' => 'Widget', 'price' => 9.99],
     *         ['sku' => 'DEF456', 'name' => 'Gadget', 'price' => 19.99],
     *     ],
     *     ['sku'],           // Unique column
     *     ['name', 'price']  // Columns to update on conflict
     * );
     * ```
     */
    public static function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if (empty($values)) {
            return 0;
        }

        $uniqueBy = (array) $uniqueBy;
        $values = array_values($values);
        $first = $values[0];
        $columns = array_keys($first);

        // Columns to update (default: all except unique keys)
        if ($update === null) {
            $update = array_diff($columns, $uniqueBy);
        }

        $pdo = static::getConnection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $table = static::quoteIdentifier(static::getTable());

        // Build column list
        $columnsSql = implode(', ', array_map(fn($c) => static::quoteIdentifier($c), $columns));

        // Build placeholders for all values
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($values), $placeholderRow));

        // Flatten values for binding
        $bindings = [];
        foreach ($values as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        // Build driver-specific SQL
        $sql = "INSERT INTO $table ($columnsSql) VALUES $placeholders";

        if ($driver === 'mysql') {
            // MySQL: ON DUPLICATE KEY UPDATE
            $updates = [];
            foreach ($update as $col) {
                $quoted = static::quoteIdentifier($col);
                $updates[] = "$quoted = VALUES($quoted)";
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        } else {
            // SQLite/PostgreSQL: ON CONFLICT DO UPDATE
            $conflictCols = implode(', ', array_map(fn($c) => static::quoteIdentifier($c), $uniqueBy));
            $updates = [];
            foreach ($update as $col) {
                $quoted = static::quoteIdentifier($col);
                $updates[] = "$quoted = EXCLUDED.$quoted";
            }
            if (!empty($updates)) {
                $sql .= " ON CONFLICT ($conflictCols) DO UPDATE SET " . implode(', ', $updates);
            } else {
                $sql .= " ON CONFLICT ($conflictCols) DO NOTHING";
            }
        }

        return static::executeStatement($sql, $bindings);
    }

    /**
     * Insert records, ignoring any that would cause conflicts.
     *
     * @param array<array<string, mixed>> $values Records to insert
     * @return int Number of inserted rows
     *
     * @example
     * ```php
     * User::insertOrIgnore([
     *     ['email' => 'user1@example.com', 'name' => 'User 1'],
     *     ['email' => 'user2@example.com', 'name' => 'User 2'],
     * ]);
     * ```
     */
    public static function insertOrIgnore(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        $values = array_values($values);
        $first = $values[0];
        $columns = array_keys($first);

        $pdo = static::getConnection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $table = static::quoteIdentifier(static::getTable());

        $columnsSql = implode(', ', array_map(fn($c) => static::quoteIdentifier($c), $columns));
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($values), $placeholderRow));

        $bindings = [];
        foreach ($values as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        if ($driver === 'mysql') {
            $sql = "INSERT IGNORE INTO $table ($columnsSql) VALUES $placeholders";
        } else {
            // SQLite/PostgreSQL
            $sql = "INSERT INTO $table ($columnsSql) VALUES $placeholders ON CONFLICT DO NOTHING";
        }

        return static::executeStatement($sql, $bindings);
    }

    /**
     * Create a model instance from a database row without saving.
     *
     * This method is used internally to create model instances from
     * query results. It populates the identity map to prevent duplicate
     * instances of the same database record.
     *
     * @param array<string, mixed> $attributes The database row as an associative array
     * @return static The hydrated model instance
     */
    public static function hydrate(array $attributes): static
    {
        $primaryKey = static::PRIMARY_KEY;
        $id = $attributes[$primaryKey] ?? null;

        // Check identity map
        if ($id !== null && isset(self::$identityMap[static::class][$id])) {
            $existing = self::$identityMap[static::class][$id];
            $existing->attributes = $attributes;
            $existing->original = $attributes;
            return $existing;
        }

        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = true;

        // Store in identity map
        if ($id !== null) {
            self::$identityMap[static::class][$id] = $model;
        }

        return $model;
    }

    // ========== QUERY STARTERS ==========

    /**
     * Start a new query builder for this model.
     *
     * @return QueryBuilder A new query builder instance
     *
     * @example
     * ```php
     * $users = User::query()
     *     ->where('active', true)
     *     ->orderBy('created_at', 'DESC')
     *     ->limit(10)
     *     ->get();
     * ```
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::class);
    }

    /**
     * Find a model by its primary key.
     *
     * Uses the identity map to return cached instances when available.
     * Returns null if the model is not found or is soft-deleted.
     *
     * @param mixed $id The primary key value
     * @return static|null The model instance or null if not found
     *
     * @example
     * ```php
     * $user = User::find(1);
     * if ($user) {
     *     echo $user->name;
     * }
     * ```
     */
    public static function find(mixed $id): ?static
    {
        // Check identity map first
        if (isset(self::$identityMap[static::class][$id])) {
            $cached = self::$identityMap[static::class][$id];
            // Don't return soft-deleted models from cache
            if (static::SOFT_DELETES && $cached->trashed()) {
                return null;
            }
            return $cached;
        }

        return static::query()->where(static::PRIMARY_KEY, $id)->first();
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param mixed $id The primary key value
     * @return static The model instance
     *
     * @throws RuntimeException If the model is not found
     *
     * @example
     * ```php
     * try {
     *     $user = User::findOrFail(999);
     * } catch (RuntimeException $e) {
     *     echo "User not found!";
     * }
     * ```
     */
    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new RuntimeException("Model not found: " . static::class . " with ID $id");
        }
        return $model;
    }

    /**
     * Find multiple models by their primary keys.
     *
     * @param array<mixed> $ids Array of primary key values
     * @return array<static> Array of found model instances
     *
     * @example
     * ```php
     * $users = User::findMany([1, 2, 3]);
     * ```
     */
    public static function findMany(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection([]);
        }
        return static::query()->whereIn(static::PRIMARY_KEY, $ids)->get();
    }

    /**
     * Get all records from the database.
     *
     * @return Collection<static> Collection of all model instances
     *
     * @example
     * ```php
     * $allUsers = User::all();
     * ```
     */
    public static function all(): Collection
    {
        return static::query()->get();
    }

    /**
     * Count all records in the table.
     *
     * @return int The total number of records
     *
     * @example
     * ```php
     * $totalUsers = User::count();
     * ```
     */
    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Start a query with a WHERE clause.
     *
     * @param string|array<string, mixed>|\Closure $column Column name, array of conditions, or closure for nested conditions
     * @param mixed $operator Comparison operator or value (if operator is omitted)
     * @param mixed $value The value to compare against
     * @return QueryBuilder A query builder with the WHERE clause applied
     *
     * @example
     * ```php
     * // Simple equality
     * $users = User::where('active', true)->get();
     *
     * // With operator
     * $users = User::where('age', '>=', 18)->get();
     *
     * // Array of conditions
     * $users = User::where(['active' => true, 'role' => 'admin'])->get();
     * ```
     */
    public static function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Start a query with a WHERE IN clause.
     *
     * @param string $column The column name
     * @param array<mixed> $values Array of values to match
     * @return QueryBuilder A query builder with the WHERE IN clause applied
     *
     * @example
     * ```php
     * $users = User::whereIn('id', [1, 2, 3])->get();
     * ```
     */
    public static function whereIn(string $column, array $values): QueryBuilder
    {
        return static::query()->whereIn($column, $values);
    }

    /**
     * Start a query with a WHERE NOT IN clause.
     *
     * @param string $column The column name
     * @param array<mixed> $values Array of values to exclude
     * @return QueryBuilder A query builder with the WHERE NOT IN clause applied
     */
    public static function whereNotIn(string $column, array $values): QueryBuilder
    {
        return static::query()->whereNotIn($column, $values);
    }

    /**
     * Start a query with a WHERE NULL clause.
     *
     * @param string $column The column name
     * @return QueryBuilder A query builder with the WHERE NULL clause applied
     */
    public static function whereNull(string $column): QueryBuilder
    {
        return static::query()->whereNull($column);
    }

    /**
     * Start a query with a WHERE NOT NULL clause.
     *
     * @param string $column The column name
     * @return QueryBuilder A query builder with the WHERE NOT NULL clause applied
     */
    public static function whereNotNull(string $column): QueryBuilder
    {
        return static::query()->whereNotNull($column);
    }

    /**
     * Start a query with a WHERE BETWEEN clause.
     *
     * @param string $column The column name
     * @param mixed $min The minimum value
     * @param mixed $max The maximum value
     * @return QueryBuilder A query builder with the WHERE BETWEEN clause applied
     */
    public static function whereBetween(string $column, mixed $min, mixed $max): QueryBuilder
    {
        return static::query()->whereBetween($column, $min, $max);
    }

    /**
     * Start a query with an ORDER BY clause.
     *
     * @param string $column The column to order by
     * @param string $direction Sort direction ('ASC' or 'DESC')
     * @return QueryBuilder A query builder with the ORDER BY clause applied
     */
    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    /**
     * Order by column descending (most recent first).
     *
     * @param string $column The column to order by (default: 'created_at')
     * @return QueryBuilder A query builder with ORDER BY DESC applied
     */
    public static function latest(string $column = 'created_at'): QueryBuilder
    {
        return static::query()->latest($column);
    }

    /**
     * Order by column ascending (oldest first).
     *
     * @param string $column The column to order by (default: 'created_at')
     * @return QueryBuilder A query builder with ORDER BY ASC applied
     */
    public static function oldest(string $column = 'created_at'): QueryBuilder
    {
        return static::query()->oldest($column);
    }

    /**
     * Get an array of values from a single column.
     *
     * @param string $column The column to pluck values from
     * @param string|null $key Optional column to use as array keys
     * @return array<mixed> Array of column values
     *
     * @example
     * ```php
     * // Get all user names
     * $names = User::pluck('name');
     *
     * // Get names keyed by ID
     * $names = User::pluck('name', 'id');
     * ```
     */
    public static function pluck(string $column, ?string $key = null): array
    {
        return static::query()->pluck($column, $key);
    }

    /**
     * Eager load relationships to prevent N+1 queries.
     *
     * @param string|array<string> ...$relations Relationship names to eager load
     * @return QueryBuilder A query builder with eager loading configured
     *
     * @example
     * ```php
     * // Load single relationship
     * $users = User::with('posts')->get();
     *
     * // Load multiple relationships
     * $users = User::with('posts', 'profile')->get();
     *
     * // Load nested relationships
     * $users = User::with('posts.comments')->get();
     * ```
     */
    public static function with(string|array ...$relations): QueryBuilder
    {
        return static::query()->with(...$relations);
    }

    /**
     * Find a record matching the attributes or create a new one.
     *
     * @param array<string, mixed> $search Attributes to search by
     * @param array<string, mixed> $additional Additional attributes when creating
     * @return static The found or newly created model
     *
     * @example
     * ```php
     * $user = User::firstOrCreate(
     *     ['email' => 'john@example.com'],
     *     ['name' => 'John Doe']
     * );
     * ```
     */
    public static function firstOrCreate(array $search, array $additional = []): static
    {
        $model = static::query()->where($search)->first();

        if ($model !== null) {
            return $model;
        }

        return static::create(array_merge($search, $additional));
    }

    /**
     * Update an existing record or create a new one.
     *
     * @param array<string, mixed> $search Attributes to search by
     * @param array<string, mixed> $update Attributes to update or set when creating
     * @return static The updated or newly created model
     *
     * @example
     * ```php
     * $user = User::updateOrCreate(
     *     ['email' => 'john@example.com'],
     *     ['name' => 'John Doe', 'last_login' => now()]
     * );
     * ```
     */
    public static function updateOrCreate(array $search, array $update = []): static
    {
        $model = static::query()->where($search)->first();

        if ($model !== null) {
            $model->fill($update);
            $model->save();
            return $model;
        }

        return static::create(array_merge($search, $update));
    }

    // ========== BULK OPERATIONS ==========

    /**
     * Insert multiple records at once.
     *
     * This is more efficient than calling create() in a loop
     * as it uses a single INSERT statement.
     *
     * @param array<array<string, mixed>> $records Array of records to insert
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * User::insert([
     *     ['name' => 'John', 'email' => 'john@example.com'],
     *     ['name' => 'Jane', 'email' => 'jane@example.com'],
     * ]);
     * ```
     */
    public static function insert(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $table = static::getTable();
        $columns = array_keys($records[0]);

        $columnsSql = implode(', ', array_map(
            fn($c) => self::quoteIdentifier(self::validateIdentifier($c)),
            $columns
        ));

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($records), $rowPlaceholder));

        $bindings = [];
        foreach ($records as $record) {
            foreach ($columns as $col) {
                $bindings[] = $record[$col] ?? null;
            }
        }

        $sql = "INSERT INTO " . self::quoteIdentifier($table) . " ($columnsSql) VALUES $allPlaceholders";

        return self::executeStatement($sql, $bindings);
    }

    /**
     * Insert multiple records and return their IDs.
     *
     * Unlike insert(), this method returns the auto-generated IDs
     * but is less efficient as it uses individual INSERT statements.
     *
     * @param array<array<string, mixed>> $records Array of records to insert
     * @return array<mixed> Array of inserted primary key values
     */
    public static function insertGetIds(array $records): array
    {
        if (empty($records)) {
            return [];
        }

        $pdo = self::getConnection();
        $ids = [];

        $pdo->beginTransaction();
        try {
            foreach ($records as $record) {
                $model = static::create($record);
                $ids[] = $model->getKey();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $ids;
    }

    // ========== ATTRIBUTE ACCESS ==========

    /**
     * Get an attribute value
     */
    public function __get(string $name): mixed
    {
        // Check for relationship method
        if (method_exists($this, $name)) {
            return $this->getRelationValue($name);
        }

        return $this->getAttribute($name);
    }

    /**
     * Set an attribute value
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Check if attribute exists
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) ||
               (method_exists($this, $name) && isset($this->relations[$name]));
    }

    /**
     * Unset an attribute
     */
    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
        unset($this->relations[$name]);
    }

    /**
     * Get attribute with accessor and casting support.
     *
     * Checks for accessor method (get{AttributeName}Attribute) first,
     * then applies casting if defined.
     *
     * @param string $key Attribute name
     * @return mixed Attribute value
     */
    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        // Check for accessor method (e.g., getFullNameAttribute for 'full_name')
        $accessor = 'get' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $accessor)) {
            return $this->$accessor($value);
        }

        // Apply cast if defined
        if (isset(static::CASTS[$key]) && $value !== null) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Set attribute with mutator and reverse casting support.
     *
     * Checks for mutator method (set{AttributeName}Attribute) first,
     * then applies reverse casting if defined.
     *
     * @param string $key Attribute name
     * @param mixed $value Value to set
     * @return static
     */
    public function setAttribute(string $key, mixed $value): static
    {
        // Check for mutator method (e.g., setPasswordAttribute for 'password')
        $mutator = 'set' . str_replace('_', '', ucwords($key, '_')) . 'Attribute';
        if (method_exists($this, $mutator)) {
            $value = $this->$mutator($value);
        }

        // Apply reverse cast if defined
        if (isset(static::CASTS[$key]) && $value !== null) {
            $value = $this->uncastAttribute($key, $value);
        }

        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Fill multiple attributes with mass assignment protection.
     *
     * Only fills attributes that are mass assignable based on
     * FILLABLE (whitelist) or GUARDED (blacklist) constants.
     *
     * @param array $attributes Attributes to fill
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    /**
     * Fill attributes without mass assignment protection.
     *
     * Use this when you need to bypass fillable/guarded checks,
     * such as when hydrating from database.
     *
     * @param array $attributes Attributes to fill
     * @return static
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    /**
     * Check if an attribute is mass assignable.
     *
     * @param string $key Attribute name
     * @return bool True if the attribute can be mass assigned
     */
    protected function isFillable(string $key): bool
    {
        // If FILLABLE is defined (non-empty), use whitelist mode
        if (!empty(static::FILLABLE)) {
            return in_array($key, static::FILLABLE, true);
        }

        // If GUARDED is ['*'], nothing is fillable (default secure)
        if (static::GUARDED === ['*']) {
            return true; // For backward compatibility, allow all by default
        }

        // Otherwise, check if key is NOT in guarded list
        return !in_array($key, static::GUARDED, true);
    }

    /**
     * Get all fillable attributes.
     *
     * @return array<string> List of fillable attribute names
     */
    public function getFillable(): array
    {
        return static::FILLABLE;
    }

    /**
     * Get all guarded attributes.
     *
     * @return array<string> List of guarded attribute names
     */
    public function getGuarded(): array
    {
        return static::GUARDED;
    }

    /**
     * Get primary key value
     */
    public function getKey(): mixed
    {
        return $this->attributes[static::PRIMARY_KEY] ?? null;
    }

    /**
     * Get table name
     */
    public static function getTable(): string
    {
        if (static::TABLE !== null) {
            return static::TABLE;
        }

        // Convert ClassName to table_names (snake_case + pluralize)
        $class = (new ReflectionClass(static::class))->getShortName();
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        return match (true) {
            str_ends_with($snake, 'y') && !str_ends_with($snake, 'ay') && !str_ends_with($snake, 'ey') && !str_ends_with($snake, 'oy') && !str_ends_with($snake, 'uy')
                => substr($snake, 0, -1) . 'ies',
            str_ends_with($snake, 's') || str_ends_with($snake, 'x') || str_ends_with($snake, 'ch') || str_ends_with($snake, 'sh')
                => $snake . 'es',
            default => $snake . 's',
        };
    }

    // ========== PERSISTENCE ==========

    /**
     * Save the model to the database
     */
    public function save(): bool
    {
        $this->fireEvent('saving');

        if ($this->exists) {
            $result = $this->performUpdate();
        } else {
            $result = $this->performInsert();
        }

        $this->fireEvent('saved');

        // Sync original
        $this->original = $this->attributes;

        return $result;
    }

    /**
     * Perform an insert operation
     */
    protected function performInsert(): bool
    {
        $this->fireEvent('creating');

        // Set timestamps
        if (static::TIMESTAMPS) {
            $now = date('Y-m-d H:i:s');
            if (!isset($this->attributes[static::CREATED_AT])) {
                $this->attributes[static::CREATED_AT] = $now;
            }
            $this->attributes[static::UPDATED_AT] = $now;
        }

        $table = static::getTable();
        $attributes = $this->attributes;

        // Remove primary key if null (auto-increment)
        if (($attributes[static::PRIMARY_KEY] ?? null) === null) {
            unset($attributes[static::PRIMARY_KEY]);
        }

        if (empty($attributes)) {
            throw new RuntimeException("Cannot insert empty record");
        }

        $columns = array_keys($attributes);
        $columnsSql = implode(', ', array_map(
            fn($c) => self::quoteIdentifier(self::validateIdentifier($c)),
            $columns
        ));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO " . self::quoteIdentifier($table) . " ($columnsSql) VALUES ($placeholders)";

        $pdo = self::getConnection();
        $stmt = self::prepareAndExecute($sql, array_values($attributes));

        // Get auto-increment ID
        $id = $pdo->lastInsertId();
        if ($id && !isset($this->attributes[static::PRIMARY_KEY])) {
            $this->attributes[static::PRIMARY_KEY] = is_numeric($id) ? (int) $id : $id;
        }

        $this->exists = true;

        // Store in identity map
        $key = $this->getKey();
        if ($key !== null) {
            self::$identityMap[static::class][$key] = $this;
        }

        $this->fireEvent('created');

        return true;
    }

    /**
     * Perform an update operation
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true; // Nothing to update
        }

        $this->fireEvent('updating');

        // Update timestamp
        if (static::TIMESTAMPS) {
            $dirty[static::UPDATED_AT] = date('Y-m-d H:i:s');
            $this->attributes[static::UPDATED_AT] = $dirty[static::UPDATED_AT];
        }

        $table = static::getTable();
        $sets = [];
        $bindings = [];

        foreach ($dirty as $column => $value) {
            $sets[] = self::quoteIdentifier(self::validateIdentifier($column)) . ' = ?';
            $bindings[] = $value;
        }

        $bindings[] = $this->getKey();

        $sql = "UPDATE " . self::quoteIdentifier($table) . " SET " . implode(', ', $sets)
             . " WHERE " . self::quoteIdentifier(static::PRIMARY_KEY) . " = ?";

        self::prepareAndExecute($sql, $bindings);

        $this->fireEvent('updated');

        return true;
    }

    /**
     * Delete the model
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $this->fireEvent('deleting');

        if (static::SOFT_DELETES) {
            $this->attributes[static::DELETED_AT] = date('Y-m-d H:i:s');
            $result = $this->performUpdate();
        } else {
            $result = $this->performDelete();
        }

        $this->fireEvent('deleted');

        return $result;
    }

    /**
     * Perform actual delete
     */
    protected function performDelete(): bool
    {
        $table = static::getTable();
        $sql = "DELETE FROM " . self::quoteIdentifier($table)
             . " WHERE " . self::quoteIdentifier(static::PRIMARY_KEY) . " = ?";

        self::prepareAndExecute($sql, [$this->getKey()]);

        // Remove from identity map
        $key = $this->getKey();
        if ($key !== null) {
            unset(self::$identityMap[static::class][$key]);
        }

        $this->exists = false;

        return true;
    }

    /**
     * Force delete (bypass soft deletes)
     */
    public function forceDelete(): bool
    {
        $this->fireEvent('forceDeleting');
        $result = $this->performDelete();
        $this->fireEvent('forceDeleted');
        return $result;
    }

    /**
     * Restore a soft-deleted model
     */
    public function restore(): bool
    {
        if (!static::SOFT_DELETES) {
            throw new RuntimeException("Model does not use soft deletes");
        }

        $this->fireEvent('restoring');

        $this->attributes[static::DELETED_AT] = null;
        $result = $this->performUpdate();

        $this->fireEvent('restored');

        return $result;
    }

    /**
     * Check if model is soft-deleted
     */
    public function trashed(): bool
    {
        if (!static::SOFT_DELETES) {
            return false;
        }
        return ($this->attributes[static::DELETED_AT] ?? null) !== null;
    }

    /**
     * Refresh model from database
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = static::query()->withTrashed()->find($this->getKey());

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
            $this->relations = [];
        }

        return $this;
    }

    // ========== DIRTY CHECKING ==========

    /**
     * Check if model has unsaved changes
     */
    public function isDirty(?string $attribute = null): bool
    {
        $dirty = $this->getDirty();

        if ($attribute === null) {
            return !empty($dirty);
        }

        return array_key_exists($attribute, $dirty);
    }

    /**
     * Check if model has no unsaved changes
     */
    public function isClean(?string $attribute = null): bool
    {
        return !$this->isDirty($attribute);
    }

    /**
     * Get dirty attributes
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Get original attribute value(s)
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->original;
        }
        return $this->original[$key] ?? null;
    }

    // ========== ATOMIC OPERATIONS ==========

    /**
     * Increment a column value
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): bool
    {
        $column = self::validateIdentifier($column);
        $table = static::getTable();

        $sets = [self::quoteIdentifier($column) . ' = ' . self::quoteIdentifier($column) . ' + ?'];
        $bindings = [$amount];

        // Handle extra columns
        foreach ($extra as $col => $val) {
            $sets[] = self::quoteIdentifier(self::validateIdentifier($col)) . ' = ?';
            $bindings[] = $val;
        }

        // Update timestamp
        if (static::TIMESTAMPS) {
            $sets[] = self::quoteIdentifier(static::UPDATED_AT) . ' = ?';
            $bindings[] = date('Y-m-d H:i:s');
        }

        $bindings[] = $this->getKey();

        $sql = "UPDATE " . self::quoteIdentifier($table) . " SET " . implode(', ', $sets)
             . " WHERE " . self::quoteIdentifier(static::PRIMARY_KEY) . " = ?";

        self::prepareAndExecute($sql, $bindings);

        // Update local value
        $this->attributes[$column] = ($this->attributes[$column] ?? 0) + $amount;
        $this->original[$column] = $this->attributes[$column];

        return true;
    }

    /**
     * Decrement a column value
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): bool
    {
        return $this->increment($column, -$amount, $extra);
    }

    // ========== RELATIONSHIPS ==========

    /**
     * Get a relationship value, loading it lazily if not already loaded.
     *
     * This method is called automatically when accessing a relationship
     * as a property (e.g., $user->posts).
     *
     * @param string $name The relationship method name
     * @return mixed The related model(s) or null
     *
     * @throws RuntimeException If the method does not return a Relation instance
     */
    protected function getRelationValue(string $name): mixed
    {
        // Return cached relation
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // Call relationship method
        $relation = $this->$name();

        if (!$relation instanceof Relation) {
            throw new RuntimeException("Method $name must return a Relation instance");
        }

        $this->relations[$name] = $relation->getResults();
        return $this->relations[$name];
    }

    /**
     * Manually set a relationship value on the model.
     *
     * This is primarily used internally during eager loading.
     *
     * @param string $name The relationship name
     * @param mixed $value The related model(s)
     * @return static
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    /**
     * Lazy load relationships on an existing model.
     *
     * @param string|array<string> ...$relations Relationship names to load
     * @return static
     *
     * @example
     * ```php
     * $user = User::find(1);
     * $user->load('posts', 'profile');
     * ```
     */
    public function load(string|array ...$relations): static
    {
        $relations = is_array($relations[0] ?? null) ? $relations[0] : $relations;

        foreach ($relations as $relation) {
            $this->getRelationValue($relation);
        }

        return $this;
    }

    /**
     * Define a one-to-one relationship.
     *
     * Use this when the related model has a foreign key pointing to this model.
     *
     * @param string $related The related model class name
     * @param string|null $foreignKey The foreign key on the related model (default: {table}_id)
     * @param string|null $localKey The local key on this model (default: primary key)
     * @return HasOne The relationship instance
     *
     * @example
     * ```php
     * // In User model
     * public function profile(): HasOne
     * {
     *     return $this->hasOne(Profile::class);
     * }
     * ```
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::PRIMARY_KEY;

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     *
     * Use this when multiple related models have foreign keys pointing to this model.
     *
     * @param string $related The related model class name
     * @param string|null $foreignKey The foreign key on the related model (default: {table}_id)
     * @param string|null $localKey The local key on this model (default: primary key)
     * @return HasMany The relationship instance
     *
     * @example
     * ```php
     * // In User model
     * public function posts(): HasMany
     * {
     *     return $this->hasMany(Post::class);
     * }
     * ```
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::PRIMARY_KEY;

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     *
     * Use this when this model has a foreign key pointing to the related model.
     *
     * @param string $related The related model class name
     * @param string|null $foreignKey The foreign key on this model (default: {related_table}_id)
     * @param string|null $ownerKey The key on the related model (default: primary key)
     * @return BelongsTo The relationship instance
     *
     * @example
     * ```php
     * // In Post model
     * public function author(): BelongsTo
     * {
     *     return $this->belongsTo(User::class, 'user_id');
     * }
     * ```
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey ??= $this->guessBelongsToForeignKey($related);
        $ownerKey ??= $related::PRIMARY_KEY;

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many relationship.
     *
     * This requires a pivot table containing foreign keys to both models.
     *
     * @param string $related The related model class name
     * @param string|null $pivotTable The pivot table name (default: alphabetically sorted singular table names joined with _)
     * @param string|null $foreignPivotKey This model's foreign key in the pivot table
     * @param string|null $relatedPivotKey Related model's foreign key in the pivot table
     * @return BelongsToMany The relationship instance
     *
     * @example
     * ```php
     * // In Post model (pivot table: post_tag)
     * public function tags(): BelongsToMany
     * {
     *     return $this->belongsToMany(Tag::class);
     * }
     * ```
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null
    ): BelongsToMany {
        if ($pivotTable === null) {
            $tables = [static::getTable(), $related::getTable()];
            sort($tables);
            $pivotTable = implode('_', array_map(fn($t) => rtrim($t, 's'), $tables));
        }

        $foreignPivotKey ??= $this->getForeignKey();
        $relatedPivotKey ??= (new $related())->getForeignKey();

        return new BelongsToMany($this, $related, $pivotTable, $foreignPivotKey, $relatedPivotKey);
    }

    /**
     * Get the default foreign key name for this model.
     *
     * @return string The foreign key name (e.g., 'user_id' for the 'users' table)
     */
    public function getForeignKey(): string
    {
        $table = static::getTable();
        return rtrim($table, 's') . '_id';
    }

    /**
     * Guess the foreign key for a belongsTo relationship.
     *
     * @param string $related The related model class name
     * @return string The guessed foreign key name
     */
    protected function guessBelongsToForeignKey(string $related): string
    {
        return (new $related())->getForeignKey();
    }

    // ========== CASTING ==========

    /**
     * Cast an attribute to its proper type
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $cast = static::CASTS[$key];

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            'object' => is_string($value) ? json_decode($value) : (object) $value,
            'datetime', 'date' => $value instanceof DateTimeInterface ? $value : new DateTime($value),
            'timestamp' => is_numeric($value) ? (int) $value : strtotime($value),
            default => $value,
        };
    }

    /**
     * Convert attribute for storage
     */
    protected function uncastAttribute(string $key, mixed $value): mixed
    {
        $cast = static::CASTS[$key];

        return match ($cast) {
            'array', 'json', 'object' => is_string($value) ? $value : json_encode($value),
            'datetime', 'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            default => $value,
        };
    }

    // ========== SERIALIZATION ==========

    /** @var array Track models being serialized to prevent infinite loops */
    private static array $serializingModels = [];

    /**
     * Convert model to array
     */
    public function toArray(): array
    {
        // Prevent infinite recursion from circular references
        $objectId = spl_object_id($this);
        if (isset(self::$serializingModels[$objectId])) {
            return ['id' => $this->getKey()]; // Return minimal reference
        }
        self::$serializingModels[$objectId] = true;

        try {
            $attributes = $this->attributes;

            // Apply casts
            foreach (static::CASTS as $key => $cast) {
                if (isset($attributes[$key])) {
                    $attributes[$key] = $this->castAttribute($key, $attributes[$key]);
                }
            }

            // Handle datetime objects
            foreach ($attributes as $key => $value) {
                if ($value instanceof DateTimeInterface) {
                    $attributes[$key] = $value->format('Y-m-d H:i:s');
                }
            }

            // Apply hidden
            if (!empty(static::HIDDEN)) {
                $attributes = array_diff_key($attributes, array_flip(static::HIDDEN));
            }

            // Apply visible
            if (!empty(static::VISIBLE)) {
                $attributes = array_intersect_key($attributes, array_flip(static::VISIBLE));
            }

            // Add loaded relations
            foreach ($this->relations as $key => $relation) {
                if ($relation instanceof Collection) {
                    $attributes[$key] = $relation->map(fn($m) => $m->toArray())->toArray();
                } elseif (is_array($relation)) {
                    $attributes[$key] = array_map(fn($m) => $m->toArray(), $relation);
                } elseif ($relation instanceof self) {
                    $attributes[$key] = $relation->toArray();
                } else {
                    $attributes[$key] = $relation;
                }
            }

            return $attributes;
        } finally {
            unset(self::$serializingModels[$objectId]);
        }
    }

    /**
     * Convert model to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    // ========== EVENTS ==========

    /** @var array<string, array<callable>> Event listeners */
    private static array $eventListeners = [];

    /**
     * Register an event listener
     */
    public static function on(string $event, callable $callback): void
    {
        self::$eventListeners[static::class][$event][] = $callback;
    }

    /**
     * Fire an event
     */
    protected function fireEvent(string $event): void
    {
        // Call model method if exists (e.g., onCreating, onSaved)
        $method = 'on' . ucfirst($event);
        if (method_exists($this, $method)) {
            $this->$method();
        }

        // Call registered observers
        $observers = self::$observers[static::class] ?? [];
        foreach ($observers as $observer) {
            if (method_exists($observer, $event)) {
                $observer->$event($this);
            }
        }

        // Call registered event listeners
        $listeners = self::$eventListeners[static::class][$event] ?? [];
        foreach ($listeners as $listener) {
            $listener($this);
        }
    }

    // ========== GLOBAL SCOPES ==========

    /** @var array<string, array<string, Closure>> Global scopes per model */
    private static array $globalScopes = [];

    /**
     * Add a global scope to the model.
     *
     * Global scopes are automatically applied to all queries for this model.
     * Common uses include filtering by tenant, active status, or access control.
     *
     * @param string $name Unique name for the scope
     * @param Closure $scope Closure that receives a QueryBuilder
     *
     * @example
     * ```php
     * // Only show active users
     * User::addGlobalScope('active', fn($q) => $q->where('active', true));
     *
     * // Multi-tenancy scope
     * User::addGlobalScope('tenant', fn($q) => $q->where('tenant_id', $currentTenant));
     * ```
     */
    public static function addGlobalScope(string $name, \Closure $scope): void
    {
        self::$globalScopes[static::class][$name] = $scope;
    }

    /**
     * Remove a global scope from the model.
     *
     * @param string $name Scope name to remove
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(self::$globalScopes[static::class][$name]);
    }

    /**
     * Get all global scopes for this model.
     *
     * @return array<string, Closure>
     */
    public static function getGlobalScopes(): array
    {
        return self::$globalScopes[static::class] ?? [];
    }

    /**
     * Clear all global scopes for this model.
     */
    public static function clearGlobalScopes(): void
    {
        self::$globalScopes[static::class] = [];
    }

    // ========== MODEL OBSERVERS ==========

    /** @var array<string, array<object>> Registered observers per model */
    private static array $observers = [];

    /**
     * Register an observer for this model.
     *
     * Observers group event handling methods in a single class.
     * The observer should have methods named after lifecycle events:
     * creating, created, updating, updated, saving, saved, deleting, deleted,
     * restoring, restored, forceDeleting, forceDeleted.
     *
     * @param string|object $observer Observer class name or instance
     *
     * @example
     * ```php
     * class UserObserver {
     *     public function creating(User $user): void {
     *         $user->uuid = Uuid::generate();
     *     }
     *
     *     public function saved(User $user): void {
     *         Cache::forget("user:{$user->id}");
     *     }
     * }
     *
     * User::observe(UserObserver::class);
     * // or
     * User::observe(new UserObserver());
     * ```
     */
    public static function observe(string|object $observer): void
    {
        if (is_string($observer)) {
            $observer = new $observer();
        }
        self::$observers[static::class][] = $observer;
    }

    /**
     * Get all observers for this model.
     *
     * @return array<object>
     */
    public static function getObservers(): array
    {
        return self::$observers[static::class] ?? [];
    }

    /**
     * Clear all observers for this model.
     */
    public static function clearObservers(): void
    {
        self::$observers[static::class] = [];
    }

    // ========== CONNECTION MANAGEMENT ==========

    /**
     * Get PDO connection
     */
    public static function getConnection(?string $name = null): PDO
    {
        $name ??= static::CONNECTION;

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        self::loadConnections();

        if (!isset(self::$configs[$name])) {
            throw new RuntimeException("Database connection '$name' not configured");
        }

        $config = self::$configs[$name];

        $pdo = new PDO(
            $config['dsn'],
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['options'] ?? []
        );

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        self::$connections[$name] = $pdo;
        return $pdo;
    }

    /**
     * Add a connection configuration
     */
    public static function addConnection(
        string $name,
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = []
    ): void {
        self::$configs[$name] = compact('dsn', 'username', 'password', 'options');
        unset(self::$connections[$name]);
    }

    /**
     * Set connections file path
     */
    public static function setConnectionsFile(string $path): void
    {
        self::$connectionsFile = $path;
        self::$connectionsLoaded = false;
    }

    /**
     * Load connections from file
     */
    private static function loadConnections(): void
    {
        if (self::$connectionsLoaded) {
            return;
        }

        self::$connectionsLoaded = true;

        $paths = [
            self::$connectionsFile,
            getcwd() . '/.connections',
            dirname(getcwd()) . '/.connections',
            $_SERVER['HOME'] ?? null ? $_SERVER['HOME'] . '/.nanoorm_connections' : null,
        ];

        foreach ($paths as $path) {
            if ($path && file_exists($path)) {
                self::parseConnectionsFile($path);
                return;
            }
        }
    }

    /**
     * Parse connections file
     */
    private static function parseConnectionsFile(string $path): void
    {
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $line, $matches)) {
                $current = $matches[1];
                self::$configs[$current] = ['options' => []];
                continue;
            }

            if ($current && str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Handle OPTIONS[...] format
                if (preg_match('/^OPTIONS\[(.+)\]$/', $key, $matches)) {
                    $optKey = $matches[1];
                    if (defined($optKey)) {
                        self::$configs[$current]['options'][constant($optKey)] = $value;
                    }
                    continue;
                }

                $mapped = match (strtoupper($key)) {
                    'DSN' => 'dsn',
                    'USER', 'USERNAME' => 'username',
                    'PASS', 'PASSWORD' => 'password',
                    default => strtolower($key),
                };

                self::$configs[$current][$mapped] = $value;
            }
        }
    }

    // ========== QUERY EXECUTION ==========

    /**
     * Prepare and execute a statement
     */
    public static function prepareAndExecute(string $sql, array $bindings = []): PDOStatement
    {
        $start = microtime(true);

        $pdo = self::getConnection(static::CONNECTION);
        $stmt = $pdo->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException("Failed to prepare statement: $sql");
        }

        $stmt->execute($bindings);

        if (self::$logging) {
            self::$queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => round((microtime(true) - $start) * 1000, 2),
            ];
        }

        return $stmt;
    }

    /**
     * Execute a statement and return affected rows
     */
    public static function executeStatement(string $sql, array $bindings = []): int
    {
        $stmt = self::prepareAndExecute($sql, $bindings);
        return $stmt->rowCount();
    }

    /**
     * Execute a query and return results
     */
    public static function executeQuery(string $sql, array $bindings = []): array
    {
        $stmt = self::prepareAndExecute($sql, $bindings);
        return $stmt->fetchAll();
    }

    // ========== VALIDATION ==========

    /**
     * Validate a SQL identifier
     */
    public static function validateIdentifier(string $identifier): string
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new InvalidArgumentException("Invalid SQL identifier: '$identifier'");
        }
        return $identifier;
    }

    /**
     * Validate a SQL operator
     */
    public static function validateOperator(string $operator): string
    {
        $upper = strtoupper(trim($operator));
        if (!in_array($upper, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid SQL operator: '$operator'");
        }
        return $upper;
    }

    /**
     * Quote a SQL identifier
     */
    public static function quoteIdentifier(string $identifier, ?string $connection = null): string
    {
        $pdo = self::getConnection($connection ?? static::CONNECTION);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => "`$identifier`",
            'pgsql', 'sqlite' => "\"$identifier\"",
            'sqlsrv', 'mssql', 'dblib' => "[$identifier]",
            default => "\"$identifier\"",
        };
    }

    // ========== TRANSACTIONS ==========

    /**
     * Begin a database transaction.
     *
     * @return bool True on success
     *
     * @example
     * ```php
     * Model::beginTransaction();
     * try {
     *     User::create(['name' => 'John']);
     *     Profile::create(['user_id' => 1]);
     *     Model::commit();
     * } catch (Exception $e) {
     *     Model::rollback();
     * }
     * ```
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection(static::CONNECTION)->beginTransaction();
    }

    /**
     * Commit the current database transaction.
     *
     * @return bool True on success
     */
    public static function commit(): bool
    {
        return self::getConnection(static::CONNECTION)->commit();
    }

    /**
     * Rollback the current database transaction.
     *
     * @return bool True on success
     */
    public static function rollback(): bool
    {
        return self::getConnection(static::CONNECTION)->rollBack();
    }

    /**
     * Execute a callback within a database transaction.
     *
     * If the callback throws an exception, the transaction is automatically
     * rolled back. Otherwise, it is committed.
     *
     * @param callable $callback The callback to execute (receives PDO instance)
     * @return mixed The return value of the callback
     *
     * @throws Throwable Re-throws any exception from the callback after rollback
     *
     * @example
     * ```php
     * Model::transaction(function ($pdo) {
     *     $user = User::create(['name' => 'John']);
     *     Profile::create(['user_id' => $user->id]);
     *     return $user;
     * });
     * ```
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getConnection(static::CONNECTION);
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ========== DEBUGGING ==========

    /**
     * Enable query logging
     */
    public static function enableQueryLog(): void
    {
        self::$logging = true;
    }

    /**
     * Disable query logging
     */
    public static function disableQueryLog(): void
    {
        self::$logging = false;
    }

    /**
     * Get query log
     */
    public static function getQueryLog(): array
    {
        return self::$queryLog;
    }

    /**
     * Flush query log
     */
    public static function flushQueryLog(): void
    {
        self::$queryLog = [];
    }

    /**
     * Clear identity map
     */
    public static function clearIdentityMap(?string $class = null): void
    {
        if ($class === null) {
            self::$identityMap = [];
        } else {
            unset(self::$identityMap[$class]);
        }
    }

    /**
     * Check if model exists in database
     */
    public function exists(): bool
    {
        return $this->exists;
    }
}

// ============================================================================
// QUERY BUILDER
// ============================================================================

/**
 * Fluent query builder for constructing and executing database queries.
 *
 * The QueryBuilder provides a chainable API for building SELECT, UPDATE, and
 * DELETE queries with support for WHERE clauses, ordering, pagination, and
 * eager loading of relationships.
 *
 * Typically you don't instantiate this class directly - use Model::query()
 * or the static query methods on your model classes.
 *
 * @example
 * ```php
 * // Complex query with multiple conditions
 * $posts = Post::query()
 *     ->where('is_published', true)
 *     ->where('created_at', '>', '2024-01-01')
 *     ->whereIn('category_id', [1, 2, 3])
 *     ->with('author', 'comments')
 *     ->orderBy('created_at', 'DESC')
 *     ->limit(10)
 *     ->get();
 *
 * // Pagination
 * $page = User::query()
 *     ->where('active', true)
 *     ->orderBy('name')
 *     ->paginate(perPage: 15, page: 1);
 * ```
 */
class QueryBuilder
{
    /** @var string The model class being queried */
    private string $model;

    /** @var array<array> WHERE clause definitions */
    private array $wheres = [];

    /** @var array<array> ORDER BY clause definitions */
    private array $orderBys = [];

    /** @var int|null LIMIT value */
    private ?int $limitValue = null;

    /** @var int|null OFFSET value */
    private ?int $offsetValue = null;

    /** @var array<string> Columns to SELECT */
    private array $columns = ['*'];

    /** @var array<string> GROUP BY columns */
    private array $groupBy = [];

    /** @var array<array> HAVING clause definitions */
    private array $having = [];

    /** @var array<array> JOIN clause definitions */
    private array $joins = [];

    /** @var array<array> UNION query definitions */
    private array $unions = [];

    /** @var string|null Row locking clause */
    private ?string $lock = null;

    /** @var bool Include soft-deleted records */
    private bool $withTrashed = false;

    /** @var bool Only return soft-deleted records */
    private bool $onlyTrashed = false;

    /** @var array<string> Relationships to eager load */
    private array $eagerLoad = [];

    /** @var array<string> Global scopes to exclude from this query */
    private array $removedScopes = [];

    /** @var bool Whether to apply global scopes */
    private bool $applyGlobalScopes = true;

    /** @var bool Whether global scopes have already been applied */
    private bool $globalScopesApplied = false;

    /**
     * Create a new query builder for the given model.
     *
     * @param string $model The fully-qualified model class name
     */
    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * Remove a specific global scope from this query.
     *
     * @param string $scope Name of the global scope to remove
     * @return static
     *
     * @example
     * ```php
     * // Query without the 'active' scope
     * User::query()->withoutGlobalScope('active')->get();
     * ```
     */
    public function withoutGlobalScope(string $scope): static
    {
        $this->removedScopes[] = $scope;
        return $this;
    }

    /**
     * Remove multiple global scopes from this query.
     *
     * @param array<string>|null $scopes Scope names to remove, or null for all
     * @return static
     *
     * @example
     * ```php
     * // Remove specific scopes
     * User::query()->withoutGlobalScopes(['active', 'tenant'])->get();
     *
     * // Remove all global scopes
     * User::query()->withoutGlobalScopes()->get();
     * ```
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if ($scopes === null) {
            $this->applyGlobalScopes = false;
        } else {
            $this->removedScopes = array_merge($this->removedScopes, $scopes);
        }
        return $this;
    }

    /**
     * Apply global scopes to this query.
     */
    private function applyGlobalScopesToQuery(): void
    {
        if ($this->globalScopesApplied || !$this->applyGlobalScopes) {
            return;
        }

        $this->globalScopesApplied = true;

        $model = $this->model;
        $scopes = $model::getGlobalScopes();

        foreach ($scopes as $name => $scope) {
            if (!in_array($name, $this->removedScopes, true)) {
                $scope($this);
            }
        }
    }

    /**
     * Handle dynamic method calls for local scopes.
     *
     * Local scopes are methods on the model prefixed with 'scope'.
     * For example, calling `->active()` on query will call `scopeActive()` on model.
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed
     *
     * @throws \BadMethodCallException If method doesn't exist
     *
     * @example
     * ```php
     * // In User model:
     * public function scopeActive(QueryBuilder $query): QueryBuilder
     * {
     *     return $query->where('active', true);
     * }
     *
     * public function scopeOfRole(QueryBuilder $query, string $role): QueryBuilder
     * {
     *     return $query->where('role', $role);
     * }
     *
     * // Usage:
     * User::query()->active()->ofRole('admin')->get();
     * ```
     */
    public function __call(string $method, array $args): mixed
    {
        // Check for scope method on model (e.g., scopeActive for ->active())
        $scopeMethod = 'scope' . ucfirst($method);

        if (method_exists($this->model, $scopeMethod)) {
            // Create model instance to call scope
            $modelInstance = new $this->model();
            $result = $modelInstance->$scopeMethod($this, ...$args);

            // Scopes should return the query builder
            return $result instanceof static ? $result : $this;
        }

        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist.', static::class, $method)
        );
    }

    // ========== WHERE CLAUSES ==========

    /**
     * Add a WHERE clause
     */
    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where($key, '=', $val);
            }
            return $this;
        }

        if ($column instanceof \Closure) {
            $nested = new static($this->model);
            $column($nested);
            $this->wheres[] = ['type' => 'nested', 'query' => $nested, 'boolean' => 'AND'];
            return $this;
        }

        // Two arguments: where('column', 'value') means =
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => Model::validateIdentifier($column),
            'operator' => Model::validateOperator($operator),
            'value' => $value,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add an OR WHERE clause
     */
    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->orWhere($key, '=', $val);
            }
            return $this;
        }

        if ($column instanceof \Closure) {
            $nested = new static($this->model);
            $column($nested);
            $this->wheres[] = ['type' => 'nested', 'query' => $nested, 'boolean' => 'OR'];
            return $this;
        }

        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => Model::validateIdentifier($column),
            'operator' => Model::validateOperator($operator),
            'value' => $value,
            'boolean' => 'OR',
        ];

        return $this;
    }

    /**
     * Add a WHERE IN clause
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => Model::validateIdentifier($column),
            'values' => $values,
            'not' => false,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE NOT IN clause
     */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => Model::validateIdentifier($column),
            'values' => $values,
            'not' => true,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE NULL clause
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => Model::validateIdentifier($column),
            'not' => false,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => Model::validateIdentifier($column),
            'not' => true,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause
     */
    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => Model::validateIdentifier($column),
            'min' => $min,
            'max' => $max,
            'not' => false,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => Model::validateIdentifier($column),
            'min' => $min,
            'max' => $max,
            'not' => true,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a raw WHERE clause
     */
    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'bindings' => $bindings,
            'boolean' => 'AND',
        ];
        return $this;
    }

    // ========== SUBQUERIES ==========

    /**
     * Add a WHERE EXISTS clause.
     *
     * @param Closure|QueryBuilder $query Subquery or closure that receives a QueryBuilder
     * @return static
     *
     * @example
     * ```php
     * // Find users who have posts
     * User::query()
     *     ->whereExists(fn($q) => $q->from('posts')->whereColumn('posts.user_id', 'users.id'))
     *     ->get();
     * ```
     */
    public function whereExists(\Closure|QueryBuilder $query): static
    {
        return $this->addExistsClause($query, 'AND', false);
    }

    /**
     * Add an OR WHERE EXISTS clause.
     */
    public function orWhereExists(\Closure|QueryBuilder $query): static
    {
        return $this->addExistsClause($query, 'OR', false);
    }

    /**
     * Add a WHERE NOT EXISTS clause.
     *
     * @param Closure|QueryBuilder $query Subquery or closure that receives a QueryBuilder
     * @return static
     *
     * @example
     * ```php
     * // Find users who have no posts
     * User::query()
     *     ->whereNotExists(fn($q) => $q->from('posts')->whereColumn('posts.user_id', 'users.id'))
     *     ->get();
     * ```
     */
    public function whereNotExists(\Closure|QueryBuilder $query): static
    {
        return $this->addExistsClause($query, 'AND', true);
    }

    /**
     * Add an OR WHERE NOT EXISTS clause.
     */
    public function orWhereNotExists(\Closure|QueryBuilder $query): static
    {
        return $this->addExistsClause($query, 'OR', true);
    }

    /**
     * Add EXISTS clause helper.
     */
    private function addExistsClause(\Closure|QueryBuilder $query, string $boolean, bool $not): static
    {
        if ($query instanceof \Closure) {
            $subQuery = new QueryBuilder($this->model);
            $query($subQuery);
        } else {
            $subQuery = $query;
        }

        $this->wheres[] = [
            'type' => 'exists',
            'query' => $subQuery,
            'not' => $not,
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * Add a WHERE IN subquery clause.
     *
     * @param string $column Column to compare
     * @param Closure|QueryBuilder $query Subquery that returns single column values
     * @return static
     *
     * @example
     * ```php
     * // Find users whose ID is in the posts table
     * User::query()
     *     ->whereInSubquery('id', fn($q) => $q->from('posts')->select('user_id'))
     *     ->get();
     * ```
     */
    public function whereInSubquery(string $column, \Closure|QueryBuilder $query): static
    {
        return $this->addInSubqueryClause($column, $query, 'AND', false);
    }

    /**
     * Add an OR WHERE IN subquery clause.
     */
    public function orWhereInSubquery(string $column, \Closure|QueryBuilder $query): static
    {
        return $this->addInSubqueryClause($column, $query, 'OR', false);
    }

    /**
     * Add a WHERE NOT IN subquery clause.
     */
    public function whereNotInSubquery(string $column, \Closure|QueryBuilder $query): static
    {
        return $this->addInSubqueryClause($column, $query, 'AND', true);
    }

    /**
     * Add an OR WHERE NOT IN subquery clause.
     */
    public function orWhereNotInSubquery(string $column, \Closure|QueryBuilder $query): static
    {
        return $this->addInSubqueryClause($column, $query, 'OR', true);
    }

    /**
     * Add IN subquery clause helper.
     */
    private function addInSubqueryClause(string $column, \Closure|QueryBuilder $query, string $boolean, bool $not): static
    {
        if ($query instanceof \Closure) {
            $subQuery = new QueryBuilder($this->model);
            $query($subQuery);
        } else {
            $subQuery = $query;
        }

        $this->wheres[] = [
            'type' => 'in_subquery',
            'column' => Model::validateIdentifier($column),
            'query' => $subQuery,
            'not' => $not,
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * Add a column-to-column comparison in a WHERE clause.
     *
     * @param string $first First column
     * @param string $operator Comparison operator
     * @param string|null $second Second column (or null if operator is used as second column)
     * @return static
     *
     * @example
     * ```php
     * // Find records where updated_at differs from created_at
     * Post::query()->whereColumn('updated_at', '!=', 'created_at')->get();
     * ```
     */
    public function whereColumn(string $first, string $operator, ?string $second = null): static
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'column',
            'first' => Model::validateIdentifier($first),
            'operator' => $operator,
            'second' => Model::validateIdentifier($second),
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add an OR column comparison.
     */
    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'column',
            'first' => Model::validateIdentifier($first),
            'operator' => $operator,
            'second' => Model::validateIdentifier($second),
            'boolean' => 'OR',
        ];
        return $this;
    }

    /**
     * Set the FROM table for subqueries.
     *
     * @param string $table Table name
     * @return static
     */
    public function from(string $table): static
    {
        $this->fromTable = Model::validateIdentifier($table);
        return $this;
    }

    /** @var string|null Override table for FROM clause */
    private ?string $fromTable = null;

    // ========== JOINS ==========

    /**
     * Add an INNER JOIN clause.
     *
     * @param string $table Table to join (can include alias: 'posts AS p')
     * @param string $first First column for ON clause
     * @param string $operator Comparison operator
     * @param string $second Second column for ON clause
     * @return static
     *
     * @example
     * ```php
     * User::query()
     *     ->join('posts', 'users.id', '=', 'posts.user_id')
     *     ->select('users.*', 'posts.title')
     *     ->get();
     * ```
     */
    public function join(string $table, string $first, string $operator, string $second): static
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    /**
     * Add a LEFT JOIN clause.
     *
     * @param string $table Table to join
     * @param string $first First column for ON clause
     * @param string $operator Comparison operator
     * @param string $second Second column for ON clause
     * @return static
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    /**
     * Add a RIGHT JOIN clause.
     *
     * @param string $table Table to join
     * @param string $first First column for ON clause
     * @param string $operator Comparison operator
     * @param string $second Second column for ON clause
     * @return static
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->addJoin('RIGHT', $table, $first, $operator, $second);
    }

    /**
     * Add a CROSS JOIN clause.
     *
     * @param string $table Table to cross join
     * @return static
     */
    public function crossJoin(string $table): static
    {
        $this->joins[] = [
            'type' => 'CROSS',
            'table' => $table,
            'first' => null,
            'operator' => null,
            'second' => null,
        ];
        return $this;
    }

    /**
     * Add a raw JOIN clause.
     *
     * @param string $expression Raw JOIN expression
     * @param array $bindings Parameter bindings
     * @return static
     *
     * @example
     * ```php
     * User::query()
     *     ->joinRaw('LEFT JOIN posts ON posts.user_id = users.id AND posts.published = ?', [true])
     *     ->get();
     * ```
     */
    public function joinRaw(string $expression, array $bindings = []): static
    {
        $this->joins[] = [
            'type' => 'raw',
            'expression' => $expression,
            'bindings' => $bindings,
        ];
        return $this;
    }

    /**
     * Add a JOIN clause to the query.
     */
    private function addJoin(string $type, string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => Model::validateOperator($operator),
            'second' => $second,
        ];
        return $this;
    }

    /**
     * Compile JOIN clauses.
     * @return array{0: string, 1: array}
     */
    private function compileJoins(): array
    {
        if (empty($this->joins)) {
            return ['', []];
        }

        $sql = [];
        $bindings = [];

        foreach ($this->joins as $join) {
            if ($join['type'] === 'raw') {
                $sql[] = $join['expression'];
                $bindings = array_merge($bindings, $join['bindings']);
                continue;
            }

            // Parse table with optional alias (e.g., 'posts AS p' or 'posts p')
            $tableParts = preg_split('/\s+(?:AS\s+)?/i', $join['table'], 2);
            $tableName = Model::validateIdentifier(trim($tableParts[0]));
            $tableAlias = isset($tableParts[1]) ? Model::validateIdentifier(trim($tableParts[1])) : null;

            $quotedTable = Model::quoteIdentifier($tableName);
            if ($tableAlias) {
                $quotedTable .= ' AS ' . Model::quoteIdentifier($tableAlias);
            }

            if ($join['type'] === 'CROSS') {
                $sql[] = "CROSS JOIN $quotedTable";
            } else {
                // Parse column references (e.g., 'users.id' or 'id')
                $first = $this->quoteColumnReference($join['first']);
                $second = $this->quoteColumnReference($join['second']);

                $sql[] = "{$join['type']} JOIN $quotedTable ON $first {$join['operator']} $second";
            }
        }

        return [implode(' ', $sql), $bindings];
    }

    /**
     * Quote a column reference that may include a table prefix.
     */
    private function quoteColumnReference(string $column): string
    {
        if (str_contains($column, '.')) {
            $parts = explode('.', $column, 2);
            return Model::quoteIdentifier(Model::validateIdentifier($parts[0])) . '.'
                 . Model::quoteIdentifier(Model::validateIdentifier($parts[1]));
        }
        return Model::quoteIdentifier(Model::validateIdentifier($column));
    }

    // ========== ORDERING ==========

    /**
     * Add ORDER BY clause
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new InvalidArgumentException("Invalid order direction: $direction");
        }

        $this->orderBys[] = [
            'column' => Model::validateIdentifier($column),
            'direction' => $direction,
        ];
        return $this;
    }

    /**
     * Order by descending (latest first)
     */
    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by ascending (oldest first)
     */
    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
    }

    // ========== UNION ==========

    /**
     * Add a UNION query.
     *
     * Combines results from multiple SELECT statements, removing duplicates.
     *
     * @param QueryBuilder|Closure $query The query to union
     * @return static
     *
     * @example
     * ```php
     * // Get all admins and all editors
     * User::query()
     *     ->where('role', 'admin')
     *     ->union(fn($q) => $q->where('role', 'editor'))
     *     ->get();
     * ```
     */
    public function union(QueryBuilder|\Closure $query): static
    {
        return $this->addUnion($query, false);
    }

    /**
     * Add a UNION ALL query.
     *
     * Combines results from multiple SELECT statements, keeping all rows including duplicates.
     *
     * @param QueryBuilder|Closure $query The query to union
     * @return static
     */
    public function unionAll(QueryBuilder|\Closure $query): static
    {
        return $this->addUnion($query, true);
    }

    /**
     * Add a union query helper.
     */
    private function addUnion(QueryBuilder|\Closure $query, bool $all): static
    {
        if ($query instanceof \Closure) {
            $subQuery = new QueryBuilder($this->model);
            $query($subQuery);
        } else {
            $subQuery = $query;
        }

        $this->unions[] = [
            'query' => $subQuery,
            'all' => $all,
        ];
        return $this;
    }

    // ========== ROW LOCKING ==========

    /**
     * Lock selected rows for update (pessimistic locking).
     *
     * Uses FOR UPDATE to prevent other transactions from modifying selected rows
     * until the current transaction commits.
     *
     * @return static
     *
     * @example
     * ```php
     * Model::transaction(function() {
     *     $inventory = Inventory::query()
     *         ->where('product_id', $productId)
     *         ->lockForUpdate()
     *         ->first();
     *
     *     if ($inventory->quantity >= $qty) {
     *         $inventory->quantity -= $qty;
     *         $inventory->save();
     *     }
     * });
     * ```
     */
    public function lockForUpdate(): static
    {
        $this->lock = 'FOR UPDATE';
        return $this;
    }

    /**
     * Lock selected rows with a shared lock.
     *
     * Allows other transactions to read but not modify selected rows
     * until the current transaction commits.
     *
     * @return static
     */
    public function sharedLock(): static
    {
        $this->lock = 'FOR SHARE';
        return $this;
    }

    /**
     * Set a custom lock clause.
     *
     * @param string $lock Lock clause (e.g., 'FOR UPDATE NOWAIT')
     * @return static
     */
    public function lock(string $lock): static
    {
        $this->lock = $lock;
        return $this;
    }

    // ========== GROUP BY ==========

    /**
     * Add GROUP BY clause.
     *
     * @param string|array ...$columns Columns to group by
     * @return static
     *
     * @example
     * ```php
     * // Single column
     * User::query()->groupBy('status')->get();
     *
     * // Multiple columns
     * Post::query()->groupBy('user_id', 'category_id')->get();
     * ```
     */
    public function groupBy(string|array ...$columns): static
    {
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $c) {
                    $this->groupBy[] = Model::validateIdentifier($c);
                }
            } else {
                $this->groupBy[] = Model::validateIdentifier($col);
            }
        }
        return $this;
    }

    // ========== HAVING ==========

    /**
     * Add a HAVING clause (used with GROUP BY).
     *
     * @param string $column Column or aggregate expression
     * @param mixed $operator Comparison operator or value
     * @param mixed $value Value to compare (if operator provided)
     * @return static
     *
     * @example
     * ```php
     * Post::query()
     *     ->select('user_id', 'COUNT(*) as post_count')
     *     ->groupBy('user_id')
     *     ->having('post_count', '>', 5)
     *     ->get();
     * ```
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): static
    {
        // Two arguments: having('column', 'value') means =
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->having[] = [
            'type' => 'basic',
            'column' => $column, // Allow aggregate expressions like COUNT(*)
            'operator' => Model::validateOperator($operator),
            'value' => $value,
            'boolean' => 'AND',
        ];

        return $this;
    }

    /**
     * Add an OR HAVING clause.
     */
    public function orHaving(string $column, mixed $operator = null, mixed $value = null): static
    {
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }

        $this->having[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => Model::validateOperator($operator),
            'value' => $value,
            'boolean' => 'OR',
        ];

        return $this;
    }

    /**
     * Add a raw HAVING clause.
     *
     * @param string $sql Raw SQL for HAVING clause
     * @param array $bindings Parameter bindings
     * @return static
     *
     * @example
     * ```php
     * Post::query()
     *     ->groupBy('user_id')
     *     ->havingRaw('COUNT(*) > ?', [5])
     *     ->get();
     * ```
     */
    public function havingRaw(string $sql, array $bindings = []): static
    {
        $this->having[] = [
            'type' => 'raw',
            'sql' => $sql,
            'bindings' => $bindings,
            'boolean' => 'AND',
        ];
        return $this;
    }

    // ========== LIMIT & OFFSET ==========

    /**
     * Set LIMIT
     */
    public function limit(int $limit): static
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Set OFFSET
     */
    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Alias for limit
     */
    public function take(int $count): static
    {
        return $this->limit($count);
    }

    /**
     * Alias for offset
     */
    public function skip(int $count): static
    {
        return $this->offset($count);
    }

    // ========== COLUMNS ==========

    /**
     * Set SELECT columns
     */
    public function select(string|array ...$columns): static
    {
        $this->columns = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                $this->columns = array_merge($this->columns, $col);
            } else {
                $this->columns[] = $col;
            }
        }
        return $this;
    }

    /**
     * Add columns to the SELECT clause without replacing existing columns.
     *
     * @param string|array ...$columns Columns to add
     * @return static
     */
    public function addSelect(string|array ...$columns): static
    {
        foreach ($columns as $col) {
            if (is_array($col)) {
                $this->columns = array_merge($this->columns, $col);
            } else {
                $this->columns[] = $col;
            }
        }
        return $this;
    }

    /**
     * Add a subquery as a column.
     *
     * @param Closure|QueryBuilder $query The subquery
     * @param string $alias Column alias
     * @return static
     *
     * @example
     * ```php
     * User::query()
     *     ->addSelect('*')
     *     ->selectSub(
     *         fn($q) => $q->from('posts')->selectRaw('COUNT(*)')->whereColumn('posts.user_id', 'users.id'),
     *         'posts_count'
     *     )
     *     ->get();
     * ```
     */
    public function selectSub(\Closure|QueryBuilder $query, string $alias): static
    {
        if ($query instanceof \Closure) {
            $subQuery = new QueryBuilder($this->model);
            $query($subQuery);
        } else {
            $subQuery = $query;
        }

        [$sql, $bindings] = $subQuery->toSql();
        $this->columns[] = "($sql) AS " . Model::quoteIdentifier($alias);
        $this->selectBindings = array_merge($this->selectBindings, $bindings);
        return $this;
    }

    /**
     * Add a raw expression to the SELECT clause.
     *
     * @param string $expression Raw SQL expression
     * @param array $bindings Parameter bindings
     * @return static
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->columns[] = $expression;
        $this->selectBindings = array_merge($this->selectBindings, $bindings);
        return $this;
    }

    /** @var array Parameter bindings for SELECT subqueries */
    private array $selectBindings = [];

    // ========== SOFT DELETES ==========

    /**
     * Include soft-deleted records
     */
    public function withTrashed(): static
    {
        $this->withTrashed = true;
        return $this;
    }

    /**
     * Only soft-deleted records
     */
    public function onlyTrashed(): static
    {
        $this->onlyTrashed = true;
        $this->withTrashed = true;
        return $this;
    }

    // ========== EAGER LOADING ==========

    /**
     * Eager load relationships
     */
    public function with(string|array ...$relations): static
    {
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                $this->eagerLoad = array_merge($this->eagerLoad, $relation);
            } else {
                $this->eagerLoad[] = $relation;
            }
        }
        return $this;
    }

    // ========== EXECUTION ==========

    /**
     * Execute query and return models as a Collection
     *
     * @return Collection<Model>
     */
    public function get(): Collection
    {
        $this->applyGlobalScopesToQuery();

        [$sql, $bindings] = $this->toSql();

        $model = $this->model;
        $results = $model::executeQuery($sql, $bindings);

        $models = array_map(fn($row) => $model::hydrate($row), $results);

        // Eager load relationships
        if (!empty($this->eagerLoad) && !empty($models)) {
            $this->loadRelations($models);
        }

        return new Collection($models);
    }

    /**
     * Get first result
     */
    public function first(): ?Model
    {
        $this->limit(1);
        $results = $this->get();
        return $results->first();
    }

    /**
     * Get first result or throw
     */
    public function firstOrFail(): Model
    {
        $result = $this->first();
        if ($result === null) {
            $model = $this->model;
            throw new RuntimeException("No results found for " . $model::getTable());
        }
        return $result;
    }

    /**
     * Find by primary key
     */
    public function find(mixed $id): ?Model
    {
        $model = $this->model;
        return $this->where($model::PRIMARY_KEY, $id)->first();
    }

    /**
     * Check if any records exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if no records exist
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // ========== AGGREGATES ==========

    /**
     * Count records
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    /**
     * Sum of column
     */
    public function sum(string $column): float
    {
        return (float) ($this->aggregate('SUM', $column) ?? 0);
    }

    /**
     * Average of column
     */
    public function avg(string $column): ?float
    {
        $result = $this->aggregate('AVG', $column);
        return $result !== null ? (float) $result : null;
    }

    /**
     * Minimum value
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Maximum value
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Execute aggregate function
     */
    private function aggregate(string $function, string $column): mixed
    {
        $this->applyGlobalScopesToQuery();

        $model = $this->model;
        $col = $column === '*' ? '*' : $model::quoteIdentifier(Model::validateIdentifier($column));

        $originalColumns = $this->columns;
        $this->columns = ["$function($col) AS aggregate"];

        [$sql, $bindings] = $this->toSql();
        $result = $model::executeQuery($sql, $bindings);

        $this->columns = $originalColumns;

        return $result[0]['aggregate'] ?? null;
    }

    /**
     * Get values of a single column
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $this->applyGlobalScopesToQuery();

        $columns = [$column];
        if ($key !== null) {
            $columns[] = $key;
        }

        $this->columns = $columns;
        [$sql, $bindings] = $this->toSql();

        $model = $this->model;
        $results = $model::executeQuery($sql, $bindings);

        if ($key !== null) {
            return array_column($results, $column, $key);
        }
        return array_column($results, $column);
    }

    /**
     * Paginate results
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $total = (clone $this)->count();

        $results = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        $lastPage = (int) ceil($total / $perPage);

        return [
            'data' => $results,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $total > 0 ? ($page - 1) * $perPage + 1 : null,
            'to' => $total > 0 ? min($page * $perPage, $total) : null,
        ];
    }

    /**
     * Paginate results using cursor-based pagination.
     *
     * Cursor pagination is more efficient than offset pagination for large datasets
     * because it doesn't require counting or skipping rows. It works by using a
     * column value (typically the ID) to determine the starting point.
     *
     * @param int $perPage Number of items per page
     * @param string $cursorColumn Column to use for cursor (must be unique and sortable)
     * @param string|null $cursor The cursor value (typically base64-encoded last value)
     * @param string $direction 'asc' or 'desc'
     * @return array{data: Collection, next_cursor: ?string, prev_cursor: ?string, has_more: bool}
     *
     * @example
     * ```php
     * // First page
     * $page1 = User::query()->orderBy('id')->cursorPaginate(perPage: 10);
     *
     * // Next page using cursor
     * $page2 = User::query()->orderBy('id')->cursorPaginate(
     *     perPage: 10,
     *     cursor: $page1['next_cursor']
     * );
     * ```
     */
    public function cursorPaginate(
        int $perPage = 15,
        string $cursorColumn = 'id',
        ?string $cursor = null,
        string $direction = 'asc'
    ): array {
        $direction = strtolower($direction);
        $operator = $direction === 'desc' ? '<' : '>';

        // Decode cursor
        $cursorValue = null;
        if ($cursor !== null) {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false) {
                $cursorValue = json_decode($decoded, true);
            }
        }

        // Apply cursor constraint
        if ($cursorValue !== null) {
            $this->where($cursorColumn, $operator, $cursorValue);
        }

        // Fetch one extra to check for more
        $results = (clone $this)
            ->orderBy($cursorColumn, $direction)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $results->count() > $perPage;

        // Remove the extra item if present
        if ($hasMore) {
            $results = $results->take($perPage);
        }

        // Generate next cursor
        $nextCursor = null;
        if ($hasMore && $results->isNotEmpty()) {
            $lastItem = $results->last();
            $lastValue = $lastItem?->getAttribute($cursorColumn);
            if ($lastValue !== null) {
                $nextCursor = base64_encode(json_encode($lastValue));
            }
        }

        // Generate previous cursor (first item's value)
        $prevCursor = null;
        if ($cursorValue !== null && $results->isNotEmpty()) {
            $firstItem = $results->first();
            $firstValue = $firstItem?->getAttribute($cursorColumn);
            if ($firstValue !== null) {
                $prevCursor = base64_encode(json_encode($firstValue));
            }
        }

        return [
            'data' => $results,
            'next_cursor' => $nextCursor,
            'prev_cursor' => $prevCursor,
            'has_more' => $hasMore,
            'per_page' => $perPage,
        ];
    }

    /**
     * Process results in chunks
     */
    public function chunk(int $size, callable $callback): bool
    {
        $page = 1;

        do {
            $results = (clone $this)->limit($size)->offset(($page - 1) * $size)->get();

            if (empty($results)) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while (count($results) === $size);

        return true;
    }

    // ========== BULK OPERATIONS ==========

    /**
     * Update matching records
     */
    public function update(array $values): int
    {
        $this->applyGlobalScopesToQuery();

        $model = $this->model;
        $table = $model::getTable();

        $sets = [];
        $bindings = [];

        // Add updated_at if timestamps enabled
        if ($model::TIMESTAMPS) {
            $values[$model::UPDATED_AT] = date('Y-m-d H:i:s');
        }

        foreach ($values as $column => $value) {
            $sets[] = $model::quoteIdentifier(Model::validateIdentifier($column)) . ' = ?';
            $bindings[] = $value;
        }

        $sql = "UPDATE " . $model::quoteIdentifier($table) . " SET " . implode(', ', $sets);

        [$whereSql, $whereBindings] = $this->compileWheres();
        if ($whereSql) {
            $sql .= " WHERE $whereSql";
            $bindings = array_merge($bindings, $whereBindings);
        }

        return $model::executeStatement($sql, $bindings);
    }

    /**
     * Delete matching records
     */
    public function delete(): int
    {
        $this->applyGlobalScopesToQuery();

        $model = $this->model;

        // Soft delete
        if ($model::SOFT_DELETES && !$this->withTrashed) {
            return $this->update([$model::DELETED_AT => date('Y-m-d H:i:s')]);
        }

        $table = $model::getTable();
        $sql = "DELETE FROM " . $model::quoteIdentifier($table);

        [$whereSql, $bindings] = $this->compileWheres();
        if ($whereSql) {
            $sql .= " WHERE $whereSql";
        }

        return $model::executeStatement($sql, $bindings);
    }

    /**
     * Force delete (bypass soft deletes)
     */
    public function forceDelete(): int
    {
        $this->withTrashed = true;

        $model = $this->model;
        $table = $model::getTable();
        $sql = "DELETE FROM " . $model::quoteIdentifier($table);

        [$whereSql, $bindings] = $this->compileWheres();
        if ($whereSql) {
            $sql .= " WHERE $whereSql";
        }

        return $model::executeStatement($sql, $bindings);
    }

    /**
     * Restore soft-deleted records
     */
    public function restore(): int
    {
        $model = $this->model;

        if (!$model::SOFT_DELETES) {
            throw new RuntimeException("Model does not use soft deletes");
        }

        $this->onlyTrashed();
        return $this->update([$model::DELETED_AT => null]);
    }

    // ========== SQL COMPILATION ==========

    /**
     * Compile to SQL and bindings
     * @return array{0: string, 1: array}
     */
    public function toSql(): array
    {
        $model = $this->model;
        $table = $this->fromTable ?? $model::getTable();
        $bindings = [];

        // Add select bindings first (for subqueries in SELECT)
        $bindings = array_merge($bindings, $this->selectBindings);

        // SELECT
        $columns = $this->columns === ['*']
            ? '*'
            : implode(', ', array_map(
                function($c) use ($model) {
                    if ($c === '*') return '*';
                    // Raw expressions (contain spaces, parens, etc) - use as-is
                    if (preg_match('/[\s\(\)]/', $c)) return $c;
                    return $model::quoteIdentifier(Model::validateIdentifier($c));
                },
                $this->columns
            ));

        $sql = "SELECT $columns FROM " . $model::quoteIdentifier($table);

        // JOINS
        [$joinSql, $joinBindings] = $this->compileJoins();
        if ($joinSql) {
            $sql .= ' ' . $joinSql;
            $bindings = array_merge($bindings, $joinBindings);
        }

        // Handle soft deletes
        if ($model::SOFT_DELETES) {
            if ($this->onlyTrashed) {
                $this->whereNotNull($model::DELETED_AT);
            } elseif (!$this->withTrashed) {
                $this->whereNull($model::DELETED_AT);
            }
        }

        // WHERE
        [$whereSql, $whereBindings] = $this->compileWheres();
        if ($whereSql) {
            $sql .= " WHERE $whereSql";
            $bindings = array_merge($bindings, $whereBindings);
        }

        // GROUP BY
        if (!empty($this->groupBy)) {
            $groups = array_map(
                fn($c) => $model::quoteIdentifier($c),
                $this->groupBy
            );
            $sql .= ' GROUP BY ' . implode(', ', $groups);
        }

        // HAVING
        [$havingSql, $havingBindings] = $this->compileHaving();
        if ($havingSql) {
            $sql .= " HAVING $havingSql";
            $bindings = array_merge($bindings, $havingBindings);
        }

        // ORDER BY
        if (!empty($this->orderBys)) {
            $orders = array_map(
                fn($o) => $model::quoteIdentifier($o['column']) . ' ' . $o['direction'],
                $this->orderBys
            );
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        // LIMIT
        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        // OFFSET
        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        // UNION
        foreach ($this->unions as $union) {
            $type = $union['all'] ? 'UNION ALL' : 'UNION';
            [$unionSql, $unionBindings] = $union['query']->toSql();
            $sql .= " $type $unionSql";
            $bindings = array_merge($bindings, $unionBindings);
        }

        // ROW LOCKING
        if ($this->lock !== null) {
            $sql .= ' ' . $this->lock;
        }

        return [$sql, $bindings];
    }

    /**
     * Compile WHERE clauses
     * @return array{0: string, 1: array}
     */
    private function compileWheres(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }

        $model = $this->model;
        $sql = [];
        $bindings = [];

        foreach ($this->wheres as $i => $where) {
            $prefix = $i === 0 ? '' : ' ' . $where['boolean'] . ' ';

            switch ($where['type']) {
                case 'basic':
                    $col = $model::quoteIdentifier($where['column']);
                    $sql[] = "$prefix$col {$where['operator']} ?";
                    $bindings[] = $where['value'];
                    break;

                case 'in':
                    $col = $model::quoteIdentifier($where['column']);
                    $not = $where['not'] ? 'NOT ' : '';
                    if (empty($where['values'])) {
                        // Empty IN clause - always false
                        $sql[] = $prefix . ($where['not'] ? '1=1' : '1=0');
                    } else {
                        $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                        $sql[] = "$prefix$col {$not}IN ($placeholders)";
                        $bindings = array_merge($bindings, $where['values']);
                    }
                    break;

                case 'null':
                    $col = $model::quoteIdentifier($where['column']);
                    $op = $where['not'] ? 'IS NOT NULL' : 'IS NULL';
                    $sql[] = "$prefix$col $op";
                    break;

                case 'between':
                    $col = $model::quoteIdentifier($where['column']);
                    $not = $where['not'] ? 'NOT ' : '';
                    $sql[] = "$prefix$col {$not}BETWEEN ? AND ?";
                    $bindings[] = $where['min'];
                    $bindings[] = $where['max'];
                    break;

                case 'raw':
                    $sql[] = $prefix . $where['sql'];
                    $bindings = array_merge($bindings, $where['bindings']);
                    break;

                case 'nested':
                    [$nestedSql, $nestedBindings] = $where['query']->compileWheres();
                    if ($nestedSql) {
                        $sql[] = "$prefix($nestedSql)";
                        $bindings = array_merge($bindings, $nestedBindings);
                    }
                    break;

                case 'exists':
                    [$subSql, $subBindings] = $where['query']->toSql();
                    $not = $where['not'] ? 'NOT ' : '';
                    $sql[] = "{$prefix}{$not}EXISTS ($subSql)";
                    $bindings = array_merge($bindings, $subBindings);
                    break;

                case 'in_subquery':
                    [$subSql, $subBindings] = $where['query']->toSql();
                    $col = $model::quoteIdentifier($where['column']);
                    $not = $where['not'] ? 'NOT ' : '';
                    $sql[] = "$prefix$col {$not}IN ($subSql)";
                    $bindings = array_merge($bindings, $subBindings);
                    break;

                case 'column':
                    $first = $this->quoteColumnReference($where['first']);
                    $second = $this->quoteColumnReference($where['second']);
                    $sql[] = "$prefix$first {$where['operator']} $second";
                    break;
            }
        }

        return [implode('', $sql), $bindings];
    }

    /**
     * Compile HAVING clauses
     * @return array{0: string, 1: array}
     */
    private function compileHaving(): array
    {
        if (empty($this->having)) {
            return ['', []];
        }

        $sql = [];
        $bindings = [];

        foreach ($this->having as $i => $having) {
            $prefix = $i === 0 ? '' : ' ' . $having['boolean'] . ' ';

            switch ($having['type']) {
                case 'basic':
                    // Allow aggregate expressions, only quote if it looks like a simple column
                    $col = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $having['column'])
                        ? Model::quoteIdentifier($having['column'])
                        : $having['column'];
                    $sql[] = "$prefix$col {$having['operator']} ?";
                    $bindings[] = $having['value'];
                    break;

                case 'raw':
                    $sql[] = $prefix . $having['sql'];
                    $bindings = array_merge($bindings, $having['bindings']);
                    break;
            }
        }

        return [implode('', $sql), $bindings];
    }

    /**
     * Load eager relations onto models
     */
    private function loadRelations(array $models): void
    {
        foreach ($this->eagerLoad as $relation) {
            // Handle nested relations (e.g., 'posts.comments')
            $parts = explode('.', $relation);
            $this->loadNestedRelation($models, $parts);
        }
    }

    /**
     * Load a potentially nested relation
     */
    private function loadNestedRelation(array $models, array $parts): void
    {
        if (empty($models) || empty($parts)) {
            return;
        }

        $relation = array_shift($parts);
        $first = $models[0];

        if (!method_exists($first, $relation)) {
            throw new RuntimeException("Relationship method '$relation' does not exist");
        }

        /** @var Relation $relationInstance */
        $relationInstance = $first->$relation();

        // Get all related models
        $relationInstance->addEagerConstraints($models);
        $results = $relationInstance->getEagerResults();

        // Match results to parent models
        $relationInstance->match($models, $results, $relation);

        // Handle nested relations
        if (!empty($parts)) {
            $nested = [];
            foreach ($models as $model) {
                $related = $model->$relation;
                if ($related instanceof Collection) {
                    $nested = array_merge($nested, $related->all());
                } elseif (is_array($related)) {
                    $nested = array_merge($nested, $related);
                } elseif ($related !== null) {
                    $nested[] = $related;
                }
            }

            if (!empty($nested)) {
                $this->loadNestedRelation($nested, $parts);
            }
        }
    }

    /**
     * Get raw SQL with bindings interpolated (for debugging)
     */
    public function toRawSql(): string
    {
        [$sql, $bindings] = $this->toSql();

        foreach ($bindings as $binding) {
            $value = match (true) {
                $binding === null => 'NULL',
                is_bool($binding) => $binding ? 'TRUE' : 'FALSE',
                is_int($binding), is_float($binding) => (string) $binding,
                default => "'" . addslashes((string) $binding) . "'",
            };
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }

        return $sql;
    }

    /**
     * Dump SQL and die (for debugging)
     */
    public function dd(): never
    {
        echo $this->toRawSql() . "\n";
        exit(1);
    }
}

// ============================================================================
// COLLECTION
// ============================================================================

/**
 * Fluent wrapper for arrays of models with transformation and aggregation methods.
 *
 * Collection provides a powerful API for working with arrays of data,
 * including filtering, mapping, reducing, and aggregation operations.
 * It implements common interfaces for seamless integration with PHP.
 *
 * @template TKey of array-key
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 * @implements \ArrayAccess<TKey, TValue>
 *
 * @example
 * ```php
 * // QueryBuilder returns Collection
 * $users = User::where('active', true)->get();
 *
 * // Fluent operations
 * $names = $users->pluck('name');
 * $admins = $users->where('is_admin', true);
 * $grouped = $users->groupBy('department');
 * ```
 */
class Collection implements \IteratorAggregate, \ArrayAccess, \Countable, \JsonSerializable
{
    /** @var array<TKey, TValue> */
    protected array $items;

    /**
     * Create a new collection instance.
     *
     * @param array<TKey, TValue> $items Items to wrap
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Create a new collection.
     *
     * @param array $items Items to wrap
     * @return static
     */
    public static function make(array $items = []): static
    {
        return new static($items);
    }

    /**
     * Wrap a value in a collection if not already one.
     *
     * @param mixed $value Value to wrap
     * @return static
     */
    public static function wrap(mixed $value): static
    {
        if ($value instanceof self) {
            return new static($value->all());
        }
        return new static(is_array($value) ? $value : [$value]);
    }

    // ========== BASIC ACCESSORS ==========

    /**
     * Get all items as array.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get number of items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Check if collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    // ========== TRANSFORMATION ==========

    /**
     * Apply a callback to each item and return new collection.
     *
     * @param callable $callback Function receiving (value, key)
     * @return static
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $values = array_map($callback, $this->items, $keys);
        return new static(array_combine($keys, $values));
    }

    /**
     * Filter items using a callback.
     *
     * @param callable|null $callback Filter function (value, key) => bool
     * @return static
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }
        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Reject items matching callback (inverse of filter).
     *
     * @param callable $callback Function (value, key) => bool
     * @return static
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($v, $k) => !$callback($v, $k));
    }

    /**
     * Reduce collection to a single value.
     *
     * @param callable $callback Function (carry, item) => mixed
     * @param mixed $initial Initial value
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Execute callback for each item.
     *
     * @param callable $callback Function (value, key) => void|false
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        return $this;
    }

    /**
     * Split collection into chunks.
     *
     * @param int $size Chunk size
     * @return static Collection of collections
     */
    public function chunk(int $size): static
    {
        $chunks = array_chunk($this->items, $size, true);
        return new static(array_map(fn($chunk) => new static($chunk), $chunks));
    }

    /**
     * Flatten a multi-dimensional collection.
     *
     * @param int $depth Depth to flatten (INF for all)
     * @return static
     */
    public function flatten(int $depth = INF): static
    {
        $result = [];
        foreach ($this->items as $item) {
            if (!is_array($item) && !$item instanceof self) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $values = $item instanceof self ? $item->all() : $item;
                $result = array_merge($result, array_values($values));
            } else {
                $values = $item instanceof self ? $item->all() : $item;
                $result = array_merge($result, (new static($values))->flatten($depth - 1)->all());
            }
        }
        return new static($result);
    }

    // ========== ACCESS ==========

    /**
     * Get first item, optionally matching callback.
     *
     * @param callable|null $callback Filter function
     * @param mixed $default Default if not found
     * @return mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items[array_key_first($this->items)] ?? $default;
        }
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Get last item, optionally matching callback.
     *
     * @param callable|null $callback Filter function
     * @param mixed $default Default if not found
     * @return mixed
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items[array_key_last($this->items)] ?? $default;
        }
        $result = $default;
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                $result = $value;
            }
        }
        return $result;
    }

    /**
     * Get item by key.
     *
     * @param int|string $key Item key
     * @param mixed $default Default if not found
     * @return mixed
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    /**
     * Extract column values from items.
     *
     * @param string $key Column to extract
     * @param string|null $keyBy Optional column to use as keys
     * @return static
     */
    public function pluck(string $key, ?string $keyBy = null): static
    {
        $results = [];
        foreach ($this->items as $item) {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if ($keyBy !== null) {
                $keyValue = is_array($item) ? ($item[$keyBy] ?? null) : ($item->$keyBy ?? null);
                $results[$keyValue] = $value;
            } else {
                $results[] = $value;
            }
        }
        return new static($results);
    }

    /**
     * Group items by a key or callback.
     *
     * @param string|callable $key Grouping key or callback
     * @return static Collection of collections
     */
    public function groupBy(string|callable $key): static
    {
        $results = [];
        foreach ($this->items as $item) {
            $groupKey = is_callable($key)
                ? $key($item)
                : (is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null));
            $results[$groupKey][] = $item;
        }
        return new static(array_map(fn($group) => new static($group), $results));
    }

    /**
     * Key items by a column or callback.
     *
     * @param string|callable $key Column name or callback
     * @return static
     */
    public function keyBy(string|callable $key): static
    {
        $results = [];
        foreach ($this->items as $item) {
            $keyValue = is_callable($key)
                ? $key($item)
                : (is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null));
            $results[$keyValue] = $item;
        }
        return new static($results);
    }

    // ========== SORTING ==========

    /**
     * Sort items.
     *
     * @param callable|null $callback Comparison function
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        $callback ? uasort($items, $callback) : asort($items);
        return new static($items);
    }

    /**
     * Sort items by key or callback.
     *
     * @param string|callable $key Sort key or callback
     * @param bool $descending Sort descending
     * @return static
     */
    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $items = $this->items;
        uasort($items, function ($a, $b) use ($key, $descending) {
            $aValue = is_callable($key) ? $key($a) : (is_array($a) ? ($a[$key] ?? null) : ($a->$key ?? null));
            $bValue = is_callable($key) ? $key($b) : (is_array($b) ? ($b[$key] ?? null) : ($b->$key ?? null));
            $result = $aValue <=> $bValue;
            return $descending ? -$result : $result;
        });
        return new static($items);
    }

    /**
     * Sort items by key descending.
     *
     * @param string|callable $key Sort key
     * @return static
     */
    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    /**
     * Reverse item order.
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    // ========== AGGREGATES ==========

    /**
     * Sum of items or column.
     *
     * @param string|callable|null $key Column or callback
     * @return int|float
     */
    public function sum(string|callable|null $key = null): int|float
    {
        if ($key === null) {
            return array_sum($this->items);
        }
        return $this->pluck(is_string($key) ? $key : '')->reduce(
            fn($carry, $item) => $carry + (is_callable($key) ? $key($item) : $item),
            0
        );
    }

    /**
     * Average of items or column.
     *
     * @param string|callable|null $key Column or callback
     * @return int|float|null
     */
    public function avg(string|callable|null $key = null): int|float|null
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : null;
    }

    /**
     * Minimum value.
     *
     * @param string|callable|null $key Column or callback
     * @return mixed
     */
    public function min(string|callable|null $key = null): mixed
    {
        $items = $key !== null ? $this->pluck($key)->all() : $this->items;
        return empty($items) ? null : min($items);
    }

    /**
     * Maximum value.
     *
     * @param string|callable|null $key Column or callback
     * @return mixed
     */
    public function max(string|callable|null $key = null): mixed
    {
        $items = $key !== null ? $this->pluck($key)->all() : $this->items;
        return empty($items) ? null : max($items);
    }

    /**
     * Get median value.
     *
     * @param string|null $key Column to get median of
     * @return int|float|null
     */
    public function median(?string $key = null): int|float|null
    {
        $values = $key !== null ? $this->pluck($key)->all() : $this->items;
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        sort($values);
        $middle = (int) floor($count / 2);
        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }
        return $values[$middle];
    }

    // ========== SEARCH & FILTERING ==========

    /**
     * Check if collection contains a value or matches callback.
     *
     * @param mixed $key Value, key, or callback
     * @param mixed $value Value to compare (if key provided)
     * @return bool
     */
    public function contains(mixed $key, mixed $value = null): bool
    {
        if ($value !== null) {
            return $this->contains(fn($item) =>
                (is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null)) === $value
            );
        }
        if (is_callable($key)) {
            foreach ($this->items as $k => $item) {
                if ($key($item, $k)) {
                    return true;
                }
            }
            return false;
        }
        return in_array($key, $this->items, true);
    }

    /**
     * Filter by key/value comparison.
     *
     * @param string $key Column to compare
     * @param mixed $operator Operator or value
     * @param mixed $value Value (if operator provided)
     * @return static
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        if ($value === null && $operator !== null) {
            $value = $operator;
            $operator = '=';
        }
        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            return match ($operator) {
                '=', '==' => $itemValue == $value,
                '===' => $itemValue === $value,
                '!=', '<>' => $itemValue != $value,
                '!==' => $itemValue !== $value,
                '>' => $itemValue > $value,
                '<' => $itemValue < $value,
                '>=' => $itemValue >= $value,
                '<=' => $itemValue <= $value,
                default => false,
            };
        });
    }

    /**
     * Filter by value being in array.
     *
     * @param string $key Column to check
     * @param array $values Allowed values
     * @return static
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) =>
            in_array(is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null), $values, true)
        );
    }

    /**
     * Filter by value not being in array.
     *
     * @param string $key Column to check
     * @param array $values Disallowed values
     * @return static
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) =>
            !in_array(is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null), $values, true)
        );
    }

    /**
     * Filter by value being null.
     *
     * @param string $key Column to check
     * @return static
     */
    public function whereNull(string $key): static
    {
        return $this->where($key, '===', null);
    }

    /**
     * Filter by value not being null.
     *
     * @param string $key Column to check
     * @return static
     */
    public function whereNotNull(string $key): static
    {
        return $this->where($key, '!==', null);
    }

    // ========== SET OPERATIONS ==========

    /**
     * Get unique items.
     *
     * @param string|null $key Column to check for uniqueness
     * @return static
     */
    public function unique(?string $key = null): static
    {
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }
        $exists = [];
        return $this->filter(function ($item) use ($key, &$exists) {
            $value = is_array($item) ? ($item[$key] ?? null) : ($item->$key ?? null);
            if (in_array($value, $exists, true)) {
                return false;
            }
            $exists[] = $value;
            return true;
        });
    }

    /**
     * Get values only (reset keys).
     *
     * @return static
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get keys only.
     *
     * @return static
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Merge with another array or collection.
     *
     * @param iterable $items Items to merge
     * @return static
     */
    public function merge(iterable $items): static
    {
        return new static(array_merge(
            $this->items,
            $items instanceof self ? $items->all() : (is_array($items) ? $items : iterator_to_array($items))
        ));
    }

    /**
     * Combine collection values with given keys.
     *
     * @param iterable $keys Keys to use
     * @return static
     */
    public function combine(iterable $keys): static
    {
        $keys = $keys instanceof self ? $keys->all() : (is_array($keys) ? $keys : iterator_to_array($keys));
        return new static(array_combine($keys, $this->items));
    }

    /**
     * Get difference with another array.
     *
     * @param iterable $items Items to diff against
     * @return static
     */
    public function diff(iterable $items): static
    {
        $items = $items instanceof self ? $items->all() : (is_array($items) ? $items : iterator_to_array($items));
        return new static(array_diff($this->items, $items));
    }

    /**
     * Get intersection with another array.
     *
     * @param iterable $items Items to intersect with
     * @return static
     */
    public function intersect(iterable $items): static
    {
        $items = $items instanceof self ? $items->all() : (is_array($items) ? $items : iterator_to_array($items));
        return new static(array_intersect($this->items, $items));
    }

    // ========== SLICE OPERATIONS ==========

    /**
     * Take first n items.
     *
     * @param int $limit Number of items
     * @return static
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return new static(array_slice($this->items, $limit, abs($limit), true));
        }
        return new static(array_slice($this->items, 0, $limit, true));
    }

    /**
     * Skip first n items.
     *
     * @param int $count Number to skip
     * @return static
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count, null, true));
    }

    /**
     * Get slice of collection.
     *
     * @param int $offset Start position
     * @param int|null $length Number of items
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Paginate the collection.
     *
     * @param int $perPage Items per page
     * @param int $page Page number (1-based)
     * @return static
     */
    public function forPage(int $page, int $perPage): static
    {
        return $this->slice(($page - 1) * $perPage, $perPage);
    }

    // ========== INTERFACE IMPLEMENTATIONS ==========

    /**
     * Get iterator for foreach.
     *
     * @return \Traversable
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Check if offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Get item at offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * Set item at offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Remove item at offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Convert to JSON-serializable array.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn($item) => $item instanceof \JsonSerializable ? $item->jsonSerialize() : $item,
            $this->items
        );
    }

    /**
     * Convert to array (with nested model conversion).
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_map(
            fn($item) => $item instanceof Model ? $item->toArray() : $item,
            $this->items
        );
    }

    /**
     * Convert to JSON string.
     *
     * @param int $options JSON encoding options
     * @return string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert to string (JSON).
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Add an item to the collection.
     *
     * @param mixed $item Item to add
     * @return static
     */
    public function push(mixed ...$items): static
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
        return $this;
    }

    /**
     * Remove and return last item.
     *
     * @return mixed
     */
    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    /**
     * Remove and return first item.
     *
     * @return mixed
     */
    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    /**
     * Add item to beginning.
     *
     * @param mixed $item Item to prepend
     * @return static
     */
    public function prepend(mixed $item): static
    {
        array_unshift($this->items, $item);
        return $this;
    }

    /**
     * Put an item at a key.
     *
     * @param int|string $key Key
     * @param mixed $value Value
     * @return static
     */
    public function put(int|string $key, mixed $value): static
    {
        $this->items[$key] = $value;
        return $this;
    }

    /**
     * Forget an item by key.
     *
     * @param int|string|array $keys Keys to forget
     * @return static
     */
    public function forget(int|string|array $keys): static
    {
        foreach ((array) $keys as $key) {
            unset($this->items[$key]);
        }
        return $this;
    }
}

// ============================================================================
// RELATIONSHIPS
// ============================================================================

/**
 * Abstract base class for all relationship types.
 *
 * Relationships define how models are connected to each other.
 * NanoORM supports four relationship types:
 * - HasOne: One-to-one (parent has one child)
 * - HasMany: One-to-many (parent has many children)
 * - BelongsTo: Inverse of HasOne/HasMany
 * - BelongsToMany: Many-to-many via pivot table
 *
 * Relationships can be accessed as properties on models, which triggers
 * lazy loading. For better performance with multiple models, use
 * eager loading via Model::with().
 */
abstract class Relation
{
    /** @var Model The parent model instance */
    protected Model $parent;

    /** @var string The related model class name */
    protected string $related;

    /** @var string The foreign key column */
    protected string $foreignKey;

    /** @var string The local key column */
    protected string $localKey;

    /** @var array<mixed> Keys for eager loading */
    protected array $eagerKeys = [];

    /**
     * Create a new relationship instance.
     *
     * @param Model $parent The parent model
     * @param string $related The related model class name
     * @param string $foreignKey The foreign key column
     * @param string $localKey The local key column
     */
    public function __construct(Model $parent, string $related, string $foreignKey, string $localKey)
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    /**
     * Get the results of the relationship (lazy loading).
     *
     * @return mixed The related model(s) or null
     */
    abstract public function getResults(): mixed;

    /**
     * Add constraints for eager loading.
     *
     * @param array<Model> $models Parent models to eager load for
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Get results for eager loading.
     *
     * @return Collection|array<Model> Collection or array of related models
     */
    abstract public function getEagerResults(): Collection|array;

    /**
     * Match eager loaded results to their parent models.
     *
     * @param array<Model> $models Parent models
     * @param Collection|array<Model> $results Related models
     * @param string $relation The relationship name
     */
    abstract public function match(array $models, Collection|array $results, string $relation): void;

    /**
     * Get a new query builder for the related model.
     *
     * @return QueryBuilder A new query builder
     */
    protected function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->related);
    }

    /**
     * Forward method calls to the query builder.
     *
     * Allows using query builder methods directly on relationships.
     *
     * @param string $method The method name
     * @param array<mixed> $args The method arguments
     * @return mixed The result of the query builder method
     */
    public function __call(string $method, array $args): mixed
    {
        $query = $this->newQuery();
        return $query->$method(...$args);
    }
}

/**
 * One-to-one relationship where the related model has the foreign key.
 *
 * Example: User hasOne Profile (profiles table has user_id)
 *
 * @example
 * ```php
 * // In User model
 * public function profile(): HasOne
 * {
 *     return $this->hasOne(Profile::class);
 * }
 *
 * // Usage
 * $user = User::find(1);
 * echo $user->profile->bio;
 * ```
 */
class HasOne extends Relation
{
    public function getResults(): ?Model
    {
        $value = $this->parent->getAttribute($this->localKey);
        if ($value === null) {
            return null;
        }
        return $this->newQuery()->where($this->foreignKey, $value)->first();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = array_filter(array_unique(
            array_map(fn($m) => $m->getAttribute($this->localKey), $models)
        ));
    }

    public function getEagerResults(): Collection
    {
        if (empty($this->eagerKeys)) {
            return new Collection([]);
        }
        return $this->newQuery()->whereIn($this->foreignKey, $this->eagerKeys)->get();
    }

    public function match(array $models, Collection|array $results, string $relation): void
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }
    }
}

/**
 * One-to-many relationship where related models have the foreign key.
 *
 * Example: User hasMany Posts (posts table has user_id)
 *
 * @example
 * ```php
 * // In User model
 * public function posts(): HasMany
 * {
 *     return $this->hasMany(Post::class);
 * }
 *
 * // Usage
 * $user = User::find(1);
 * foreach ($user->posts as $post) {
 *     echo $post->title;
 * }
 * ```
 */
class HasMany extends Relation
{
    public function getResults(): Collection
    {
        $value = $this->parent->getAttribute($this->localKey);
        if ($value === null) {
            return new Collection([]);
        }
        return $this->newQuery()->where($this->foreignKey, $value)->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = array_filter(array_unique(
            array_map(fn($m) => $m->getAttribute($this->localKey), $models)
        ));
    }

    public function getEagerResults(): Collection
    {
        if (empty($this->eagerKeys)) {
            return new Collection([]);
        }
        return $this->newQuery()->whereIn($this->foreignKey, $this->eagerKeys)->get();
    }

    public function match(array $models, Collection|array $results, string $relation): void
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }
    }
}

/**
 * Inverse one-to-one or one-to-many relationship.
 *
 * Use BelongsTo when this model has the foreign key pointing to another model.
 *
 * Example: Post belongsTo User (posts table has user_id)
 *
 * @example
 * ```php
 * // In Post model
 * public function author(): BelongsTo
 * {
 *     return $this->belongsTo(User::class, 'user_id');
 * }
 *
 * // Usage
 * $post = Post::find(1);
 * echo $post->author->name;
 * ```
 */
class BelongsTo extends Relation
{
    public function getResults(): ?Model
    {
        $value = $this->parent->getAttribute($this->foreignKey);
        if ($value === null) {
            return null;
        }
        return $this->newQuery()->where($this->localKey, $value)->first();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = array_filter(array_unique(
            array_map(fn($m) => $m->getAttribute($this->foreignKey), $models)
        ));
    }

    public function getEagerResults(): Collection
    {
        if (empty($this->eagerKeys)) {
            return new Collection([]);
        }
        return $this->newQuery()->whereIn($this->localKey, $this->eagerKeys)->get();
    }

    public function match(array $models, Collection|array $results, string $relation): void
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->localKey);
            $dictionary[$key] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);
            $model->setRelation($relation, $dictionary[$key] ?? null);
        }
    }

    /**
     * Associate a model with this relationship
     */
    public function associate(Model $model): Model
    {
        $this->parent->setAttribute($this->foreignKey, $model->getAttribute($this->localKey));
        $this->parent->setRelation($this->getRelationName(), $model);
        return $this->parent;
    }

    /**
     * Dissociate the relationship
     */
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);
        return $this->parent;
    }

    private function getRelationName(): string
    {
        // Derive from foreign key
        return str_replace('_id', '', $this->foreignKey);
    }
}

/**
 * Many-to-many relationship via a pivot table.
 *
 * BelongsToMany requires an intermediate (pivot) table with foreign keys
 * to both models. The pivot table name defaults to the alphabetically
 * sorted singular table names joined with underscore (e.g., post_tag).
 *
 * Example: Post belongsToMany Tags (via post_tag pivot table)
 *
 * @example
 * ```php
 * // In Post model
 * public function tags(): BelongsToMany
 * {
 *     return $this->belongsToMany(Tag::class);
 * }
 *
 * // Usage
 * $post = Post::find(1);
 * foreach ($post->tags as $tag) {
 *     echo $tag->name;
 * }
 *
 * // Attaching/detaching
 * $post->tags()->attach([1, 2, 3]);
 * $post->tags()->detach(1);
 * $post->tags()->sync([2, 3, 4]); // Removes 1, keeps 2,3, adds 4
 * ```
 */
class BelongsToMany extends Relation
{
    /** @var string The pivot table name */
    private string $pivotTable;

    /** @var string This model's foreign key in the pivot table */
    private string $foreignPivotKey;

    /** @var string Related model's foreign key in the pivot table */
    private string $relatedPivotKey;

    /** @var array<string> Additional pivot columns to retrieve */
    private array $pivotColumns = [];

    public function __construct(
        Model $parent,
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey
    ) {
        parent::__construct($parent, $related, $foreignPivotKey, $parent::PRIMARY_KEY);
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
    }

    /**
     * Include pivot columns in results
     */
    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = array_merge($this->pivotColumns, $columns);
        return $this;
    }

    public function getResults(): Collection
    {
        $parentKey = $this->parent->getKey();
        if ($parentKey === null) {
            return new Collection([]);
        }

        $related = $this->related;
        $relatedTable = $related::getTable();
        $relatedKey = $related::PRIMARY_KEY;

        $pivotSelect = '';
        if (!empty($this->pivotColumns)) {
            $pivotSelect = ', ' . implode(', ', array_map(
                fn($c) => $this->pivotTable . '.' . $c . ' AS pivot_' . $c,
                $this->pivotColumns
            ));
        }

        $sql = "SELECT " . Model::quoteIdentifier($relatedTable) . ".*$pivotSelect "
             . "FROM " . Model::quoteIdentifier($relatedTable) . " "
             . "INNER JOIN " . Model::quoteIdentifier($this->pivotTable) . " "
             . "ON " . Model::quoteIdentifier($relatedTable) . "." . Model::quoteIdentifier($relatedKey) . " = "
             . Model::quoteIdentifier($this->pivotTable) . "." . Model::quoteIdentifier($this->relatedPivotKey) . " "
             . "WHERE " . Model::quoteIdentifier($this->pivotTable) . "." . Model::quoteIdentifier($this->foreignPivotKey) . " = ?";

        $results = $related::executeQuery($sql, [$parentKey]);
        return new Collection(array_map(fn($row) => $related::hydrate($row), $results));
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = array_filter(array_unique(
            array_map(fn($m) => $m->getKey(), $models)
        ));
    }

    public function getEagerResults(): Collection
    {
        if (empty($this->eagerKeys)) {
            return new Collection([]);
        }

        $related = $this->related;
        $relatedTable = $related::getTable();
        $relatedKey = $related::PRIMARY_KEY;

        $placeholders = implode(', ', array_fill(0, count($this->eagerKeys), '?'));

        $sql = "SELECT " . Model::quoteIdentifier($relatedTable) . ".*, "
             . Model::quoteIdentifier($this->pivotTable) . "." . Model::quoteIdentifier($this->foreignPivotKey) . " AS pivot_parent_key "
             . "FROM " . Model::quoteIdentifier($relatedTable) . " "
             . "INNER JOIN " . Model::quoteIdentifier($this->pivotTable) . " "
             . "ON " . Model::quoteIdentifier($relatedTable) . "." . Model::quoteIdentifier($relatedKey) . " = "
             . Model::quoteIdentifier($this->pivotTable) . "." . Model::quoteIdentifier($this->relatedPivotKey) . " "
             . "WHERE " . Model::quoteIdentifier($this->pivotTable) . "." . Model::quoteIdentifier($this->foreignPivotKey) . " IN ($placeholders)";

        $results = $related::executeQuery($sql, $this->eagerKeys);
        return new Collection(array_map(fn($row) => $related::hydrate($row), $results));
    }

    public function match(array $models, Collection|array $results, string $relation): void
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute('pivot_parent_key');
            $dictionary[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getKey();
            $model->setRelation($relation, new Collection($dictionary[$key] ?? []));
        }
    }

    /**
     * Attach related models
     */
    public function attach(int|string|array $ids, array $attributes = []): void
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $parentKey = $this->parent->getKey();

        foreach ($ids as $id) {
            $data = array_merge([
                $this->foreignPivotKey => $parentKey,
                $this->relatedPivotKey => $id,
            ], $attributes);

            $columns = array_keys($data);
            $columnsSql = implode(', ', array_map(fn($c) => Model::quoteIdentifier($c), $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $sql = "INSERT INTO " . Model::quoteIdentifier($this->pivotTable)
                 . " ($columnsSql) VALUES ($placeholders)";

            Model::executeStatement($sql, array_values($data));
        }
    }

    /**
     * Detach related models
     */
    public function detach(int|string|array|null $ids = null): int
    {
        $parentKey = $this->parent->getKey();

        $sql = "DELETE FROM " . Model::quoteIdentifier($this->pivotTable)
             . " WHERE " . Model::quoteIdentifier($this->foreignPivotKey) . " = ?";
        $bindings = [$parentKey];

        if ($ids !== null) {
            $ids = is_array($ids) ? $ids : [$ids];
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $sql .= " AND " . Model::quoteIdentifier($this->relatedPivotKey) . " IN ($placeholders)";
            $bindings = array_merge($bindings, $ids);
        }

        return Model::executeStatement($sql, $bindings);
    }

    /**
     * Sync related models
     */
    public function sync(array $ids): array
    {
        $current = $this->pluck($this->relatedPivotKey);

        $detached = array_diff($current, $ids);
        $attached = array_diff($ids, $current);

        if (!empty($detached)) {
            $this->detach($detached);
        }

        if (!empty($attached)) {
            $this->attach($attached);
        }

        return [
            'attached' => $attached,
            'detached' => $detached,
        ];
    }

    /**
     * Toggle related models
     */
    public function toggle(array $ids): array
    {
        $current = $this->pluck($this->relatedPivotKey);

        $detach = array_intersect($current, $ids);
        $attach = array_diff($ids, $current);

        if (!empty($detach)) {
            $this->detach($detach);
        }

        if (!empty($attach)) {
            $this->attach($attach);
        }

        return [
            'attached' => $attach,
            'detached' => $detach,
        ];
    }

    /**
     * Get pivot table column values
     */
    private function pluck(string $column): array
    {
        $parentKey = $this->parent->getKey();

        $sql = "SELECT " . Model::quoteIdentifier($column)
             . " FROM " . Model::quoteIdentifier($this->pivotTable)
             . " WHERE " . Model::quoteIdentifier($this->foreignPivotKey) . " = ?";

        $results = Model::executeQuery($sql, [$parentKey]);
        return array_column($results, $column);
    }
}

// ============================================================================
// SCHEMA BUILDER & MIGRATIONS
// ============================================================================

/**
 * Database migration runner.
 *
 * The Migrator handles running and rolling back database migrations.
 * Migration files should be PHP files that return an array with 'up' and 'down' keys.
 *
 * @example
 * ```php
 * // Run migrations
 * $migrator = new Migrator($pdo, __DIR__ . '/migrations');
 * $ran = $migrator->migrate();
 *
 * // Rollback last batch
 * $rolledBack = $migrator->rollback();
 *
 * // Reset and re-run all
 * $migrator->refresh();
 * ```
 *
 * @example Migration file format (migrations/001_create_users_table.php)
 * ```php
 * <?php
 * use NanoORM\Schema;
 * use NanoORM\Blueprint;
 *
 * return [
 *     'up' => Schema::create('users', function (Blueprint $table) {
 *         $table->id();
 *         $table->string('name');
 *         $table->string('email')->unique();
 *         $table->timestamps();
 *     }),
 *     'down' => Schema::drop('users'),
 * ];
 * ```
 */
class Migrator
{
    /** @var PDO Database connection */
    private PDO $pdo;

    /** @var string Migrations tracking table name */
    private string $table;

    /** @var string Path to migration files */
    private string $path;

    public function __construct(PDO $pdo, string $migrationsPath, string $table = 'migrations')
    {
        $this->pdo = $pdo;
        $this->path = rtrim($migrationsPath, '/');
        $this->table = $table;
        $this->ensureMigrationsTable();
    }

    /**
     * Create migrations table if not exists
     */
    private function ensureMigrationsTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'mysql' => "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT NOT NULL,
                `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                \"id\" SERIAL PRIMARY KEY,
                \"migration\" VARCHAR(255) NOT NULL,
                \"batch\" INT NOT NULL,
                \"executed_at\" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            default => "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"migration\" VARCHAR(255) NOT NULL,
                \"batch\" INTEGER NOT NULL,
                \"executed_at\" DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
        };

        $this->pdo->exec($sql);
    }

    /**
     * Run pending migrations
     */
    public function migrate(): array
    {
        $files = glob($this->path . '/*.php');
        if ($files === false) {
            $files = [];
        }

        sort($files);

        $ran = $this->getRanMigrations();
        $batch = $this->getNextBatch();
        $executed = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $ran)) {
                continue;
            }

            $migration = require $file;

            if (!is_array($migration) || !isset($migration['up'])) {
                throw new RuntimeException("Invalid migration file: $file");
            }

            $up = $migration['up'];

            if (is_callable($up)) {
                $up($this->pdo);
            } else {
                $this->pdo->exec($up);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)"
            );
            $stmt->execute([$name, $batch]);

            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * Rollback last batch of migrations
     */
    public function rollback(): array
    {
        $batch = $this->getLastBatch();

        if ($batch === 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC"
        );
        $stmt->execute([$batch]);
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $rolledBack = [];

        foreach ($migrations as $name) {
            $file = $this->path . '/' . $name . '.php';

            if (file_exists($file)) {
                $migration = require $file;

                if (isset($migration['down'])) {
                    $down = $migration['down'];

                    if (is_callable($down)) {
                        $down($this->pdo);
                    } else {
                        $this->pdo->exec($down);
                    }
                }
            }

            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?");
            $stmt->execute([$name]);

            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * Reset all migrations
     */
    public function reset(): array
    {
        $rolledBack = [];

        while (true) {
            $batch = $this->rollback();
            if (empty($batch)) {
                break;
            }
            $rolledBack = array_merge($rolledBack, $batch);
        }

        return $rolledBack;
    }

    /**
     * Reset and re-run all migrations
     */
    public function refresh(): array
    {
        $this->reset();
        return $this->migrate();
    }

    /**
     * Get list of ran migrations
     */
    public function getRanMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table}");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get pending migrations
     */
    public function getPendingMigrations(): array
    {
        $files = glob($this->path . '/*.php') ?: [];
        $ran = $this->getRanMigrations();

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $ran)) {
                $pending[] = $name;
            }
        }

        sort($pending);
        return $pending;
    }

    private function getNextBatch(): int
    {
        return $this->getLastBatch() + 1;
    }

    private function getLastBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}");
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}

/**
 * Schema builder for creating and modifying database tables.
 *
 * Provides a fluent interface for defining database schemas that
 * works across MySQL, PostgreSQL, and SQLite.
 *
 * @example
 * ```php
 * // Create a table
 * $sql = Schema::create('users', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('name');
 *     $table->string('email')->unique();
 *     $table->text('bio')->nullable();
 *     $table->boolean('active')->default(true);
 *     $table->timestamps();
 *     $table->softDeletes();
 * });
 *
 * // Drop a table
 * $sql = Schema::drop('users');
 * ```
 */
class Schema
{
    /** @var PDO|null Database connection for schema operations */
    private static ?PDO $connection = null;

    /**
     * Set the database connection for schema operations.
     *
     * @param PDO $pdo The PDO connection
     */
    public static function connection(PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    /**
     * Create a new database table.
     *
     * @param string $table The table name
     * @param callable $callback Callback that receives a Blueprint instance
     * @return string The CREATE TABLE SQL statement
     */
    public static function create(string $table, callable $callback): string
    {
        $blueprint = new Blueprint($table, self::$connection);
        $callback($blueprint);
        return $blueprint->toCreateSql();
    }

    /**
     * Modify an existing database table.
     *
     * @param string $table The table name
     * @param callable $callback Callback that receives a Blueprint instance
     * @return array<string> Array of ALTER TABLE SQL statements
     */
    public static function table(string $table, callable $callback): array
    {
        $blueprint = new Blueprint($table, self::$connection);
        $blueprint->setModifying(true);
        $callback($blueprint);
        return $blueprint->toAlterSql();
    }

    /**
     * Drop a database table.
     *
     * @param string $table The table name
     * @return string The DROP TABLE SQL statement
     */
    public static function drop(string $table): string
    {
        return "DROP TABLE IF EXISTS " . Model::quoteIdentifier($table);
    }

    /**
     * Rename a database table.
     *
     * @param string $from The current table name
     * @param string $to The new table name
     * @return string The ALTER TABLE RENAME SQL statement
     */
    public static function rename(string $from, string $to): string
    {
        return "ALTER TABLE " . Model::quoteIdentifier($from) . " RENAME TO " . Model::quoteIdentifier($to);
    }

    /**
     * Check if a table exists in the database.
     *
     * @param string $table The table name
     * @return bool True if the table exists
     *
     * @throws RuntimeException If no database connection is set
     */
    public static function hasTable(string $table): bool
    {
        if (self::$connection === null) {
            throw new RuntimeException("No database connection set for Schema");
        }

        $driver = self::$connection->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'mysql' => "SHOW TABLES LIKE ?",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE tablename = ?",
            default => "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
        };

        $stmt = self::$connection->prepare($sql);
        $stmt->execute([$table]);
        return $stmt->fetch() !== false;
    }
}

/**
 * Table blueprint for defining database table structure.
 *
 * Blueprint provides methods for defining columns, indexes, and
 * foreign keys in a fluent, chainable API. Column modifiers like
 * nullable(), default(), and unique() apply to the last defined column.
 *
 * @example
 * ```php
 * Schema::create('posts', function (Blueprint $table) {
 *     $table->id();
 *     $table->unsignedBigInteger('user_id');
 *     $table->string('title');
 *     $table->text('body');
 *     $table->boolean('is_published')->default(false);
 *     $table->json('metadata')->nullable();
 *     $table->timestamps();
 *     $table->softDeletes();
 *     $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
 * });
 * ```
 */
class Blueprint
{
    /** @var string The table name */
    private string $table;

    /** @var PDO|null Database connection for type mapping */
    private ?PDO $connection;

    /** @var array<string, array> Column definitions */
    private array $columns = [];

    /** @var array<array> Index definitions */
    private array $indexes = [];

    /** @var array<ForeignKeyDefinition> Foreign key definitions */
    private array $foreignKeys = [];

    /** @var string|null Primary key column */
    private ?string $primaryKey = null;

    /** @var bool Whether we're modifying an existing table */
    private bool $modifying = false;

    /** @var string|null The last defined column (for modifiers) */
    private ?string $lastColumn = null;

    /**
     * Create a new Blueprint instance.
     *
     * @param string $table The table name
     * @param PDO|null $connection Optional PDO connection for database-specific type mapping
     */
    public function __construct(string $table, ?PDO $connection = null)
    {
        $this->table = $table;
        $this->connection = $connection;
    }

    /**
     * Set whether this blueprint is modifying an existing table.
     *
     * @param bool $modifying True if modifying an existing table
     */
    public function setModifying(bool $modifying): void
    {
        $this->modifying = $modifying;
    }

    // ========== COLUMN TYPES ==========

    /**
     * Auto-incrementing primary key
     */
    public function id(string $name = 'id'): static
    {
        $this->columns[$name] = [
            'type' => 'bigint',
            'autoIncrement' => true,
            'unsigned' => true,
        ];
        $this->primaryKey = $name;
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * UUID primary key
     */
    public function uuid(string $name = 'id'): static
    {
        $this->columns[$name] = ['type' => 'uuid'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * String column
     */
    public function string(string $name, int $length = 255): static
    {
        $this->columns[$name] = ['type' => 'varchar', 'length' => $length];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Text column
     */
    public function text(string $name): static
    {
        $this->columns[$name] = ['type' => 'text'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Medium text column
     */
    public function mediumText(string $name): static
    {
        $this->columns[$name] = ['type' => 'mediumtext'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Long text column
     */
    public function longText(string $name): static
    {
        $this->columns[$name] = ['type' => 'longtext'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Integer column
     */
    public function integer(string $name): static
    {
        $this->columns[$name] = ['type' => 'int'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Big integer column
     */
    public function bigInteger(string $name): static
    {
        $this->columns[$name] = ['type' => 'bigint'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Small integer column
     */
    public function smallInteger(string $name): static
    {
        $this->columns[$name] = ['type' => 'smallint'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Tiny integer column
     */
    public function tinyInteger(string $name): static
    {
        $this->columns[$name] = ['type' => 'tinyint'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Unsigned big integer (for foreign keys)
     */
    public function unsignedBigInteger(string $name): static
    {
        $this->columns[$name] = ['type' => 'bigint', 'unsigned' => true];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Decimal column
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): static
    {
        $this->columns[$name] = ['type' => 'decimal', 'precision' => $precision, 'scale' => $scale];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Float column
     */
    public function float(string $name): static
    {
        $this->columns[$name] = ['type' => 'float'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Double column
     */
    public function double(string $name): static
    {
        $this->columns[$name] = ['type' => 'double'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Boolean column
     */
    public function boolean(string $name): static
    {
        $this->columns[$name] = ['type' => 'boolean'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Date column
     */
    public function date(string $name): static
    {
        $this->columns[$name] = ['type' => 'date'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Datetime column
     */
    public function datetime(string $name): static
    {
        $this->columns[$name] = ['type' => 'datetime'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Timestamp column
     */
    public function timestamp(string $name): static
    {
        $this->columns[$name] = ['type' => 'timestamp'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Time column
     */
    public function time(string $name): static
    {
        $this->columns[$name] = ['type' => 'time'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * JSON column
     */
    public function json(string $name): static
    {
        $this->columns[$name] = ['type' => 'json'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Binary/blob column
     */
    public function binary(string $name): static
    {
        $this->columns[$name] = ['type' => 'blob'];
        $this->lastColumn = $name;
        return $this;
    }

    /**
     * Enum column
     */
    public function enum(string $name, array $values): static
    {
        $this->columns[$name] = ['type' => 'enum', 'values' => $values];
        $this->lastColumn = $name;
        return $this;
    }

    // ========== COLUMN MODIFIERS ==========

    /**
     * Make column nullable
     */
    public function nullable(): static
    {
        if ($this->lastColumn) {
            $this->columns[$this->lastColumn]['nullable'] = true;
        }
        return $this;
    }

    /**
     * Set default value
     */
    public function default(mixed $value): static
    {
        if ($this->lastColumn) {
            $this->columns[$this->lastColumn]['default'] = $value;
        }
        return $this;
    }

    /**
     * Make column unsigned
     */
    public function unsigned(): static
    {
        if ($this->lastColumn) {
            $this->columns[$this->lastColumn]['unsigned'] = true;
        }
        return $this;
    }

    /**
     * Add unique constraint
     */
    public function unique(): static
    {
        if ($this->lastColumn) {
            $this->columns[$this->lastColumn]['unique'] = true;
        }
        return $this;
    }

    /**
     * Add index
     */
    public function index(?string $name = null): static
    {
        if ($this->lastColumn) {
            $this->indexes[] = [
                'columns' => [$this->lastColumn],
                'name' => $name ?? $this->table . '_' . $this->lastColumn . '_index',
            ];
        }
        return $this;
    }

    /**
     * Add primary key constraint
     */
    public function primary(): static
    {
        if ($this->lastColumn) {
            $this->primaryKey = $this->lastColumn;
        }
        return $this;
    }

    // ========== SHORTCUT COLUMNS ==========

    /**
     * Add created_at and updated_at columns
     */
    public function timestamps(): static
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        return $this;
    }

    /**
     * Add deleted_at column for soft deletes
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->timestamp($column)->nullable();
        return $this;
    }

    /**
     * Add foreign key column
     */
    public function foreignId(string $column): static
    {
        $this->unsignedBigInteger($column);
        return $this;
    }

    /**
     * Add foreign key constraint
     */
    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    /**
     * Shortcut: foreignId + foreign key constraint
     */
    public function foreignIdFor(string $model, ?string $column = null): static
    {
        $column ??= (new $model())->getForeignKey();
        $this->foreignId($column);

        $table = $model::getTable();
        $this->foreign($column)->references($model::PRIMARY_KEY)->on($table);

        return $this;
    }

    // ========== INDEX METHODS ==========

    /**
     * Add a composite index
     */
    public function addIndex(array $columns, ?string $name = null): static
    {
        $this->indexes[] = [
            'columns' => $columns,
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_index',
        ];
        return $this;
    }

    /**
     * Add a composite unique index
     */
    public function addUnique(array $columns, ?string $name = null): static
    {
        $this->indexes[] = [
            'columns' => $columns,
            'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_unique',
            'unique' => true,
        ];
        return $this;
    }

    // ========== SQL GENERATION ==========

    /**
     * Generate CREATE TABLE SQL
     */
    public function toCreateSql(): string
    {
        $driver = $this->connection?->getAttribute(PDO::ATTR_DRIVER_NAME) ?? 'mysql';
        $lines = [];

        foreach ($this->columns as $name => $def) {
            $lines[] = $this->compileColumn($name, $def, $driver);
        }

        // Primary key
        if ($this->primaryKey && !($this->columns[$this->primaryKey]['autoIncrement'] ?? false)) {
            $lines[] = "PRIMARY KEY (" . Model::quoteIdentifier($this->primaryKey) . ")";
        }

        // Unique constraints from columns
        foreach ($this->columns as $name => $def) {
            if ($def['unique'] ?? false) {
                $lines[] = "UNIQUE (" . Model::quoteIdentifier($name) . ")";
            }
        }

        // Additional indexes
        foreach ($this->indexes as $index) {
            $cols = implode(', ', array_map(fn($c) => Model::quoteIdentifier($c), $index['columns']));
            $type = ($index['unique'] ?? false) ? 'UNIQUE ' : '';
            $lines[] = "{$type}INDEX " . Model::quoteIdentifier($index['name']) . " ($cols)";
        }

        // Foreign keys
        foreach ($this->foreignKeys as $fk) {
            $lines[] = $fk->toSql();
        }

        $columnsSql = implode(",\n    ", $lines);
        return "CREATE TABLE " . Model::quoteIdentifier($this->table) . " (\n    $columnsSql\n)";
    }

    /**
     * Generate ALTER TABLE SQL statements
     */
    public function toAlterSql(): array
    {
        $driver = $this->connection?->getAttribute(PDO::ATTR_DRIVER_NAME) ?? 'mysql';
        $statements = [];

        foreach ($this->columns as $name => $def) {
            $columnSql = $this->compileColumn($name, $def, $driver);
            $statements[] = "ALTER TABLE " . Model::quoteIdentifier($this->table) . " ADD COLUMN $columnSql";
        }

        return $statements;
    }

    /**
     * Compile a column definition to SQL
     */
    private function compileColumn(string $name, array $def, string $driver): string
    {
        $type = $this->mapType($def['type'], $def, $driver);
        $sql = Model::quoteIdentifier($name) . ' ' . $type;

        // Unsigned (MySQL only)
        if (($def['unsigned'] ?? false) && $driver === 'mysql') {
            $sql .= ' UNSIGNED';
        }

        // Nullable
        if (!($def['nullable'] ?? false)) {
            $sql .= ' NOT NULL';
        }

        // Default
        if (array_key_exists('default', $def)) {
            $default = $def['default'];
            if ($default === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (is_bool($default)) {
                $sql .= ' DEFAULT ' . ($default ? '1' : '0');
            } elseif (is_int($default) || is_float($default)) {
                $sql .= " DEFAULT $default";
            } elseif ($default === 'CURRENT_TIMESTAMP') {
                $sql .= ' DEFAULT CURRENT_TIMESTAMP';
            } else {
                $sql .= " DEFAULT '$default'";
            }
        }

        // Auto increment
        if ($def['autoIncrement'] ?? false) {
            $sql .= match ($driver) {
                'mysql' => ' AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => '', // handled by SERIAL type
                default => ' PRIMARY KEY AUTOINCREMENT',
            };
        }

        return $sql;
    }

    /**
     * Map column type to database-specific type
     */
    private function mapType(string $type, array $def, string $driver): string
    {
        // Handle auto-increment special case for PostgreSQL
        if (($def['autoIncrement'] ?? false) && $driver === 'pgsql') {
            return $type === 'bigint' ? 'BIGSERIAL' : 'SERIAL';
        }

        // Handle auto-increment special case for SQLite (requires INTEGER, not BIGINT)
        if (($def['autoIncrement'] ?? false) && $driver === 'sqlite') {
            return 'INTEGER';
        }

        return match ($type) {
            'varchar' => "VARCHAR(" . ($def['length'] ?? 255) . ")",
            'decimal' => "DECIMAL(" . ($def['precision'] ?? 8) . "," . ($def['scale'] ?? 2) . ")",
            'enum' => $driver === 'mysql'
                ? "ENUM('" . implode("','", $def['values']) . "')"
                : "VARCHAR(255)",
            'boolean' => match ($driver) {
                'pgsql' => 'BOOLEAN',
                default => 'TINYINT(1)',
            },
            'json' => match ($driver) {
                'mysql', 'pgsql' => 'JSON',
                default => 'TEXT',
            },
            'uuid' => match ($driver) {
                'pgsql' => 'UUID',
                default => 'CHAR(36)',
            },
            'mediumtext' => match ($driver) {
                'mysql' => 'MEDIUMTEXT',
                default => 'TEXT',
            },
            'longtext' => match ($driver) {
                'mysql' => 'LONGTEXT',
                default => 'TEXT',
            },
            default => strtoupper($type),
        };
    }
}

/**
 * Fluent builder for defining foreign key constraints.
 *
 * Used within a Blueprint to define foreign key relationships
 * between tables.
 *
 * @example
 * ```php
 * // Basic foreign key
 * $table->foreign('user_id')->references('id')->on('users');
 *
 * // With cascade delete
 * $table->foreign('user_id')
 *     ->references('id')
 *     ->on('users')
 *     ->cascadeOnDelete();
 *
 * // Set null on delete
 * $table->foreign('category_id')
 *     ->references('id')
 *     ->on('categories')
 *     ->nullOnDelete();
 * ```
 */
class ForeignKeyDefinition
{
    /** @var string The column that holds the foreign key */
    private string $column;

    /** @var string|null The referenced column in the foreign table */
    private ?string $referencedColumn = null;

    /** @var string|null The foreign table name */
    private ?string $referencedTable = null;

    /** @var string Action on delete (RESTRICT, CASCADE, SET NULL, NO ACTION) */
    private string $onDelete = 'RESTRICT';

    /** @var string Action on update (RESTRICT, CASCADE, SET NULL, NO ACTION) */
    private string $onUpdate = 'RESTRICT';

    /**
     * Create a new foreign key definition.
     *
     * @param string $column The column that holds the foreign key
     */
    public function __construct(string $column)
    {
        $this->column = $column;
    }

    /**
     * Set the referenced column in the foreign table.
     *
     * @param string $column The referenced column name
     * @return static
     */
    public function references(string $column): static
    {
        $this->referencedColumn = $column;
        return $this;
    }

    /**
     * Set the foreign table name.
     *
     * @param string $table The table name
     * @return static
     */
    public function on(string $table): static
    {
        $this->referencedTable = $table;
        return $this;
    }

    /**
     * Set the ON DELETE action.
     *
     * @param string $action The action (RESTRICT, CASCADE, SET NULL, NO ACTION)
     * @return static
     */
    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    /**
     * Set the ON UPDATE action.
     *
     * @param string $action The action (RESTRICT, CASCADE, SET NULL, NO ACTION)
     * @return static
     */
    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /**
     * Set ON DELETE CASCADE - deletes child rows when parent is deleted.
     *
     * @return static
     */
    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    /**
     * Set ON UPDATE CASCADE - updates child rows when parent key changes.
     *
     * @return static
     */
    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    /**
     * Set ON DELETE SET NULL - sets child foreign key to NULL when parent is deleted.
     *
     * @return static
     */
    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    /**
     * Generate the SQL for this foreign key constraint.
     *
     * @return string The FOREIGN KEY SQL clause
     */
    public function toSql(): string
    {
        return "FOREIGN KEY (" . Model::quoteIdentifier($this->column) . ") "
             . "REFERENCES " . Model::quoteIdentifier($this->referencedTable) . " "
             . "(" . Model::quoteIdentifier($this->referencedColumn) . ") "
             . "ON DELETE {$this->onDelete} ON UPDATE {$this->onUpdate}";
    }
}
