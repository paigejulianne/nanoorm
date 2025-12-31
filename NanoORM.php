<?php
/**
 * NanoORM - A Lightweight, Full-Featured PHP ORM
 *
 * @version 1.0.0
 * @author Paige Julianne
 * @license MIT
 * @requires PHP 8.1+, PDO
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

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ========== STATIC FACTORY METHODS ==========

    /**
     * Create a new model instance and save it
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Hydrate a model from database row
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
     * Start a new query builder
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::class);
    }

    /**
     * Find by primary key
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
     * Find by primary key or throw exception
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
     * Find multiple by primary keys
     */
    public static function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return static::query()->whereIn(static::PRIMARY_KEY, $ids)->get();
    }

    /**
     * Get all records
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Count all records
     */
    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Start a where query
     */
    public static function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Start a whereIn query
     */
    public static function whereIn(string $column, array $values): QueryBuilder
    {
        return static::query()->whereIn($column, $values);
    }

    /**
     * Start a whereNotIn query
     */
    public static function whereNotIn(string $column, array $values): QueryBuilder
    {
        return static::query()->whereNotIn($column, $values);
    }

    /**
     * Start a whereNull query
     */
    public static function whereNull(string $column): QueryBuilder
    {
        return static::query()->whereNull($column);
    }

    /**
     * Start a whereNotNull query
     */
    public static function whereNotNull(string $column): QueryBuilder
    {
        return static::query()->whereNotNull($column);
    }

    /**
     * Start a whereBetween query
     */
    public static function whereBetween(string $column, mixed $min, mixed $max): QueryBuilder
    {
        return static::query()->whereBetween($column, $min, $max);
    }

    /**
     * Start an orderBy query
     */
    public static function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return static::query()->orderBy($column, $direction);
    }

    /**
     * Order by latest (descending)
     */
    public static function latest(string $column = 'created_at'): QueryBuilder
    {
        return static::query()->latest($column);
    }

    /**
     * Order by oldest (ascending)
     */
    public static function oldest(string $column = 'created_at'): QueryBuilder
    {
        return static::query()->oldest($column);
    }

    /**
     * Pluck values from column
     */
    public static function pluck(string $column, ?string $key = null): array
    {
        return static::query()->pluck($column, $key);
    }

    /**
     * Eager load relationships
     */
    public static function with(string|array ...$relations): QueryBuilder
    {
        return static::query()->with(...$relations);
    }

    /**
     * Find or create a record
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
     * Update or create a record
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
     * Bulk insert records
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
     * Bulk insert and return inserted IDs
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
     * Get attribute with casting
     */
    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        // Apply cast if defined
        if (isset(static::CASTS[$key]) && $value !== null) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Set attribute with reverse casting
     */
    public function setAttribute(string $key, mixed $value): static
    {
        // Apply reverse cast if defined
        if (isset(static::CASTS[$key]) && $value !== null) {
            $value = $this->uncastAttribute($key, $value);
        }

        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Fill multiple attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
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
     * Get relationship value (lazy loading)
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
     * Set a relationship value
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    /**
     * Load relationships onto the model
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
     * Define a HasOne relationship
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::PRIMARY_KEY;

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a HasMany relationship
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= static::PRIMARY_KEY;

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a BelongsTo relationship
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey ??= $this->guessBelongsToForeignKey($related);
        $ownerKey ??= $related::PRIMARY_KEY;

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Define a BelongsToMany relationship
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
     * Get foreign key name for this model
     */
    public function getForeignKey(): string
    {
        $table = static::getTable();
        return rtrim($table, 's') . '_id';
    }

    /**
     * Guess foreign key for belongsTo
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

    /**
     * Convert model to array
     */
    public function toArray(): array
    {
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
            if (is_array($relation)) {
                $attributes[$key] = array_map(fn($m) => $m->toArray(), $relation);
            } elseif ($relation instanceof self) {
                $attributes[$key] = $relation->toArray();
            } else {
                $attributes[$key] = $relation;
            }
        }

        return $attributes;
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
        // Call model method if exists
        $method = 'on' . ucfirst($event);
        if (method_exists($this, $method)) {
            $this->$method();
        }

        // Call registered listeners
        $listeners = self::$eventListeners[static::class][$event] ?? [];
        foreach ($listeners as $listener) {
            $listener($this);
        }
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
     * Begin a transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection(static::CONNECTION)->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public static function commit(): bool
    {
        return self::getConnection(static::CONNECTION)->commit();
    }

    /**
     * Rollback a transaction
     */
    public static function rollback(): bool
    {
        return self::getConnection(static::CONNECTION)->rollBack();
    }

    /**
     * Execute callback in a transaction
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

class QueryBuilder
{
    private string $model;
    private array $wheres = [];
    private array $orderBys = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private array $columns = ['*'];
    private array $groupBy = [];
    private array $having = [];
    private bool $withTrashed = false;
    private bool $onlyTrashed = false;
    private array $eagerLoad = [];

    public function __construct(string $model)
    {
        $this->model = $model;
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
     * Execute query and return models
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->toSql();

        $model = $this->model;
        $results = $model::executeQuery($sql, $bindings);

        $models = array_map(fn($row) => $model::hydrate($row), $results);

        // Eager load relationships
        if (!empty($this->eagerLoad) && !empty($models)) {
            $this->loadRelations($models);
        }

        return $models;
    }

    /**
     * Get first result
     */
    public function first(): ?Model
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
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
        $table = $model::getTable();
        $bindings = [];

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
                if (is_array($related)) {
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
// RELATIONSHIPS
// ============================================================================

/**
 * Base Relation class
 */
abstract class Relation
{
    protected Model $parent;
    protected string $related;
    protected string $foreignKey;
    protected string $localKey;
    protected array $eagerKeys = [];

    public function __construct(Model $parent, string $related, string $foreignKey, string $localKey)
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    /**
     * Get the results of the relationship
     */
    abstract public function getResults(): mixed;

    /**
     * Add constraints for eager loading
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Get results for eager loading
     */
    abstract public function getEagerResults(): array;

    /**
     * Match eager loaded results to their parent models
     */
    abstract public function match(array $models, array $results, string $relation): void;

    /**
     * Get a new query builder for the related model
     */
    protected function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->related);
    }

    /**
     * Forward method calls to query builder
     */
    public function __call(string $method, array $args): mixed
    {
        $query = $this->newQuery();
        return $query->$method(...$args);
    }
}

