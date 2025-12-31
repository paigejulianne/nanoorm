<?php
/**
 * NanoORM Demo Application
 *
 * This script demonstrates the main features of NanoORM using a blog example.
 * Run with: php demo.php
 */

declare(strict_types=1);

require_once __DIR__ . '/models.php';

use NanoORM\Model;
use NanoORM\Schema;
use NanoORM\Migrator;

// =============================================================================
// SETUP: Configure database connection
// =============================================================================

echo "=== NanoORM Demo Application ===\n\n";

// Use SQLite for this demo (no configuration needed)
$dbPath = __DIR__ . '/demo.db';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

Model::addConnection('default', 'sqlite:' . $dbPath);
$pdo = Model::getConnection();
Schema::connection($pdo);

// =============================================================================
// MIGRATIONS: Set up the database schema
// =============================================================================

echo "1. Running migrations...\n";

$migrator = new Migrator($pdo, __DIR__ . '/migrations');
$ran = $migrator->migrate();

foreach ($ran as $migration) {
    echo "   - Migrated: $migration\n";
}
echo "\n";

// =============================================================================
// CREATING RECORDS
// =============================================================================

echo "2. Creating records...\n";

// Create users
$alice = User::create([
    'name' => 'Alice Johnson',
    'email' => 'alice@example.com',
    'password' => password_hash('secret123', PASSWORD_DEFAULT),
    'is_admin' => true,
    'settings' => ['theme' => 'dark', 'notifications' => true],
]);
echo "   - Created user: {$alice->name} (ID: {$alice->getKey()})\n";

$bob = User::create([
    'name' => 'Bob Smith',
    'email' => 'bob@example.com',
    'password' => password_hash('secret456', PASSWORD_DEFAULT),
    'is_admin' => false,
]);
echo "   - Created user: {$bob->name} (ID: {$bob->getKey()})\n";

$charlie = User::create([
    'name' => 'Charlie Brown',
    'email' => 'charlie@example.com',
    'password' => password_hash('secret789', PASSWORD_DEFAULT),
]);
echo "   - Created user: {$charlie->name} (ID: {$charlie->getKey()})\n";

// Create profiles
Profile::create([
    'user_id' => $alice->getKey(),
    'bio' => 'Software engineer and tech blogger',
    'website' => 'https://alice.dev',
    'location' => 'San Francisco, CA',
]);
echo "   - Created profile for Alice\n";

Profile::create([
    'user_id' => $bob->getKey(),
    'bio' => 'Full-stack developer',
    'location' => 'New York, NY',
]);
echo "   - Created profile for Bob\n";

// Create tags
$tags = [];
foreach (['PHP', 'ORM', 'Database', 'Tutorial', 'Web Development'] as $name) {
    $tags[$name] = Tag::create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)),
    ]);
}
echo "   - Created " . count($tags) . " tags\n";

// Create posts
$post1 = Post::create([
    'user_id' => $alice->getKey(),
    'title' => 'Getting Started with NanoORM',
    'slug' => 'getting-started-with-nanoorm',
    'excerpt' => 'Learn how to use NanoORM in your PHP projects.',
    'body' => 'NanoORM is a lightweight ORM that makes database operations simple...',
    'is_published' => true,
    'published_at' => date('Y-m-d H:i:s'),
    'metadata' => ['featured' => true, 'read_time' => 5],
]);
echo "   - Created post: {$post1->title}\n";

$post2 = Post::create([
    'user_id' => $alice->getKey(),
    'title' => 'Advanced Query Building',
    'slug' => 'advanced-query-building',
    'excerpt' => 'Master the fluent query builder.',
    'body' => 'The query builder provides a convenient interface for creating queries...',
    'is_published' => true,
    'published_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
]);
echo "   - Created post: {$post2->title}\n";

