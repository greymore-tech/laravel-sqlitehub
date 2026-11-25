<?php

namespace SQLiteHub\Laravel\Schema;

use Illuminate\Database\Schema\SQLiteBuilder;
use LogicException;

/**
 * Laravel's SQLite schema builder, minus the parts that work on local files:
 * dropping all tables (migrate:fresh, db:wipe) drops them one by one instead
 * of truncating the database file.
 */
class SQLiteHubSchemaBuilder extends SQLiteBuilder
{
    public function createDatabase($name)
    {
        throw new LogicException('Create SQLiteHub databases in the console or with the API (POST /api/v1/databases).');
    }

    public function dropDatabaseIfExists($name)
    {
        throw new LogicException('Delete SQLiteHub databases in the console or with the API.');
    }

    public function dropAllTables()
    {
        $this->dropAll('table', array_column($this->getTables('main'), 'name'));
    }

    public function dropAllViews()
    {
        $this->dropAll('view', array_column($this->getViews('main'), 'name'));
    }

    /** @param  list<string>  $names */
    private function dropAll(string $type, array $names): void
    {
        if ($names === []) {
            return;
        }

        $sql = implode('; ', array_map(fn ($name) => "drop {$type} if exists \"".str_replace('"', '""', $name).'"', $names));

        $this->withoutForeignKeyConstraints(fn () => $this->connection->unprepared($sql));
    }
}
