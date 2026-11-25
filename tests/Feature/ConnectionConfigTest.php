<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SQLiteHub\Laravel\Hrana\HranaClient;
use SQLiteHub\Laravel\Hrana\HranaPdo;
use SQLiteHub\Laravel\SQLiteHubConnection;
use SQLiteHub\Laravel\Tests\TestCase;

class ConnectionConfigTest extends TestCase
{
    private function connect(array $config, string $name = 'other'): SQLiteHubConnection
    {
        config()->set("database.connections.{$name}", $config);

        return DB::connection($name);
    }

    public function test_the_driver_is_registered(): void
    {
        $connection = $this->connection();

        $this->assertInstanceOf(SQLiteHubConnection::class, $connection);
        $this->assertSame('sqlitehub', $connection->getDriverName());
        $this->assertSame('SQLiteHub', $connection->getDriverTitle());
        $this->assertSame('sqlitehub', $connection->getName());
        $this->assertSame('orders--acme.mumbai.db.sqlitehub.test', $connection->getDatabaseName());
        $this->assertInstanceOf(HranaPdo::class, $connection->getPdo());
        $this->assertSame([], $this->server->requests, 'connecting sends nothing');
    }

    public function test_libsql_url_becomes_https_and_the_token_is_a_bearer_token(): void
    {
        DB::select('select 1');

        $request = $this->server->requests[0];
        $this->assertSame('https://orders--acme.mumbai.db.sqlitehub.test/v3/pipeline', $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertSame(['Bearer test-token'], $request->header('Authorization'));
        $this->assertSame(['application/json'], $request->header('Content-Type'));
        $this->assertSame(['type' => 'execute', 'stmt' => ['sql' => 'PRAGMA foreign_keys = ON', 'want_rows' => false]], $request->data()['requests'][0]);
    }

    public function test_url_forms(): void
    {
        $this->assertSame('https://db.example.test', HranaClient::normalizeUrl(' libsql://db.example.test/ '));
        $this->assertSame('https://db.example.test:8443/base', HranaClient::normalizeUrl('https://db.example.test:8443/base/'));
        $this->assertSame('http://127.0.0.1:8080', HranaClient::normalizeUrl('http://127.0.0.1:8080'));
        $this->assertSame('https://db.example.test', HranaClient::normalizeUrl('wss://db.example.test'));
        $this->assertSame('http://db.example.test', HranaClient::normalizeUrl('ws://db.example.test'));
    }

    public function test_unsupported_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported SQLiteHub URL scheme [ftp]');
        HranaClient::normalizeUrl('ftp://db.example.test');
    }

    public function test_missing_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Set the SQLiteHub URL (SQLITEHUB_URL)');
        $this->connect(['driver' => 'sqlitehub', 'endpoint' => null]);
    }

    public function test_laravel_url_key_with_libsql_scheme(): void
    {
        // Laravel parses 'url' itself: the scheme becomes the driver ("libsql") and the host a key.
        $connection = $this->connect(['driver' => 'sqlitehub', 'url' => 'libsql://orders--acme.mumbai.db.sqlitehub.test:8443?timeout=3', 'token' => 't']);
        $connection->select('select 1');

        $this->assertSame('libsql', $connection->getDriverName());
        $this->assertSame('https://orders--acme.mumbai.db.sqlitehub.test:8443/v3/pipeline', end($this->server->requests)->url());
    }

    public function test_plain_http_host_header_and_no_token(): void
    {
        $connection = $this->connect(['driver' => 'sqlitehub', 'endpoint' => 'http://127.0.0.1:18080', 'host_header' => 'orders--acme.db.test', 'foreign_key_constraints' => false, 'database' => 'orders']);
        $connection->select('select 1');

        $request = end($this->server->requests);
        $this->assertSame('http://127.0.0.1:18080/v3/pipeline', $request->url());
        $this->assertSame(['orders--acme.db.test'], $request->header('Host'));
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertSame('PRAGMA foreign_keys = OFF', $request->data()['requests'][0]['stmt']['sql']);
        $this->assertSame('orders', $connection->getDatabaseName());
    }

    public function test_without_foreign_key_setting_no_pragma_is_sent(): void
    {
        $this->connect(['driver' => 'sqlitehub', 'endpoint' => 'https://db.example.test'])->select('select 1');

        $this->assertSame(['execute', 'close'], array_column(end($this->server->requests)->data()['requests'], 'type'));
    }

    public function test_read_only_tokens_skip_the_pragma(): void
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $ro = $b64(['alg' => 'EdDSA']).'.'.$b64(['id' => 'orders--acme', 'a' => 'ro']).'.sig';
        $rw = $b64(['alg' => 'EdDSA']).'.'.$b64(['id' => 'orders--acme', 'a' => 'rw']).'.sig';

        $this->assertTrue(SQLiteHubConnection::tokenIsReadOnly($ro));
        $this->assertFalse(SQLiteHubConnection::tokenIsReadOnly($rw));
        $this->assertFalse(SQLiteHubConnection::tokenIsReadOnly('opaque'));
        $this->assertFalse(SQLiteHubConnection::tokenIsReadOnly(null));

        $this->connect(['driver' => 'sqlitehub', 'endpoint' => 'https://db.example.test', 'token' => $ro, 'foreign_key_constraints' => true])->select('select 1');
        $this->assertSame(['execute', 'close'], array_column(end($this->server->requests)->data()['requests'], 'type'));
    }

    public function test_foreign_key_pragma_is_remembered_across_streams(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->assertSame(0, DB::scalar('pragma foreign_keys'));
        $this->assertSame('PRAGMA foreign_keys = OFF', end($this->server->requests)->data()['requests'][0]['stmt']['sql']);

        DB::statement("pragma foreign_keys = 'on'");
        $this->assertSame(1, DB::scalar('pragma foreign_keys'));

        // Inside a transaction SQLite ignores it, and so does the driver.
        DB::transaction(fn () => DB::statement('PRAGMA foreign_keys = OFF'));
        $this->assertSame(1, DB::scalar('pragma foreign_keys'));
    }

    public function test_a_failing_pragma_does_not_block_the_statement(): void
    {
        $this->server->intercept = function ($request) {
            $this->server->intercept = null;
            $response = json_decode((string) ($this->server)($request)->wait()->getBody(), true);
            array_pop($this->server->requests);
            $response['results'][0] = ['type' => 'error', 'error' => ['message' => 'not allowed']];

            return Http::response($response);
        };

        $this->assertSame(1, DB::scalar('select 1'));
    }

    public function test_timeouts_are_passed_to_the_http_client(): void
    {
        $options = null;
        Http::fake(function ($request, $o) use (&$options) {
            $options = $o;

            return ($this->server)($request);
        });

        $this->connect(['driver' => 'sqlitehub', 'endpoint' => 'https://db.example.test', 'timeout' => 7, 'connect_timeout' => 2])->select('select 1');

        $this->assertSame(7.0, (float) $options['timeout']);
        $this->assertSame(2.0, (float) $options['connect_timeout']);
    }
}
