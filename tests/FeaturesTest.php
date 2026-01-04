<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class FeaturesTest extends NanoORMTestCase
{
    // ========== DIRTY CHECKING ==========

    public function testIsDirty(): void
    {
        $user = $this->createUser(['name' => 'Original']);

        $this->assertFalse($user->isDirty());

        $user->name = 'Changed';

        $this->assertTrue($user->isDirty());
        $this->assertTrue($user->isDirty('name'));
        $this->assertFalse($user->isDirty('email'));
    }

    public function testIsClean(): void
    {
        $user = $this->createUser(['name' => 'Original']);

        $this->assertTrue($user->isClean());

        $user->name = 'Changed';

        $this->assertFalse($user->isClean());
    }

    public function testGetDirty(): void
    {
        $user = $this->createUser(['name' => 'Original']);

        $user->name = 'Changed';
        $user->email = 'new@example.com';

        $dirty = $user->getDirty();

        $this->assertArrayHasKey('name', $dirty);
        $this->assertArrayHasKey('email', $dirty);
        $this->assertEquals('Changed', $dirty['name']);
    }

    public function testGetOriginal(): void
    {
        $user = $this->createUser(['name' => 'Original']);

        $user->name = 'Changed';

        $this->assertEquals('Original', $user->getOriginal('name'));
        $this->assertEquals('Changed', $user->name);
    }

    public function testDirtyResetAfterSave(): void
    {
        $user = $this->createUser(['name' => 'Original']);

        $user->name = 'Changed';
        $this->assertTrue($user->isDirty());

        $user->save();
        $this->assertFalse($user->isDirty());
    }

    // ========== ATTRIBUTE CASTING ==========

    public function testBooleanCast(): void
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'is_admin' => 1,
        ]);

        Model::clearIdentityMap();
        $loaded = User::find($user->getKey());

        $this->assertTrue($loaded->is_admin);
        $this->assertIsBool($loaded->is_admin);
    }

    public function testJsonCast(): void
    {
        $settings = ['theme' => 'dark', 'notifications' => true];

        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
            'settings' => $settings,
        ]);

        Model::clearIdentityMap();
        $loaded = User::find($user->getKey());

        $this->assertEquals($settings, $loaded->settings);
        $this->assertIsArray($loaded->settings);
    }

    public function testJsonCastNull(): void
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
            'settings' => null,
        ]);

        Model::clearIdentityMap();
        $loaded = User::find($user->getKey());

        $this->assertNull($loaded->settings);
    }

    // ========== ATOMIC OPERATIONS ==========

    public function testIncrement(): void
    {
        $user = $this->createUser();
        $post = Post::create([
            'user_id' => $user->getKey(),
            'title' => 'Test',
            'views' => 10,
        ]);

        Model::clearIdentityMap();
        $loadedPost = Post::find($post->getKey());

        $loadedPost->increment('views');

        $this->assertEquals(11, $loadedPost->views);

        // Verify in database
        $stmt = self::$pdo->query("SELECT views FROM posts WHERE id = " . $post->getKey());
        $this->assertEquals(11, $stmt->fetchColumn());
    }

    public function testIncrementByAmount(): void
    {
        $user = $this->createUser();
        $post = Post::create([
            'user_id' => $user->getKey(),
            'title' => 'Test',
            'views' => 10,
        ]);

        Model::clearIdentityMap();
        $loadedPost = Post::find($post->getKey());

        $loadedPost->increment('views', 5);

        $this->assertEquals(15, $loadedPost->views);
    }

    public function testDecrement(): void
    {
        $user = $this->createUser();
        $post = Post::create([
            'user_id' => $user->getKey(),
            'title' => 'Test',
            'views' => 10,
        ]);

        Model::clearIdentityMap();
        $loadedPost = Post::find($post->getKey());

        $loadedPost->decrement('views');

        $this->assertEquals(9, $loadedPost->views);
    }

    // ========== TRANSACTIONS ==========

    public function testTransaction(): void
    {
        $result = \NanoORM\Model::transaction(function () {
            $user = User::create(['name' => 'Transaction User', 'email' => 'tx@example.com']);
            return $user->getKey();
        });

        $this->assertNotNull($result);

        $user = User::find($result);
        $this->assertEquals('Transaction User', $user->name);
    }

    public function testTransactionRollbackOnException(): void
    {
        $countBefore = User::count();

        try {
            \NanoORM\Model::transaction(function () {
                User::create(['name' => 'Will Rollback', 'email' => 'rollback@example.com']);
                throw new \Exception('Force rollback');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $countAfter = User::count();

        $this->assertEquals($countBefore, $countAfter);
    }

    public function testManualTransaction(): void
    {
        \NanoORM\Model::beginTransaction();

        try {
            User::create(['name' => 'Manual TX', 'email' => 'manual@example.com']);
            \NanoORM\Model::commit();
        } catch (\Exception $e) {
            \NanoORM\Model::rollback();
            throw $e;
        }

        $user = User::where('email', 'manual@example.com')->first();
        $this->assertNotNull($user);
    }

    // ========== FIRST OR CREATE / UPDATE OR CREATE ==========

    public function testFirstOrCreateFindsExisting(): void
    {
        $existing = $this->createUser(['name' => 'Existing', 'email' => 'existing@example.com']);

        $user = User::firstOrCreate(
            ['email' => 'existing@example.com'],
            ['name' => 'New Name']
        );

        $this->assertEquals($existing->getKey(), $user->getKey());
        $this->assertEquals('Existing', $user->name); // Original name preserved
    }

    public function testFirstOrCreateCreatesNew(): void
    {
        $countBefore = User::count();

        $user = User::firstOrCreate(
            ['email' => 'new@example.com'],
            ['name' => 'New User']
        );

        $countAfter = User::count();

        $this->assertEquals($countBefore + 1, $countAfter);
        $this->assertEquals('New User', $user->name);
        $this->assertEquals('new@example.com', $user->email);
    }

    public function testUpdateOrCreateUpdatesExisting(): void
    {
        $existing = $this->createUser(['name' => 'Old Name', 'email' => 'update@example.com']);

        $user = User::updateOrCreate(
            ['email' => 'update@example.com'],
            ['name' => 'Updated Name']
        );

        $this->assertEquals($existing->getKey(), $user->getKey());
        $this->assertEquals('Updated Name', $user->name);
    }

    public function testUpdateOrCreateCreatesNew(): void
    {
        $countBefore = User::count();

        $user = User::updateOrCreate(
            ['email' => 'create@example.com'],
            ['name' => 'Created User']
        );

        $countAfter = User::count();

        $this->assertEquals($countBefore + 1, $countAfter);
        $this->assertEquals('Created User', $user->name);
    }

    // ========== IDENTITY MAP ==========

    public function testIdentityMapReturnsSameInstance(): void
    {
        $user = $this->createUser();
        $id = $user->getKey();

        $loaded1 = User::find($id);
        $loaded2 = User::find($id);

        $this->assertSame($loaded1, $loaded2);
    }

    public function testClearIdentityMap(): void
    {
        $user = $this->createUser();
        $id = $user->getKey();

        $loaded1 = User::find($id);
        Model::clearIdentityMap();
        $loaded2 = User::find($id);

        $this->assertNotSame($loaded1, $loaded2);
        $this->assertEquals($loaded1->getKey(), $loaded2->getKey());
    }

    // ========== QUERY LOGGING ==========

    public function testQueryLogging(): void
    {
        Model::enableQueryLog();
        Model::flushQueryLog();

        User::where('name', 'Test')->get();
        User::find(1);

        $log = Model::getQueryLog();

        $this->assertCount(2, $log);
        $this->assertArrayHasKey('sql', $log[0]);
        $this->assertArrayHasKey('bindings', $log[0]);
        $this->assertArrayHasKey('time', $log[0]);

        Model::disableQueryLog();
    }

    // ========== TO ARRAY / TO JSON ==========

    public function testToArray(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret',
            'is_admin' => true,
            'settings' => ['theme' => 'dark'],
        ]);

        $array = $user->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Test User', $array['name']);
        $this->assertEquals('test@example.com', $array['email']);

        // Password should be hidden
        $this->assertArrayNotHasKey('password', $array);

        // Casts should be applied
        $this->assertTrue($array['is_admin']);
        $this->assertEquals(['theme' => 'dark'], $array['settings']);
    }

    public function testToJson(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $json = $user->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('Test User', $decoded['name']);
    }

    public function testToArrayIncludesLoadedRelations(): void
    {
        $user = $this->createUser(['name' => 'Author']);
        $this->createPost($user, ['title' => 'Post 1']);

        $user->load('posts');

        $array = $user->toArray();

        $this->assertArrayHasKey('posts', $array);
        $this->assertCount(1, $array['posts']);
        $this->assertEquals('Post 1', $array['posts'][0]['title']);
    }

    // ========== TABLE NAME INFERENCE ==========

    public function testTableNameFromClassName(): void
    {
        // User -> users
        $this->assertEquals('users', User::getTable());

        // Post -> posts
        $this->assertEquals('posts', Post::getTable());
    }

    // ========== AGGREGATES ==========

    public function testSum(): void
    {
        $user = $this->createUser();

        Post::create(['user_id' => $user->getKey(), 'title' => 'P1', 'amount' => 100.50]);
        Post::create(['user_id' => $user->getKey(), 'title' => 'P2', 'amount' => 200.25]);

        $sum = Post::query()->sum('amount');

        $this->assertEquals(300.75, $sum);
    }

    public function testAvg(): void
    {
        $user = $this->createUser();

        Post::create(['user_id' => $user->getKey(), 'title' => 'P1', 'rating' => 4.0]);
        Post::create(['user_id' => $user->getKey(), 'title' => 'P2', 'rating' => 5.0]);

        $avg = Post::query()->avg('rating');

        $this->assertEquals(4.5, $avg);
    }

    public function testMinMax(): void
    {
        $user = $this->createUser();

        Post::create(['user_id' => $user->getKey(), 'title' => 'A']);
        Post::create(['user_id' => $user->getKey(), 'title' => 'B']);
        Post::create(['user_id' => $user->getKey(), 'title' => 'C']);

        $min = Post::query()->min('title');
        $max = Post::query()->max('title');

        $this->assertEquals('A', $min);
        $this->assertEquals('C', $max);
    }
}
