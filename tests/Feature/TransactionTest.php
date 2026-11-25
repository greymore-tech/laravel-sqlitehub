<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SQLiteHub\Laravel\Hrana\HranaException;
use SQLiteHub\Laravel\Tests\TestCase;

class TransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('create table items (id integer primary key, name text unique)');
        $this->server->requests = [];
    }

    private function names(): array
    {
        return DB::table('items')->orderBy('id')->pluck('name')->all();
    }

    public function test_commit_keeps_one_stream_and_closes_it(): void
    {
        DB::transaction(function () {
            DB::table('items')->insert(['name' => 'a']);
            DB::table('items')->insert(['name' => 'b']);
        });

        $this->assertSame(['a', 'b'], $this->names());

        // One stream: no baton on BEGIN, then each request carries the previous response's baton.
        $bodies = array_map(fn ($r) => $r->data(), array_slice($this->server->requests, 0, 4));
        $this->assertNull($bodies[0]['baton']);
        foreach ([1, 2, 3] as $i) {
            $this->assertNotNull($bodies[$i]['baton']);
        }
        $this->assertSame(['PRAGMA foreign_keys = ON', 'BEGIN DEFERRED TRANSACTION'], array_column(array_column(array_slice($bodies[0]['requests'], 0, 2), 'stmt'), 'sql'));
        $this->assertSame('get_autocommit', $bodies[1]['requests'][1]['type']);
        $this->assertSame(['execute', 'close'], array_column($bodies[3]['requests'], 'type'));
        $this->assertSame('COMMIT', $bodies[3]['requests'][0]['stmt']['sql']);
        $this->assertSame(0, $this->server->openStreams());
        $this->assertNull($this->pdo()->baton());
    }

    public function test_exception_rolls_back(): void
    {
        try {
            DB::transaction(function () {
                DB::table('items')->insert(['name' => 'a']);
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->names());
        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertSame(0, $this->server->openStreams());
    }

    public function test_nested_transactions_use_savepoints(): void
    {
        DB::transaction(function () {
            DB::table('items')->insert(['name' => 'outer']);

            try {
                DB::transaction(function () {
                    DB::table('items')->insert(['name' => 'inner']);
                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
            }

            DB::transaction(fn () => DB::table('items')->insert(['name' => 'inner2']));
            $this->assertSame(2, DB::transactionLevel() + 1);
        });

        $this->assertSame(['outer', 'inner2'], $this->names());
        $statements = $this->server->statements();
        $this->assertContains('SAVEPOINT trans2', $statements);
        $this->assertContains('ROLLBACK TO SAVEPOINT trans2', $statements);
        $this->assertSame(0, $this->server->openStreams());
    }

    public function test_manual_begin_commit_and_rollback(): void
    {
        DB::beginTransaction();
        DB::table('items')->insert(['name' => 'a']);
        DB::commit();

        DB::beginTransaction();
        DB::table('items')->insert(['name' => 'b']);
        DB::rollBack();

        $this->assertSame(['a'], $this->names());
    }

    public function test_query_error_inside_a_transaction_keeps_the_stream(): void
    {
        DB::transaction(function () {
            DB::table('items')->insert(['name' => 'a']);
            try {
                DB::table('items')->insert(['name' => 'a']);
            } catch (QueryException) {
            }
            $this->assertTrue($this->pdo()->inTransaction());
            DB::table('items')->insert(['name' => 'b']);
        });

        $this->assertSame(['a', 'b'], $this->names());
    }

    public function test_raw_begin_and_commit_are_tracked(): void
    {
        DB::unprepared('BEGIN');
        $this->assertTrue($this->pdo()->inTransaction());
        DB::insert('insert into items (name) values (?)', ['raw']);
        DB::unprepared('COMMIT');

        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertSame(0, $this->server->openStreams(), 'a stream ended by SQL is closed');
        $this->assertSame(['raw'], $this->names());
    }

    public function test_a_transaction_the_server_ended_is_not_rolled_back_again(): void
    {
        DB::beginTransaction();
        DB::table('items')->insert(['name' => 'a']);
        // Simulate SQLite ending the transaction itself (as on some errors).
        $this->server->intercept = function ($request) {
            $this->server->intercept = null;
            $data = $request->data();

            return Http::response(['baton' => 'b2', 'base_url' => null, 'results' => [
                ['type' => 'error', 'error' => ['message' => 'SQLite error: database or disk is full', 'code' => 'SQLITE_FULL']],
                ['type' => 'ok', 'response' => ['type' => 'get_autocommit', 'is_autocommit' => true]],
            ]]);
        };

        try {
            DB::table('items')->insert(['name' => 'b']);
            $this->fail('Expected SQLITE_FULL');
        } catch (QueryException $e) {
            $this->assertStringContainsString('SQLITE_FULL', $e->getMessage());
        }

        $this->assertFalse($this->pdo()->inTransaction());
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNotContains('ROLLBACK', $this->server->statements());
    }

    public function test_a_lost_stream_ends_the_transaction(): void
    {
        DB::beginTransaction();
        DB::table('items')->insert(['name' => 'a']);
        $this->server->intercept = fn () => Http::response('Received an invalid baton', 400);

        try {
            DB::table('items')->insert(['name' => 'b']);
            $this->fail('Expected the stream to be gone');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Received an invalid baton (HTTP 400)', $e->getMessage());
            $this->assertSame(400, $e->getPrevious()->status);
        }

        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertNull($this->pdo()->baton());
        $this->server->intercept = null;
        DB::rollBack();
        $this->assertSame([], $this->names());
    }

    public function test_commit_errors_are_raised(): void
    {
        DB::beginTransaction();
        $this->server->intercept = fn () => Http::response(['baton' => null, 'base_url' => null, 'results' => [
            ['type' => 'error', 'error' => ['message' => 'SQLite error: database is locked', 'code' => 'SQLITE_BUSY']],
            ['type' => 'ok', 'response' => ['type' => 'close']],
        ]]);

        try {
            DB::commit();
            $this->fail('Expected the commit to fail');
        } catch (HranaException $e) {
            $this->assertSame('SQLITE_BUSY: SQLite error: database is locked', $e->getMessage());
        }
        $this->assertFalse($this->pdo()->inTransaction());
    }

    public function test_pdo_transaction_misuse_throws_like_pdo(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue($pdo->beginTransaction());

        try {
            $pdo->beginTransaction();
            $this->fail('Expected a nested PDO transaction to fail');
        } catch (HranaException $e) {
            $this->assertSame('There is already an active transaction', $e->getMessage());
        }

        $this->assertTrue($pdo->rollBack());
        $this->expectExceptionMessage('There is no active transaction');
        $pdo->commit();
    }

    public function test_base_url_from_the_server_is_used_for_the_stream(): void
    {
        $this->server->intercept = function ($request) {
            $this->server->intercept = null;
            $response = ($this->server)($request)->wait();
            array_pop($this->server->requests); // recorded twice
            $body = json_decode((string) $response->getBody(), true);

            return Http::response(['base_url' => 'https://shard-7.internal.test'] + $body);
        };

        DB::transaction(fn () => DB::table('items')->insert(['name' => 'a']));

        $this->names();
        $urls = array_map(fn ($r) => $r->url(), $this->server->requests);
        $this->assertSame([
            'https://orders--acme.mumbai.db.sqlitehub.test/v3/pipeline', // BEGIN: the answer moves the stream
            'https://shard-7.internal.test/v3/pipeline',                 // insert
            'https://shard-7.internal.test/v3/pipeline',                 // COMMIT + close
            'https://orders--acme.mumbai.db.sqlitehub.test/v3/pipeline', // a new stream
        ], $urls);
    }

    public function test_server_closing_the_stream_ends_the_transaction(): void
    {
        $this->server->intercept = fn () => Http::response(['baton' => null, 'base_url' => null, 'results' => [
            ['type' => 'ok', 'response' => ['type' => 'execute', 'result' => ['cols' => [], 'rows' => [], 'affected_row_count' => 0, 'last_insert_rowid' => null]]],
            ['type' => 'ok', 'response' => ['type' => 'execute', 'result' => ['cols' => [], 'rows' => [], 'affected_row_count' => 0, 'last_insert_rowid' => null]]],
            ['type' => 'ok', 'response' => ['type' => 'get_autocommit', 'is_autocommit' => false]],
        ]]);

        $this->pdo()->exec('BEGIN');

        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertCount(1, $this->server->requests, 'nothing to close');
    }

    public function test_disconnect_closes_an_open_stream(): void
    {
        DB::beginTransaction();
        DB::table('items')->insert(['name' => 'a']);
        $this->assertSame(1, $this->server->openStreams());

        DB::disconnect();
        gc_collect_cycles();

        $this->assertSame(0, $this->server->openStreams());
        $this->assertSame(['close'], array_column(end($this->server->requests)->data()['requests'], 'type'));
    }

    public function test_closing_a_stream_ignores_errors(): void
    {
        $this->pdo()->exec('BEGIN');
        $this->server->intercept = fn () => Http::response('gone', 500);

        $this->pdo()->closeStream();

        $this->assertNull($this->pdo()->baton());
        $this->assertFalse($this->pdo()->inTransaction());
    }
}
