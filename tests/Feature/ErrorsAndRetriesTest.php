<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SQLiteHub\Laravel\Hrana\HranaException;
use SQLiteHub\Laravel\Tests\TestCase;

class ErrorsAndRetriesTest extends TestCase
{
    private function failWith($response): void
    {
        $this->server->intercept = fn () => $response;
    }

    private function queryError(callable $query): QueryException
    {
        try {
            $query();
        } catch (QueryException $e) {
            return $e;
        }
        $this->fail('Expected a QueryException');
    }

    public function test_sql_errors_carry_sqlds_message_and_code(): void
    {
        $e = $this->queryError(fn () => DB::select('select * from nope'));

        $this->assertStringStartsWith('SQLITE_ERROR: SQLite error: no such table: nope (Connection: sqlitehub, Host: orders--acme.mumbai.db.sqlitehub.test', $e->getMessage());
        $this->assertSame('select * from nope', $e->getSql());
        $this->assertSame('SQLITE_ERROR', $e->getCode());
        $this->assertInstanceOf(HranaException::class, $e->getPrevious());
        $this->assertSame(['HY000', 'SQLITE_ERROR', 'SQLITE_ERROR: SQLite error: no such table: nope'], $e->getPrevious()->errorInfo);
    }

    public function test_errors_without_a_code(): void
    {
        $this->failWith(Http::response(['baton' => null, 'base_url' => null, 'results' => [
            ['type' => 'ok', 'response' => ['type' => 'execute', 'result' => []]],
            ['type' => 'error', 'error' => ['message' => 'Something odd']],
            ['type' => 'ok', 'response' => ['type' => 'close']],
        ]]));

        $e = $this->queryError(fn () => DB::select('select 1'));
        $this->assertStringStartsWith('Something odd (Connection', $e->getMessage());
        $this->assertSame('HY000', $e->getCode());
    }

    public function test_edge_errors_surface_their_code(): void
    {
        $this->failWith(Http::response(['message' => 'The connection token has expired.', 'code' => 'TOKEN_EXPIRED'], 401));

        $e = $this->queryError(fn () => DB::select('select 1'));
        $this->assertStringStartsWith('TOKEN_EXPIRED: The connection token has expired. (HTTP 401)', $e->getMessage());
        $this->assertSame('TOKEN_EXPIRED', $e->getCode());
        $this->assertSame(401, $e->getPrevious()->status);
    }

    public function test_sqld_http_errors_and_plain_text_bodies(): void
    {
        $this->failWith(Http::response(['error' => 'Authorization forbidden: read-only'], 403));
        $this->assertStringStartsWith('Authorization forbidden: read-only (HTTP 403)', $this->queryError(fn () => DB::select('select 1'))->getMessage());

        $this->failWith(Http::response('<html>Bad gateway</html>', 502));
        $this->assertStringStartsWith('<html>Bad gateway</html> (HTTP 502)', $this->queryError(fn () => DB::select('select 1'))->getMessage());

        $this->failWith(Http::response('', 503));
        $this->assertStringStartsWith('no response body (HTTP 503)', $this->queryError(fn () => DB::select('select 1'))->getMessage());
    }

    public function test_invalid_pipeline_responses(): void
    {
        $this->failWith(Http::response('not json', 200));
        $this->assertStringStartsWith('SQLiteHub sent an invalid pipeline response: not json', $this->queryError(fn () => DB::select('select 1'))->getMessage());

        $this->failWith(Http::response(['results' => []], 200));
        $this->assertSame('INVALID_RESPONSE', $this->queryError(fn () => DB::select('select 1'))->getCode());
    }

    public function test_reads_are_retried_once_after_a_connection_error(): void
    {
        DB::statement('create table t (x)');
        $failures = 0;
        $this->server->intercept = function ($request) use (&$failures) {
            return $failures++ < 1 ? Http::failedConnection('cURL error 7: Connection refused')($request) : null;
        };

        $this->assertSame([], DB::select('select * from t'));
        $this->assertSame(2, $failures);
    }

    public function test_reads_fail_after_two_connection_errors(): void
    {
        $attempts = 0;
        $this->server->intercept = function ($request) use (&$attempts) {
            $attempts++;

            return Http::failedConnection('cURL error 7: Connection refused')($request);
        };

        $e = $this->queryError(fn () => DB::select('select 1'));
        $this->assertSame(2, $attempts);
        $this->assertStringStartsWith('Could not reach SQLiteHub at https://orders--acme.mumbai.db.sqlitehub.test: cURL error 7: Connection refused', $e->getMessage());
        $this->assertSame('CONNECTION_FAILED', $e->getCode());
    }

    public function test_writes_are_never_retried(): void
    {
        $attempts = 0;
        $this->server->intercept = function ($request) use (&$attempts) {
            $attempts++;

            return Http::failedConnection('cURL error 28: Operation timed out')($request);
        };

        $this->queryError(fn () => DB::insert('insert into t values (1)'));
        $this->queryError(fn () => DB::update('update t set x = 1'));
        $this->assertSame(2, $attempts, 'neither the driver nor Laravel re-ran the writes');
    }

    public function test_reads_inside_a_transaction_are_not_retried(): void
    {
        DB::beginTransaction();
        $attempts = 0;
        $this->server->intercept = function ($request) use (&$attempts) {
            $attempts++;

            return Http::failedConnection()($request);
        };

        $this->queryError(fn () => DB::select('select 1'));
        $this->assertSame(1, $attempts);
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}
