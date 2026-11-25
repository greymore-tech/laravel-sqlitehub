<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SQLiteHub\Laravel\Tests\TestCase;

class QueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('customer');
            $table->decimal('total', 10, 2)->default(0);
            $table->boolean('paid')->default(false);
            $table->timestamps();
        });
    }

    public function test_insert_select_update_delete(): void
    {
        $this->assertTrue(DB::table('orders')->insert([
            ['customer' => 'asha', 'total' => 499.5, 'paid' => true],
            ['customer' => 'ravi', 'total' => 120, 'paid' => false],
        ]));

        $rows = DB::table('orders')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('asha', $rows[0]->customer);
        $this->assertSame(1, $rows[0]->id);
        $this->assertSame(499.5, $rows[0]->total);
        $this->assertSame(1, $rows[0]->paid);

        $this->assertSame(1, DB::table('orders')->where('customer', 'ravi')->update(['paid' => true]));
        $this->assertSame(2, DB::table('orders')->where('paid', true)->count());
        $this->assertSame(['asha', 'ravi'], DB::table('orders')->orderBy('customer')->pluck('customer')->all());
        $this->assertEquals(619.5, DB::table('orders')->sum('total'));
        $this->assertTrue(DB::table('orders')->where('customer', 'asha')->exists());

        $this->assertSame(1, DB::table('orders')->where('customer', 'asha')->delete());
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_insert_get_id(): void
    {
        $first = DB::table('orders')->insertGetId(['customer' => 'a']);
        $second = DB::table('orders')->insertGetId(['customer' => 'b']);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    public function test_cursor_and_lazy(): void
    {
        DB::table('orders')->insert(array_map(fn ($i) => ['customer' => "c{$i}"], range(1, 5)));

        $names = [];
        foreach (DB::table('orders')->orderBy('id')->cursor() as $row) {
            $names[] = $row->customer;
        }
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5'], $names);
        $this->assertSame(5, DB::table('orders')->lazyById(2)->count());
    }

    public function test_upsert_and_raw_statements(): void
    {
        DB::table('orders')->insert(['customer' => 'a', 'total' => 1]);
        DB::table('orders')->upsert([['id' => 1, 'customer' => 'a', 'total' => 2], ['id' => 2, 'customer' => 'b', 'total' => 3]], ['id'], ['total']);

        $this->assertEquals([2, 3], DB::table('orders')->orderBy('id')->pluck('total')->all());
        $this->assertSame(2, DB::selectOne('select count(*) as n from orders')->n);
        $this->assertSame(2, DB::scalar('select count(*) from orders where total > ?', [1]));
        $this->assertTrue(DB::statement('update orders set paid = ?', [true]));
        $this->assertSame(2, DB::affectingStatement('update orders set paid = 0'));
        $this->assertTrue(DB::unprepared('update orders set total = 0; update orders set customer = upper(customer)'));
        $this->assertSame(['A', 'B'], DB::table('orders')->orderBy('id')->pluck('customer')->all());
    }
}