/**
 * HasOne relationship
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

    public function getEagerResults(): array
    {
        if (empty($this->eagerKeys)) {
            return [];
        }
        return $this->newQuery()->whereIn($this->foreignKey, $this->eagerKeys)->get();
    }

    public function match(array $models, array $results, string $relation): void
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
 * HasMany relationship
 */
class HasMany extends Relation
{
    public function getResults(): array
    {
        $value = $this->parent->getAttribute($this->localKey);
        if ($value === null) {
            return [];
        }
        return $this->newQuery()->where($this->foreignKey, $value)->get();
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = array_filter(array_unique(
            array_map(fn($m) => $m->getAttribute($this->localKey), $models)
        ));
    }

    public function getEagerResults(): array
    {
        if (empty($this->eagerKeys)) {
            return [];
        }
        return $this->newQuery()->whereIn($this->foreignKey, $this->eagerKeys)->get();
    }

    public function match(array $models, array $results, string $relation): void
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);
            $dictionary[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);
            $model->setRelation($relation, $dictionary[$key] ?? []);
        }
    }
}

/**
 * BelongsTo relationship
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

    public function getEagerResults(): array
    {
        if (empty($this->eagerKeys)) {
            return [];
        }
        return $this->newQuery()->whereIn($this->localKey, $this->eagerKeys)->get();
    }

    public function match(array $models, array $results, string $relation): void
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
 * BelongsToMany relationship (Many-to-Many)
 */
class BelongsToMany extends Relation
{
    private string $pivotTable;
    private string $foreignPivotKey;
    private string $relatedPivotKey;
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

    public function getResults(): array
    {
        $parentKey = $this->parent->getKey();
        if ($parentKey === null) {
            return [];
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
        return array_map(fn($row) => $related::hydrate($row), $results);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerKeys = array_filter(array_unique(
            array_map(fn($m) => $m->getKey(), $models)
        ));
    }

    public function getEagerResults(): array
    {
        if (empty($this->eagerKeys)) {
            return [];
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
        return array_map(fn($row) => $related::hydrate($row), $results);
    }

    public function match(array $models, array $results, string $relation): void
    {
        $dictionary = [];
        foreach ($results as $result) {
            $key = $result->getAttribute('pivot_parent_key');
            $dictionary[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getKey();
            $model->setRelation($relation, $dictionary[$key] ?? []);
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
 * Simple migration runner
 */
class Migrator
{
    private PDO $pdo;
    private string $table;
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
 * Schema builder helper
 */
class Schema
{
    private static ?PDO $connection = null;

    /**
     * Set the connection for schema operations
     */
    public static function connection(PDO $pdo): void
    {
        self::$connection = $pdo;
    }

    /**
     * Create a table
     */
    public static function create(string $table, callable $callback): string
    {
        $blueprint = new Blueprint($table, self::$connection);
        $callback($blueprint);
        return $blueprint->toCreateSql();
    }

    /**
     * Modify a table
     */
    public static function table(string $table, callable $callback): array
    {
        $blueprint = new Blueprint($table, self::$connection);
        $blueprint->setModifying(true);
        $callback($blueprint);
        return $blueprint->toAlterSql();
    }

    /**
     * Drop a table
     */
    public static function drop(string $table): string
    {
        return "DROP TABLE IF EXISTS " . Model::quoteIdentifier($table);
    }

    /**
     * Rename a table
     */
    public static function rename(string $from, string $to): string
    {
        return "ALTER TABLE " . Model::quoteIdentifier($from) . " RENAME TO " . Model::quoteIdentifier($to);
    }

    /**
     * Check if table exists
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
 * Table blueprint for schema building
 */
class Blueprint
{
    private string $table;
    private ?PDO $connection;
    private array $columns = [];
    private array $indexes = [];
    private array $foreignKeys = [];
    private ?string $primaryKey = null;
    private bool $modifying = false;
    private ?string $lastColumn = null;

    public function __construct(string $table, ?PDO $connection = null)
    {
        $this->table = $table;
        $this->connection = $connection;
    }

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
 * Foreign key definition builder
 */
class ForeignKeyDefinition
{
    private string $column;
    private ?string $referencedColumn = null;
    private ?string $referencedTable = null;
    private string $onDelete = 'RESTRICT';
    private string $onUpdate = 'RESTRICT';

    public function __construct(string $column)
    {
        $this->column = $column;
    }

    public function references(string $column): static
    {
        $this->referencedColumn = $column;
        return $this;
    }

    public function on(string $table): static
    {
        $this->referencedTable = $table;
        return $this;
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    public function toSql(): string
    {
        return "FOREIGN KEY (" . Model::quoteIdentifier($this->column) . ") "
             . "REFERENCES " . Model::quoteIdentifier($this->referencedTable) . " "
             . "(" . Model::quoteIdentifier($this->referencedColumn) . ") "
             . "ON DELETE {$this->onDelete} ON UPDATE {$this->onUpdate}";
    }
}
