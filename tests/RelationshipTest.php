<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class RelationshipTest extends NanoORMTestCase
{
    public function testHasMany(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $post1 = $this->createPost($user, ['title' => 'Post 1']);
        $post2 = $this->createPost($user, ['title' => 'Post 2']);

        $posts = $user->posts;

        $this->assertCount(2, $posts);
        $this->assertContains('Post 1', array_map(fn($p) => $p->title, $posts));
        $this->assertContains('Post 2', array_map(fn($p) => $p->title, $posts));
    }

    public function testHasManyReturnsEmptyArrayWhenNone(): void
    {
        $user = $this->createUser();

        $posts = $user->posts;

        $this->assertIsArray($posts);
        $this->assertEmpty($posts);
    }

    public function testBelongsTo(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $post = $this->createPost($user, ['title' => 'My Post']);

        Model::clearIdentityMap();
        $loadedPost = Post::find($post->getKey());

        $author = $loadedPost->author;

        $this->assertInstanceOf(User::class, $author);
        $this->assertEquals('Author', $author->name);
    }

    public function testBelongsToReturnsNullWhenForeignKeyIsNull(): void
    {
        $user = $this->createUser();
        $post = Post::create([
            'user_id' => $user->getKey(),
            'title' => 'Orphan Post',
        ]);

        // Set user_id to null after creation
        self::$pdo->exec("UPDATE posts SET user_id = NULL WHERE id = " . $post->getKey());

        Model::clearIdentityMap();
        $loadedPost = Post::find($post->getKey());
        $loadedPost->setAttribute('user_id', null);

        $author = $loadedPost->author;

        $this->assertNull($author);
    }

    public function testHasOne(): void
    {
        $user = $this->createUser(['name' => 'User With Profile']);

        Profile::create([
            'user_id' => $user->getKey(),
            'bio' => 'My bio',
            'website' => 'https://example.com',
        ]);

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());

        $profile = $loadedUser->profile;

        $this->assertInstanceOf(Profile::class, $profile);
        $this->assertEquals('My bio', $profile->bio);
    }

    public function testHasOneReturnsNullWhenNone(): void
    {
        $user = $this->createUser();

        $profile = $user->profile;

        $this->assertNull($profile);
    }

    public function testBelongsToMany(): void
    {
        $user = $this->createUser(['name' => 'User With Roles']);

        $role1 = Role::create(['name' => 'admin']);
        $role2 = Role::create(['name' => 'editor']);

        // Manually insert pivot records
        self::$pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES ({$user->getKey()}, {$role1->getKey()})");
        self::$pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES ({$user->getKey()}, {$role2->getKey()})");

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());

        $roles = $loadedUser->roles;

        $this->assertCount(2, $roles);
        $roleNames = array_map(fn($r) => $r->name, $roles);
        $this->assertContains('admin', $roleNames);
        $this->assertContains('editor', $roleNames);
    }

    public function testBelongsToManyAttach(): void
    {
        $user = $this->createUser();
        $role = Role::create(['name' => 'admin']);

        $user->roles()->attach($role->getKey());

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());
        $roles = $loadedUser->roles;

        $this->assertCount(1, $roles);
        $this->assertEquals('admin', $roles[0]->name);
    }

    public function testBelongsToManyAttachMultiple(): void
    {
        $user = $this->createUser();
        $role1 = Role::create(['name' => 'admin']);
        $role2 = Role::create(['name' => 'editor']);

        $user->roles()->attach([$role1->getKey(), $role2->getKey()]);

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());

        $this->assertCount(2, $loadedUser->roles);
    }

    public function testBelongsToManyDetach(): void
    {
        $user = $this->createUser();
        $role1 = Role::create(['name' => 'admin']);
        $role2 = Role::create(['name' => 'editor']);

        $user->roles()->attach([$role1->getKey(), $role2->getKey()]);
        $user->roles()->detach($role1->getKey());

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());
        $roles = $loadedUser->roles;

        $this->assertCount(1, $roles);
        $this->assertEquals('editor', $roles[0]->name);
    }

    public function testBelongsToManyDetachAll(): void
    {
        $user = $this->createUser();
        $role1 = Role::create(['name' => 'admin']);
        $role2 = Role::create(['name' => 'editor']);

        $user->roles()->attach([$role1->getKey(), $role2->getKey()]);
        $user->roles()->detach();

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());

        $this->assertCount(0, $loadedUser->roles);
    }

    public function testBelongsToManySync(): void
    {
        $user = $this->createUser();
        $role1 = Role::create(['name' => 'admin']);
        $role2 = Role::create(['name' => 'editor']);
        $role3 = Role::create(['name' => 'viewer']);

        $user->roles()->attach([$role1->getKey(), $role2->getKey()]);

        // Sync to different set
        $result = $user->roles()->sync([$role2->getKey(), $role3->getKey()]);

        $this->assertContains($role1->getKey(), $result['detached']);
        $this->assertContains($role3->getKey(), $result['attached']);

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());
        $roleNames = array_map(fn($r) => $r->name, $loadedUser->roles);

        $this->assertCount(2, $roleNames);
        $this->assertContains('editor', $roleNames);
        $this->assertContains('viewer', $roleNames);
        $this->assertNotContains('admin', $roleNames);
    }

    public function testEagerLoading(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $this->createPost($user, ['title' => 'Post 1']);
        $this->createPost($user, ['title' => 'Post 2']);

        Model::clearIdentityMap();
        Model::enableQueryLog();
        Model::flushQueryLog();

        $users = User::with('posts')->get();

        $queryLog = Model::getQueryLog();
        Model::disableQueryLog();

        // Should be 2 queries: one for users, one for posts
        $this->assertCount(2, $queryLog);

        // Posts should be loaded
        $this->assertCount(2, $users[0]->posts);
    }

    public function testNestedEagerLoading(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $post = $this->createPost($user, ['title' => 'Post 1']);

        Comment::create([
            'post_id' => $post->getKey(),
            'user_id' => $user->getKey(),
            'body' => 'Comment 1',
        ]);
        Comment::create([
            'post_id' => $post->getKey(),
            'user_id' => $user->getKey(),
            'body' => 'Comment 2',
        ]);

        Model::clearIdentityMap();
        Model::enableQueryLog();
        Model::flushQueryLog();

        $users = User::with('posts.comments')->get();

        $queryLog = Model::getQueryLog();
        Model::disableQueryLog();

        // Should be 3 queries: users, posts, comments
        $this->assertCount(3, $queryLog);

        // Relations should be loaded
        $this->assertCount(1, $users[0]->posts);
        $this->assertCount(2, $users[0]->posts[0]->comments);
    }

    public function testLazyLoadingRelation(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $this->createPost($user, ['title' => 'Post 1']);

        Model::clearIdentityMap();

        $loadedUser = User::find($user->getKey());

        // Accessing relation triggers query
        Model::enableQueryLog();
        Model::flushQueryLog();

        $posts = $loadedUser->posts;

        $queryLog = Model::getQueryLog();
        Model::disableQueryLog();

        $this->assertCount(1, $queryLog);
        $this->assertCount(1, $posts);
    }

    public function testRelationIsCached(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $this->createPost($user, ['title' => 'Post 1']);

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());

        // First access
        $posts1 = $loadedUser->posts;

        Model::enableQueryLog();
        Model::flushQueryLog();

        // Second access - should be cached
        $posts2 = $loadedUser->posts;

        $queryLog = Model::getQueryLog();
        Model::disableQueryLog();

        $this->assertCount(0, $queryLog);
        $this->assertSame($posts1, $posts2);
    }

    public function testLoadMethod(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $this->createPost($user, ['title' => 'Post 1']);

        Profile::create([
            'user_id' => $user->getKey(),
            'bio' => 'Bio',
        ]);

        Model::clearIdentityMap();
        $loadedUser = User::find($user->getKey());

        // Load relations after the fact
        $loadedUser->load('posts', 'profile');

        $this->assertCount(1, $loadedUser->posts);
        $this->assertNotNull($loadedUser->profile);
    }

    public function testSetRelation(): void
    {
        $user = $this->createUser();

        $posts = [
            Post::create(['user_id' => $user->getKey(), 'title' => 'Post 1']),
            Post::create(['user_id' => $user->getKey(), 'title' => 'Post 2']),
        ];

        $user->setRelation('posts', $posts);

        $this->assertSame($posts, $user->posts);
    }
}
