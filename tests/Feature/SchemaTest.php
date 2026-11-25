<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use SQLiteHub\Laravel\Schema\SQLiteHubSchemaBuilder;
use SQLiteHub\Laravel\Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_artisan_migrate_fresh_and_rollback(): void
    {
        $paths = ['--path' => __DIR__.'/../Support/migrations', '--realpath' => true];
        $this->artisan('migrate', $paths)->assertSuccessful();

        $this->assertInstanceOf(SQLiteHubSchemaBuilder::class, Schema::getFacadeRoot());
        $this->assertSame(['customers', 'migrations', 'posts'], Schema::getTableListing(schemaQualified: false));
        $this->assertTrue(Schema::hasColumns('customers', ['uuid', 'settings', 'avatar', 'deleted_at']));

        $types = array_column(Schema::getColumns('customers'), 'type_name', 'name');
        $this->assertSame('integer', $types['id']);
        $this->assertSame('varchar', $types['email']);
        $this->assertSame('numeric', $types['balance']);
        $this->assertSame('blob', $types['avatar']);
        $this->assertSame('tinyint', $types['active']);
        $this->assertContains('customers_email_unique', array_column(Schema::getIndexes('customers'), 'name'));
        $this->assertSame('customers', Schema::getForeignKeys('posts')[0]['foreign_table']);

        DB::table('customers')->insert(['uuid' => 'u', 'email' => 'e']);
        DB::statement('create view active_customers as select * from customers where active = 1');
        $this->assertSame(['active_customers'], array_column(Schema::getViews(), 'name'));

        // migrate:fresh drops every table and view one by one (no database file to truncate).
        $this->artisan('migrate:fresh', ['--drop-views' => true] + $paths)->assertSuccessful();
        $this->assertSame(0, DB::table('customers')->count());
        $this->assertSame([], Schema::getViews());

        $this->artisan('migrate:rollback', $paths)->assertSuccessful();
        $this->assertSame(['migrations'], Schema::getTableListing(schemaQualified: false));
    }

    public function test_alter_table_rebuild_keeps_data_and_foreign_keys(): void
    {
        Schema::create('parents', fn (Blueprint $t) => $t->id());
        Schema::create('kids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('nickname')->nullable();
        });
        DB::table('parents')->insert(['id' => 1]);
        DB::table('kids')->insert(['parent_id' => 1, 'name' => 'a']);

        // Changing a column makes Laravel rebuild the table with foreign keys off.
        Schema::table('kids', function (Blueprint $t) {
            $t->string('name', 50)->nullable()->change();
            $t->renameColumn('nickname', 'alias');
        });
        Schema::table('kids', fn (Blueprint $t) => $t->dropColumn('alias'));

        $this->assertSame(['id', 'parent_id', 'name'], Schema::getColumnListing('kids'));
        $this->assertSame('a', DB::table('kids')->value('name'));
        $this->assertTrue((bool) DB::scalar('pragma foreign_keys'), 'foreign keys are back on');

        DB::table('parents')->delete();
        $this->assertSame(0, DB::table('kids')->count(), 'cascade still works after the rebuild');
    }

    public function test_drop_all_on_an_empty_database_sends_no_drops(): void
    {
        Schema::dropAllTables();
        Schema::dropAllViews();

        $this->assertSame([], array_filter($this->server->statements(), fn ($sql) => str_starts_with($sql, 'drop')));
    }

    public function test_drop_all_tables_quotes_names(): void
    {
        DB::statement('create table "odd ""name""" (x)');
        Schema::dropAllTables();

        $this->assertSame([], Schema::getTables());
        $this->assertContains('drop table if exists "odd ""name"""', $this->server->statements());
    }

    public function test_databases_are_managed_in_the_console(): void
    {
        $this->expectException(LogicException::class);
        Schema::createDatabase('x');
    }

    public function test_databases_are_deleted_in_the_console(): void
    {
        $this->expectException(LogicException::class);
        Schema::dropDatabaseIfExists('x');
    }

    public function test_schema_dumps_are_not_supported(): void
    {
        $this->expectException(LogicException::class);
        $this->connection()->getSchemaState();
    }

    public function test_server_version_is_read_once(): void
    {
        $this->assertSame(\SQLite3::version()['versionString'], $this->connection()->getServerVersion());
        $this->connection()->getServerVersion();

        $this->assertSame(['select sqlite_version()'], $this->server->statements());
    }
}