$post3 = Post::create([
    'user_id' => $bob->getKey(),
    'title' => 'Working with Relationships',
    'slug' => 'working-with-relationships',
    'excerpt' => 'Understanding model relationships.',
    'body' => 'NanoORM supports HasOne, HasMany, BelongsTo, and BelongsToMany...',
    'is_published' => false, // Draft
]);
echo "   - Created post: {$post3->title} (draft)\n";

// Attach tags to posts (many-to-many)
$post1->tags()->attach([$tags['PHP']->getKey(), $tags['ORM']->getKey(), $tags['Tutorial']->getKey()]);
$post2->tags()->attach([$tags['PHP']->getKey(), $tags['Database']->getKey()]);
$post3->tags()->attach([$tags['ORM']->getKey(), $tags['Web Development']->getKey()]);
echo "   - Attached tags to posts\n";

// Create comments
Comment::create([
    'post_id' => $post1->getKey(),
    'user_id' => $bob->getKey(),
    'body' => 'Great introduction! Very helpful.',
]);

Comment::create([
    'post_id' => $post1->getKey(),
    'user_id' => $charlie->getKey(),
    'body' => 'Thanks for writing this. Looking forward to more tutorials.',
]);

Comment::create([
    'post_id' => $post2->getKey(),
    'user_id' => $charlie->getKey(),
    'body' => 'The query builder is so intuitive!',
]);
echo "   - Created 3 comments\n\n";

// =============================================================================
// QUERYING: Demonstrating the query builder
// =============================================================================

echo "3. Query examples...\n\n";

// Simple where
echo "   a) Find admin users:\n";
$admins = User::where('is_admin', true)->get();
foreach ($admins as $admin) {
    echo "      - {$admin->name}\n";
}
echo "\n";

// Multiple conditions
echo "   b) Published posts ordered by date:\n";
$publishedPosts = Post::where('is_published', true)
    ->orderBy('published_at', 'DESC')
    ->get();
foreach ($publishedPosts as $post) {
    echo "      - {$post->title}\n";
}
echo "\n";

// Using whereIn
echo "   c) Users with specific IDs:\n";
$specificUsers = User::whereIn('id', [1, 3])->pluck('name');
echo "      - " . implode(', ', $specificUsers) . "\n\n";

// Count and aggregates
echo "   d) Aggregates:\n";
echo "      - Total users: " . User::count() . "\n";
echo "      - Total posts: " . Post::count() . "\n";
echo "      - Published posts: " . Post::where('is_published', true)->count() . "\n\n";

// =============================================================================
// RELATIONSHIPS: Demonstrating eager loading
// =============================================================================

echo "4. Relationship examples...\n\n";

// Eager loading to prevent N+1
echo "   a) Users with their posts (eager loaded):\n";
Model::enableQueryLog();
Model::flushQueryLog();

$usersWithPosts = User::with('posts')->get();
foreach ($usersWithPosts as $user) {
    $postCount = count($user->posts);
    echo "      - {$user->name}: $postCount post(s)\n";
}

$queries = Model::getQueryLog();
echo "      (Executed " . count($queries) . " queries with eager loading)\n\n";
Model::disableQueryLog();

// Nested eager loading
echo "   b) Posts with author and comments:\n";
$posts = Post::with('author', 'comments')->where('is_published', true)->get();
foreach ($posts as $post) {
    $commentCount = count($post->comments);
    echo "      - \"{$post->title}\" by {$post->author->name} ({$commentCount} comments)\n";
}
echo "\n";

// Many-to-many
echo "   c) Post tags (many-to-many):\n";
$post = Post::find(1);
$tagNames = array_map(fn($t) => $t->name, $post->tags);
echo "      - \"{$post->title}\" tags: " . implode(', ', $tagNames) . "\n\n";

// BelongsTo
echo "   d) User profile (one-to-one):\n";
$alice = User::find(1);
if ($alice->profile) {
    echo "      - {$alice->name}'s bio: {$alice->profile->bio}\n\n";
}

// =============================================================================
// UPDATING: Demonstrating updates and dirty checking
// =============================================================================

