<?php
/**
 * NanoORM Example - Model Definitions
 *
 * This file demonstrates how to define models with NanoORM.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoORM.php';

use NanoORM\Model;
use NanoORM\HasOne;
use NanoORM\HasMany;
use NanoORM\BelongsTo;
use NanoORM\BelongsToMany;

/**
 * User Model
 *
 * Demonstrates: timestamps, soft deletes, casting, hidden attributes, relationships
 */
class User extends Model
{
    public const ?string TABLE = 'users';
    public const bool TIMESTAMPS = true;
    public const bool SOFT_DELETES = true;

    public const array CASTS = [
        'is_admin' => 'boolean',
        'settings' => 'json',
        'email_verified_at' => 'datetime',
    ];

    public const array HIDDEN = ['password'];

    /**
     * User's profile (one-to-one)
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * User's blog posts (one-to-many)
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * User's comments (one-to-many)
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Event: Generate UUID on creation
     */
    protected function onCreating(): void
    {
        if (empty($this->uuid)) {
            $this->uuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }
    }
}

/**
 * Profile Model
 *
 * Demonstrates: belongs-to relationship
 */
class Profile extends Model
{
    public const ?string TABLE = 'profiles';

    /**
     * Profile's owner
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

/**
 * Post Model
 *
 * Demonstrates: timestamps, casting, multiple relationships
 */
class Post extends Model
{
    public const ?string TABLE = 'posts';
    public const bool TIMESTAMPS = true;

    public const array CASTS = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'metadata' => 'json',
    ];

    /**
     * Post's author
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Post's comments
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Post's tags (many-to-many)
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }

    /**
     * Scope: Published posts only
     */
    public static function published(): \NanoORM\QueryBuilder
    {
        return static::where('is_published', true)
            ->whereNotNull('published_at');
    }
}

/**
 * Comment Model
 *
 * Demonstrates: timestamps, multiple belongs-to relationships
 */
class Comment extends Model
{
    public const ?string TABLE = 'comments';
    public const bool TIMESTAMPS = true;

    /**
     * Comment's post
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Comment's author
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

/**
 * Tag Model
 *
 * Demonstrates: many-to-many relationship
 */
class Tag extends Model
{
    public const ?string TABLE = 'tags';

    /**
     * Tag's posts
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }
}
