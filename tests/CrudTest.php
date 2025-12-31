<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class CrudTest extends NanoORMTestCase
{
    public function testCreateModel(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $this->assertNotNull($user->getKey());
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertTrue($user->exists());
    }

    public function testCreateWithConstructor(): void
    {
        $user = new User([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $this->assertNull($user->getKey());
        $this->assertFalse($user->exists());

        $user->save();

        $this->assertNotNull($user->getKey());
        $this->assertTrue($user->exists());
    }

    public function testFindById(): void
    {
        $created = $this->createUser(['name' => 'Find Me']);

        $found = User::find($created->getKey());

        $this->assertNotNull($found);
        $this->assertEquals('Find Me', $found->name);
        $this->assertEquals($created->getKey(), $found->getKey());
    }

    public function testFindOrFail(): void
    {
        $user = $this->createUser();

        $found = User::findOrFail($user->getKey());
        $this->assertEquals($user->getKey(), $found->getKey());
    }

    public function testFindOrFailThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        User::findOrFail(99999);
    }

    public function testFindMany(): void
    {
        $user1 = $this->createUser(['name' => 'User 1']);
        $user2 = $this->createUser(['name' => 'User 2']);
        $user3 = $this->createUser(['name' => 'User 3']);

        $users = User::findMany([$user1->getKey(), $user3->getKey()]);

        $this->assertCount(2, $users);
        $names = array_map(fn($u) => $u->name, $users);
        $this->assertContains('User 1', $names);
        $this->assertContains('User 3', $names);
    }

    public function testAll(): void
    {
        $this->createUser(['name' => 'User 1']);
        $this->createUser(['name' => 'User 2']);

        $users = User::all();

        $this->assertCount(2, $users);
    }

    public function testUpdate(): void
    {
        $user = $this->createUser(['name' => 'Original Name']);

        $user->name = 'Updated Name';
        $user->save();

        // Reload from database
        Model::clearIdentityMap();
        $reloaded = User::find($user->getKey());

        $this->assertEquals('Updated Name', $reloaded->name);
    }

    public function testDelete(): void
    {
        $user = $this->createUser();
        $id = $user->getKey();

        $user->delete();

        // Soft delete - should still exist in DB but be trashed
        $this->assertTrue($user->trashed());

        // Regular query shouldn't find it
        $this->assertNull(User::find($id));

        // With trashed should find it
        $found = User::query()->withTrashed()->find($id);
        $this->assertNotNull($found);
    }

    public function testForceDelete(): void
    {
        $user = $this->createUser();
        $id = $user->getKey();

        $user->forceDelete();

        // Should be completely gone
        $found = User::query()->withTrashed()->find($id);
        $this->assertNull($found);
    }

    public function testRestore(): void
    {
        $user = $this->createUser();
        $id = $user->getKey();

        $user->delete();
        $this->assertTrue($user->trashed());

        $user->restore();
        $this->assertFalse($user->trashed());

        // Should be findable again
        Model::clearIdentityMap();
        $found = User::find($id);
        $this->assertNotNull($found);
    }

    public function testRefresh(): void
    {
        $user = $this->createUser(['name' => 'Original']);

        // Update directly in database
        self::$pdo->exec("UPDATE users SET name = 'Changed' WHERE id = " . $user->getKey());

        // Before refresh
        $this->assertEquals('Original', $user->name);

        // After refresh
        $user->refresh();
        $this->assertEquals('Changed', $user->name);
    }

    public function testTimestampsAreSet(): void
    {
        $user = $this->createUser();

        $this->assertNotNull($user->created_at);
        $this->assertNotNull($user->updated_at);
    }

    public function testUpdatedAtChangesOnUpdate(): void
    {
        $user = $this->createUser();
        $originalUpdatedAt = $user->updated_at;

        sleep(1); // Ensure timestamp difference

        $user->name = 'Changed';
        $user->save();

        $this->assertNotEquals($originalUpdatedAt, $user->updated_at);
    }

    public function testFillMethod(): void
    {
        $user = new User();
        $user->fill([
            'name' => 'Filled User',
            'email' => 'filled@example.com',
        ]);

        $this->assertEquals('Filled User', $user->name);
        $this->assertEquals('filled@example.com', $user->email);
    }

    public function testPropertyAccess(): void
    {
        $user = new User();

        $user->name = 'Test';
        $this->assertEquals('Test', $user->name);
        $this->assertTrue(isset($user->name));

        unset($user->name);
        $this->assertNull($user->name);
    }
}