echo "5. Update examples...\n\n";

// Update single record
echo "   a) Updating a post:\n";
$post = Post::find(1);
echo "      - Original title: {$post->title}\n";
$post->title = 'Introduction to NanoORM';
echo "      - Is dirty: " . ($post->isDirty() ? 'yes' : 'no') . "\n";
echo "      - Dirty fields: " . implode(', ', array_keys($post->getDirty())) . "\n";
$post->save();
echo "      - Saved! Is dirty now: " . ($post->isDirty() ? 'yes' : 'no') . "\n\n";

// Atomic increment
echo "   b) Atomic increment (view count):\n";
$post = Post::find(1);
echo "      - Views before: {$post->view_count}\n";
$post->increment('view_count');
$post->increment('view_count', 5);
echo "      - Views after: {$post->view_count}\n\n";

// Bulk update
echo "   c) Bulk update:\n";
$affected = Post::where('is_published', false)->update(['is_published' => true]);
echo "      - Published $affected draft post(s)\n\n";

// =============================================================================
// SOFT DELETES
// =============================================================================

echo "6. Soft delete examples...\n\n";

echo "   a) Deleting a user (soft delete):\n";
$charlie = User::find(3);
echo "      - Deleting: {$charlie->name}\n";
$charlie->delete();
echo "      - Is trashed: " . ($charlie->trashed() ? 'yes' : 'no') . "\n";

echo "   b) Querying with soft deletes:\n";
echo "      - Active users: " . User::count() . "\n";
echo "      - All users (with trashed): " . User::query()->withTrashed()->count() . "\n";
echo "      - Only trashed: " . User::query()->onlyTrashed()->count() . "\n";

echo "   c) Restoring deleted user:\n";
$charlie->restore();
echo "      - Restored! Active users now: " . User::count() . "\n\n";

// =============================================================================
// TRANSACTIONS
// =============================================================================

echo "7. Transaction example...\n\n";

echo "   Creating user with profile in transaction:\n";
try {
    Model::transaction(function () {
        $user = User::create([
            'name' => 'Diana Prince',
            'email' => 'diana@example.com',
            'password' => password_hash('wonder', PASSWORD_DEFAULT),
        ]);

        Profile::create([
            'user_id' => $user->getKey(),
            'bio' => 'Amazon warrior princess',
            'location' => 'Themyscira',
        ]);

        echo "   - Created user and profile successfully!\n";
    });
} catch (Exception $e) {
    echo "   - Transaction failed: " . $e->getMessage() . "\n";
}
echo "\n";

// =============================================================================
// PAGINATION
// =============================================================================

echo "8. Pagination example...\n\n";

// Create more posts for pagination demo
for ($i = 1; $i <= 10; $i++) {
    Post::create([
        'user_id' => 1,
        'title' => "Sample Post $i",
        'slug' => "sample-post-$i",
        'body' => "Content for sample post $i",
        'is_published' => true,
    ]);
}

$page = Post::orderBy('id')->paginate(5, 1);
echo "   Page 1 of {$page['last_page']}:\n";
echo "   - Showing {$page['from']} to {$page['to']} of {$page['total']} posts\n";
foreach ($page['data'] as $post) {
    echo "   - {$post->title}\n";
}
echo "\n";

// =============================================================================
// JSON SERIALIZATION
// =============================================================================

echo "9. JSON serialization example...\n\n";

$user = User::with('profile', 'posts')->find(1);
$json = $user->toJson(JSON_PRETTY_PRINT);
echo "   User as JSON (password hidden):\n";
echo preg_replace('/^/m', '   ', $json) . "\n\n";

// =============================================================================
// CLEANUP
// =============================================================================

echo "10. Cleanup...\n\n";
echo "    Demo database saved to: $dbPath\n";
echo "    You can inspect it with: sqlite3 $dbPath\n\n";

echo "=== Demo Complete ===\n";
