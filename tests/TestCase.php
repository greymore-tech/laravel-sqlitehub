<?php

namespace SQLiteHub\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use SQLiteHub\Laravel\Hrana\HranaPdo;
use SQLiteHub\Laravel\SQLiteHubConnection;
use SQLiteHub\Laravel\SQLiteHubServiceProvider;
use SQLiteHub\Laravel\Tests\Support\FakeHranaServer;

/** Runs Laravel with the default connection on a fake SQLiteHub (Http::fake + FakeHranaServer). */
abstract class TestCase extends Orchestra
{
    protected FakeHranaServer $server;

    protected function getPackageProviders($app)
    {
        return [SQLiteHubServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'sqlitehub');
        $app['config']->set('database.connections.sqlitehub', $this->connectionConfig());

        // Before migrations run (they run during setUp), and never to a real host.
        $this->server = new FakeHranaServer;
        Http::preventStrayRequests();
        Http::fake(['*' => fn ($request) => ($this->server)($request)]);
    }

    protected function connectionConfig(): array
    {
        return [
            'driver' => 'sqlitehub',
            'endpoint' => 'libsql://orders--acme.mumbai.db.sqlitehub.test',
            'token' => 'test-token',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'timeout' => 5,
        ];
    }

    protected function connection(): SQLiteHubConnection
    {
        return DB::connection();
    }

    protected function pdo(): HranaPdo
    {
        return $this->connection()->getPdo();
    }
}
