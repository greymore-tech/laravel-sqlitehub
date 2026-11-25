<?php

namespace SQLiteHub\Laravel;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the "sqlitehub" database driver (and "libsql", the driver
 * Laravel derives from a 'url' => 'libsql://…' config).
 */
class SQLiteHubServiceProvider extends ServiceProvider
{
    public const DRIVERS = ['sqlitehub', 'libsql'];

    public function register(): void
    {
        $this->callAfterResolving('db', function (DatabaseManager $db) {
            foreach (self::DRIVERS as $driver) {
                $db->extend($driver, fn (array $config, string $name) => SQLiteHubConnection::fromConfig(
                    $config, $name, fn () => $this->app->make(Factory::class),
                ));
            }
        });
    }
}
