<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SQLiteHub\Laravel\Tests\Support\Customer;
use SQLiteHub\Laravel\Tests\Support\Post;
use SQLiteHub\Laravel\Tests\TestCase;

class EloquentTest extends TestCase
{
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Support/migrations');
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + ['uuid' => (string) Str::uuid(), 'email' => Str::random(8).'@example.com']);
    }

    public function test_create_find_update_delete(): void
    {
        $avatar = random_bytes(32)."\xff";
        $customer = $this->customer([
            'email' => 'asha@example.com',
            'name' => 'Asha',
            'rating' => 4.75,
            'balance' => '1499.50',
            'points' => PHP_INT_MAX,
            'settings' => ['theme' => 'dark', 'langs' => ['en', 'hi']],
            'avatar' => $avatar,
            'born_on' => '1990-05-17',
            'last_seen_at' => Carbon::parse('2026-11-25 10:00:00'),
        ]);

        $this->assertSame(1, $customer->id);
        $this->assertTrue($customer->wasRecentlyCreated);

        $found = Customer::find(1);
        $this->assertSame('Asha', $found->name);
        $this->assertSame(4.75, $found->rating);
        $this->assertSame('1499.50', $found->balance);
        $this->assertSame(PHP_INT_MAX, $found->points);
        $this->assertSame(['theme' => 'dark', 'langs' => ['en', 'hi']], $found->settings);
        $this->assertSame($avatar, $found->avatar);
        $this->assertTrue($found->active);
        $this->assertSame('1990-05-17', $found->born_on->toDateString());
        $this->assertSame('2026-11-25 10:00:00', $found->last_seen_at->toDateTimeString());
        $this->assertSame('free', $found->tier);
        $this->assertNull($found->bio);

        $found->update(['name' => 'Asha K', 'active' => false]);
        $this->assertSame('Asha K', $found->fresh()->name);
        $this->assertFalse($found->fresh()->active);
        $this->assertSame(1, Customer::where('active', false)->count());

        $found->increment('visits', 3);
        $this->assertSame(3, $found->fresh()->visits);

        $found->delete();
        $this->assertNull(Customer::find(1));
        $this->assertSame(1, Customer::withTrashed()->count());
        $found->forceDelete();
        $this->assertSame(0, Customer::withTrashed()->count());
    }

    public function test_relations_and_cascades(): void
    {
        $customer = $this->customer();
        $customer->posts()->createMany([['title' => 'One'], ['title' => 'Two']]);

        $this->assertSame(['One', 'Two'], Customer::with('posts')->first()->posts->pluck('title')->all());
        $this->assertSame($customer->id, Post::first()->customer->id);
        $this->assertSame(1, Customer::has('posts', '>=', 2)->count());

        // foreign_key_constraints => true: cascades run in every stream.
        $customer->forceDelete();
        $this->assertSame(0, Post::count());
    }

    public function test_unique_violation_is_laravels_exception(): void
    {
        $this->customer(['email' => 'dup@example.com']);

        try {
            $this->customer(['email' => 'dup@example.com']);
            $this->fail('Expected a unique constraint violation');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(['email'], $e->columns);
            $this->assertSame('SQLITE_CONSTRAINT', $e->getCode());
            $this->assertStringContainsString('SQLITE_CONSTRAINT: SQLite error: UNIQUE constraint failed: customers.email', $e->getMessage());
        }

        $this->assertSame('dup@example.com', Customer::firstOrCreate(['email' => 'dup@example.com'], ['uuid' => 'x'])->email);
        $this->assertSame(1, Customer::count());
    }

    public function test_pagination_chunking_and_aggregates(): void
    {
        foreach (range(1, 7) as $i) {
            $this->customer(['visits' => $i]);
        }

        $page = Customer::orderBy('id')->paginate(3, page: 2);
        $this->assertSame(7, $page->total());
        $this->assertSame([4, 5, 6], $page->pluck('visits')->all());

        $seen = 0;
        Customer::chunkById(2, function ($chunk) use (&$seen) {
            $seen += $chunk->count();
        });
        $this->assertSame(7, $seen);
        $this->assertSame(28, (int) Customer::sum('visits'));
        $this->assertSame(7, Customer::max('visits'));
        $this->assertEqualsWithDelta(4.0, Customer::avg('visits'), 0.001);
    }
}
