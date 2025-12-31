<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class QueryBuilderTest extends NanoORMTestCase
{
    public function testWhereEquals(): void
    {
        $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->createUser(['name' => 'Bob', 'email' => 'bob@example.com']);

        $users = User::where('name', 'Alice')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Alice', $users[0]->name);
    }

    public function testWhereTwoArguments(): void
    {
        $this->createUser(['name' => 'Alice']);

        $users = User::where('name', 'Alice')->get();
        $this->assertCount(1, $users);
    }

    public function testWhereWithOperator(): void
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);
        $this->createUser(['name' => 'Charlie']);

        $users = User::where('name', '>', 'Bob')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Charlie', $users[0]->name);
    }

    public function testWhereArray(): void
    {
        $this->createUser(['name' => 'Alice', 'is_admin' => true]);
        $this->createUser(['name' => 'Bob', 'is_admin' => false]);

        $users = User::where(['name' => 'Alice', 'is_admin' => 1])->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Alice', $users[0]->name);
    }

    public function testOrWhere(): void
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);
        $this->createUser(['name' => 'Charlie']);

        $users = User::where('name', 'Alice')
            ->orWhere('name', 'Bob')
            ->get();

        $this->assertCount(2, $users);
    }

    public function testNestedWhere(): void
    {
        $this->createUser(['name' => 'Alice', 'is_admin' => true]);
        $this->createUser(['name' => 'Bob', 'is_admin' => true]);
        $this->createUser(['name' => 'Charlie', 'is_admin' => false]);

        $users = User::where('is_admin', 1)
            ->where(function ($query) {
                $query->where('name', 'Alice')
                      ->orWhere('name', 'Bob');
            })
            ->get();

        $this->assertCount(2, $users);
    }

    public function testWhereIn(): void
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);
        $this->createUser(['name' => 'Charlie']);

        $users = User::whereIn('name', ['Alice', 'Charlie'])->get();

        $this->assertCount(2, $users);
    }

    public function testWhereNotIn(): void
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);
        $this->createUser(['name' => 'Charlie']);

        $users = User::whereNotIn('name', ['Alice', 'Charlie'])->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Bob', $users[0]->name);
    }

    public function testWhereNull(): void
    {
        $this->createUser(['name' => 'Alice', 'password' => null]);
        $this->createUser(['name' => 'Bob', 'password' => 'secret']);

        $users = User::whereNull('password')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Alice', $users[0]->name);
    }

    public function testWhereNotNull(): void
    {
        $this->createUser(['name' => 'Alice', 'password' => null]);
        $this->createUser(['name' => 'Bob', 'password' => 'secret']);

        $users = User::whereNotNull('password')->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Bob', $users[0]->name);
    }

    public function testWhereBetween(): void
    {
        $user1 = $this->createUser(['name' => 'User 1']);
        $user2 = $this->createUser(['name' => 'User 2']);
        $user3 = $this->createUser(['name' => 'User 3']);

        $users = User::whereBetween('id', $user1->getKey(), $user2->getKey())->get();

        $this->assertCount(2, $users);
    }

    public function testOrderBy(): void
    {
        $this->createUser(['name' => 'Charlie']);
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);

        $users = User::orderBy('name')->get();

        $this->assertEquals('Alice', $users[0]->name);
        $this->assertEquals('Bob', $users[1]->name);
        $this->assertEquals('Charlie', $users[2]->name);
    }

    public function testOrderByDesc(): void
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);
        $this->createUser(['name' => 'Charlie']);

        $users = User::orderBy('name', 'DESC')->get();

        $this->assertEquals('Charlie', $users[0]->name);
        $this->assertEquals('Bob', $users[1]->name);
        $this->assertEquals('Alice', $users[2]->name);
    }

    public function testLatest(): void
    {
        $user1 = $this->createUser(['name' => 'First']);
        sleep(1);
        $user2 = $this->createUser(['name' => 'Second']);

        $users = User::latest('created_at')->get();

        $this->assertEquals('Second', $users[0]->name);
    }

    public function testLimit(): void
    {
        $this->createUser(['name' => 'User 1']);
        $this->createUser(['name' => 'User 2']);
        $this->createUser(['name' => 'User 3']);

        $users = User::orderBy('id')->limit(2)->get();

        $this->assertCount(2, $users);
        $this->assertEquals('User 1', $users[0]->name);
        $this->assertEquals('User 2', $users[1]->name);
    }

    public function testOffset(): void
    {
        $this->createUser(['name' => 'User 1']);
        $this->createUser(['name' => 'User 2']);
        $this->createUser(['name' => 'User 3']);

        $users = User::orderBy('id')->offset(1)->limit(2)->get();

        $this->assertCount(2, $users);
        $this->assertEquals('User 2', $users[0]->name);
        $this->assertEquals('User 3', $users[1]->name);
    }

    public function testFirst(): void
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'Bob']);

        $user = User::orderBy('name')->first();

        $this->assertEquals('Alice', $user->name);
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        $user = User::where('name', 'NonExistent')->first();

        $this->assertNull($user);
    }

    public function testFirstOrFail(): void
    {
        $this->createUser(['name' => 'Alice']);

        $user = User::where('name', 'Alice')->firstOrFail();

        $this->assertEquals('Alice', $user->name);
    }

    public function testFirstOrFailThrows(): void
    {
        $this->expectException(RuntimeException::class);
        User::where('name', 'NonExistent')->firstOrFail();
    }

    public function testExists(): void
    {
        $this->createUser(['name' => 'Alice']);

        $this->assertTrue(User::where('name', 'Alice')->exists());
        $this->assertFalse(User::where('name', 'Bob')->exists());
    }

    public function testDoesntExist(): void
    {
        $this->createUser(['name' => 'Alice']);

        $this->assertFalse(User::where('name', 'Alice')->doesntExist());
        $this->assertTrue(User::where('name', 'Bob')->doesntExist());
    }

    public function testCount(): void
    {
        $this->createUser(['name' => 'User 1']);
        $this->createUser(['name' => 'User 2']);
        $this->createUser(['name' => 'User 3']);

        $this->assertEquals(3, User::count());
        $this->assertEquals(1, User::where('name', 'User 1')->count());
    }

    public function testPluck(): void
    {
        $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->createUser(['name' => 'Bob', 'email' => 'bob@example.com']);

        $names = User::orderBy('name')->pluck('name');

        $this->assertEquals(['Alice', 'Bob'], $names);
    }

    public function testPluckWithKey(): void
    {
        $user1 = $this->createUser(['name' => 'Alice']);
        $user2 = $this->createUser(['name' => 'Bob']);

        $names = User::pluck('name', 'id');

        $this->assertEquals('Alice', $names[$user1->getKey()]);
        $this->assertEquals('Bob', $names[$user2->getKey()]);
    }

    public function testSelect(): void
    {
        $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com']);

        // Just verify select doesn't break the query
        $users = User::query()->select('id', 'name')->get();

        $this->assertCount(1, $users);
    }

    public function testBulkUpdate(): void
    {
        $this->createUser(['name' => 'User 1', 'is_admin' => false]);
        $this->createUser(['name' => 'User 2', 'is_admin' => false]);
        $this->createUser(['name' => 'User 3', 'is_admin' => false]);

        $affected = User::where('name', 'LIKE', 'User%')->update(['is_admin' => true]);

        $this->assertEquals(3, $affected);

        Model::clearIdentityMap();
        $this->assertEquals(3, User::where('is_admin', 1)->count());
    }

    public function testBulkDelete(): void
    {
        $this->createUser(['name' => 'Keep']);
        $this->createUser(['name' => 'Delete 1']);
        $this->createUser(['name' => 'Delete 2']);

        // Users have soft deletes, so this soft-deletes them
        $affected = User::where('name', 'LIKE', 'Delete%')->delete();

        $this->assertEquals(2, $affected);
        $this->assertEquals(1, User::count()); // Only "Keep" visible
        $this->assertEquals(3, User::query()->withTrashed()->count()); // All still exist
    }

    public function testPaginate(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createUser(['name' => "User $i"]);
        }

        $page1 = User::orderBy('id')->paginate(10, 1);

        $this->assertCount(10, $page1['data']);
        $this->assertEquals(25, $page1['total']);
        $this->assertEquals(10, $page1['per_page']);
        $this->assertEquals(1, $page1['current_page']);
        $this->assertEquals(3, $page1['last_page']);
        $this->assertEquals(1, $page1['from']);
        $this->assertEquals(10, $page1['to']);

        $page3 = User::orderBy('id')->paginate(10, 3);

        $this->assertCount(5, $page3['data']);
        $this->assertEquals(3, $page3['current_page']);
        $this->assertEquals(21, $page3['from']);
        $this->assertEquals(25, $page3['to']);
    }

    public function testChunk(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createUser(['name' => "User $i"]);
        }

        $chunks = [];
        User::orderBy('id')->chunk(10, function ($users, $page) use (&$chunks) {
            $chunks[$page] = count($users);
        });

        $this->assertEquals([1 => 10, 2 => 10, 3 => 5], $chunks);
    }

    public function testChunkCanBeStopped(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createUser(['name' => "User $i"]);
        }

        $processed = 0;
        User::orderBy('id')->chunk(10, function ($users) use (&$processed) {
            $processed += count($users);
            return false; // Stop after first chunk
        });

        $this->assertEquals(10, $processed);
    }

    public function testToRawSql(): void
    {
        $sql = User::where('name', 'Alice')
            ->where('is_admin', true)
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->toRawSql();

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('Alice', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testSoftDeletesExcludedByDefault(): void
    {
        $user = $this->createUser(['name' => 'Active']);
        $deleted = $this->createUser(['name' => 'Deleted']);
        $deleted->delete();

        $users = User::all();

        $this->assertCount(1, $users);
        $this->assertEquals('Active', $users[0]->name);
    }

    public function testWithTrashed(): void
    {
        $user = $this->createUser(['name' => 'Active']);
        $deleted = $this->createUser(['name' => 'Deleted']);
        $deleted->delete();

        $users = User::query()->withTrashed()->get();

        $this->assertCount(2, $users);
    }

    public function testOnlyTrashed(): void
    {
        $user = $this->createUser(['name' => 'Active']);
        $deleted = $this->createUser(['name' => 'Deleted']);
        $deleted->delete();

        $users = User::query()->onlyTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals('Deleted', $users[0]->name);
    }
}
