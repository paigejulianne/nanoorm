<?php
/**
 * NanoORM Test Bootstrap
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoORM.php';

use NanoORM\Model;
use NanoORM\Schema;
use NanoORM\Blueprint;

// Alias for tests
class_alias(Model::class, 'Model');

/**
 * Test User Model
 */
class User extends Model
{
    public const ?string TABLE = 'users';
    public const bool TIMESTAMPS = true;
    public const bool SOFT_DELETES = true;
    public const array CASTS = [
        'is_admin' => 'boolean',
        'settings' => 'json',
    ];
    public const array HIDDEN = ['password'];

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

/**
 * Test Post Model
 */
class Post extends Model
{
    public const ?string TABLE = 'posts';
    public const bool TIMESTAMPS = true;

    public function author(): \NanoORM\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): \NanoORM\HasMany
    {
        return $this->hasMany(Comment::class);
    }
}

/**
 * Test Comment Model
 */
class Comment extends Model
{
    public const ?string TABLE = 'comments';
    public const bool TIMESTAMPS = true;

    public function post(): \NanoORM\BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function author(): \NanoORM\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

/**
 * Test Profile Model
 */
class Profile extends Model
{
    public const ?string TABLE = 'profiles';

    public function user(): \NanoORM\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * Test Role Model
 */
class Role extends Model
{
    public const ?string TABLE = 'roles';

    public function users(): \NanoORM\BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}

/**
 * Base test case with database setup
 */
abstract class NanoORMTestCase extends \PHPUnit\Framework\TestCase
{
    protected static ?\PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        // Use file-based SQLite for consistency across connections
        $dbPath = sys_get_temp_dir() . '/nanoorm_test_' . getmypid() . '.db';

        // Remove old database file if exists
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        Model::addConnection('default', 'sqlite:' . $dbPath);
        self::$pdo = Model::getConnection('default');
        Schema::connection(self::$pdo);

        // Create tables
        self::$pdo->exec(Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->text('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        }));

        self::$pdo->exec(Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->integer('views')->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->float('rating')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        }));

        self::$pdo->exec(Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        }));

        self::$pdo->exec(Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('bio')->nullable();
            $table->string('website')->nullable();
        }));

        self::$pdo->exec(Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        }));

        self::$pdo->exec(Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        }));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear all tables
        self::$pdo->exec('DELETE FROM comments');
        self::$pdo->exec('DELETE FROM posts');
        self::$pdo->exec('DELETE FROM profiles');
        self::$pdo->exec('DELETE FROM role_user');
        self::$pdo->exec('DELETE FROM roles');
        self::$pdo->exec('DELETE FROM users');

        // Clear identity map
        Model::clearIdentityMap();
        Model::flushQueryLog();
    }

    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'test' . uniqid() . '@example.com',
            'password' => 'secret',
        ], $attributes));
    }

    protected function createPost(User $user, array $attributes = []): Post
    {
        return Post::create(array_merge([
            'user_id' => $user->getKey(),
            'title' => 'Test Post',
            'body' => 'Test body content',
        ], $attributes));
    }
}
