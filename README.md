# SQLiteHub for Laravel

A Laravel database driver for [SQLiteHub](https://sqlitehub.in) (libSQL / sqld) databases. It is **pure PHP over HTTPS**: it speaks Hrana over HTTP (`POST /v3/pipeline`) with Laravel's HTTP client, so it needs no FFI, no libSQL extension and no native library. It runs on Cloud Run, shared hosting, Vapor/Lambda and anywhere else Laravel runs.

Query builder, Eloquent, migrations, the schema builder and transactions (including nested ones) work as they do with Laravel's `sqlite` driver, because the driver reuses Laravel's SQLite grammars and processor.

Requirements: PHP 8.2+ with `ext-pdo` (on by default; no PDO *driver* is needed), Laravel 12 or 13.

## Install

```sh
composer require greymore/laravel-sqlitehub
```

The service provider (`SQLiteHub\Laravel\SQLiteHubServiceProvider`) is auto-discovered. Requires PHP 8.2+ (8.3+ with Laravel 13), Laravel 12 or 13, and the `pdo` and `json` extensions; no SQLite extension, FFI or native libSQL library is needed, so it runs on Cloud Run, Lambda and shared hosting.

Not on Packagist yet in your mirror? Install from GitHub directly:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/greymore-tech/laravel-sqlitehub" }]
```

## Configure

In `config/database.php`:

```php
'connections' => [
    'sqlitehub' => [
        'driver' => 'sqlitehub',
        'endpoint' => env('SQLITEHUB_URL'),     // libsql://orders--acme.mumbai.db.sqlitehub.in or https://…
        'token' => env('SQLITEHUB_TOKEN'),      // a connection token (read-write or read-only)
        'prefix' => '',
        'foreign_key_constraints' => true,      // PRAGMA foreign_keys for every request; null = server default
        'timeout' => 30,                        // seconds per HTTP request
        'connect_timeout' => 10,
    ],
],
```

and in `.env`:

```sh
DB_CONNECTION=sqlitehub
SQLITEHUB_URL=libsql://orders--acme.mumbai.db.sqlitehub.in
SQLITEHUB_TOKEN=eyJhbGciOiJFZERTQSIs…
```

Get the URL and a token from the console (*Databases → your database → Connection tokens*) or the API.

| Key | Meaning |
|---|---|
| `endpoint` | `libsql://host` (sent as `https://host`), `https://host` or `http://host:port`. `wss://`/`ws://` are mapped to `https://`/`http://` |
| `token` | Sent as `Authorization: Bearer <token>`. Leave empty for an sqld without auth |
| `foreign_key_constraints` | `true`/`false` sends `PRAGMA foreign_keys = ON/OFF` at the start of every request (see [Connection state](#connection-state)). Skipped for read-only tokens, which can't run it |
| `timeout`, `connect_timeout` | HTTP timeouts in seconds |
| `host_header` | Optional `Host` header, to reach a namespace of a self-hosted sqld by IP (e.g. `endpoint` `http://127.0.0.1:8080`, `host_header` `orders.db.example`) |
| `database` | Name shown in error messages; defaults to the host |

**Why `endpoint` and not `url`?** Laravel parses a connection's `url` key itself and turns its scheme into the driver name. `'url' => 'libsql://…'` still works (the package also registers a `libsql` driver for that case, and the URL's host and port are used), but `'url' => 'https://…'` would ask Laravel for a driver called `https`. Use `endpoint`.

## Use

Nothing changes in your code:

```php
DB::table('orders')->insert(['total' => 499.0, 'created_at' => now()]);
$recent = Order::where('total', '>', 100)->latest()->limit(10)->get();
$id = DB::table('orders')->insertGetId(['total' => 120]);

Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->decimal('total', 10, 2);
    $table->timestamps();
});
```

`php artisan migrate`, `migrate:fresh` (tables are dropped one by one), `migrate:rollback`, model factories and seeders all work.

Several connections (one per database) are just several entries in `connections` with their own URL and token.

### Transactions

```php
DB::transaction(function () {
    DB::table('stock')->where('sku', 'A1')->decrement('qty');
    Order::create(['total' => 120]);
});
```

Outside a transaction every statement is its own Hrana stream: one HTTPS request containing `execute` + `close`. `BEGIN` opens a stream that the driver keeps: each response carries a *baton* that the next request sends back, so all statements of the transaction run on the same server-side connection. `COMMIT`/`ROLLBACK` is sent together with `close`. Nested `DB::transaction()` calls use savepoints, exactly as with Laravel's SQLite driver, and an exception rolls back.

Keep transactions short: sqld drops a stream that is idle for about 10 seconds, and then the next statement fails with `STREAM_EXPIRED: The stream has expired due to inactivity` and the transaction is rolled back (the driver resets its state so Laravel's rollback doesn't try again).

### Errors

Errors are Laravel's `Illuminate\Database\QueryException` (or `UniqueConstraintViolationException`, with `->columns`) carrying sqld's message and code, e.g.

```
SQLITE_CONSTRAINT: SQLite error: UNIQUE constraint failed: users.email (Connection: sqlitehub, …, SQL: insert into …)
```

Errors from the SQLiteHub edge keep their code, e.g. `TOKEN_EXPIRED: … (HTTP 401)`, `TOKEN_WRONG_DATABASE: … (HTTP 403)`; the [troubleshooting table](../../docs/GUIDE.md#troubleshooting) lists them. `$e->getCode()` is that code, and `$e->getPrevious()` is a `SQLiteHub\Laravel\Hrana\HranaException` (a `PDOException`) with `errorCode` and the HTTP `status`.

### Retries

A read (`SELECT`, `EXPLAIN`, `VALUES`) outside a transaction is retried once if the HTTP connection fails (DNS, connect, reset, timeout). Writes and anything inside a transaction are **never** retried, by the driver or by Laravel's lost-connection handling (which this driver turns off), because a write whose answer was lost may already have been applied.

## Performance

- **One HTTPS round trip per statement.** From Cloud Run in `asia-south1` to a Mumbai database that's a few milliseconds; from elsewhere it's the network latency. N+1 queries cost N round trips, so eager-load relations (`with()`) and prefer set-based updates.
- A transaction costs one round trip per statement plus one for `BEGIN` (the `COMMIT` rides with `close`).
- The HTTPS connection is kept alive and reused for every statement of a connection (one curl handler per connection), so only the first statement pays for the TCP and TLS handshake. Under PHP-FPM that is once per web request; under Octane/RoadRunner/queue workers it stays warm across requests.
- Large result sets are sent in one response; `cursor()`/`lazy()` work but don't stream from the server. Page with `chunkById()`/`lazyById()` for big tables.

## How it works, and limits

Laravel's `Connection` is built around PDO: it type-hints `PDOStatement` (`Connection::prepared()`), and transactions, `insertGetId()` (`Processor::processInsertGetId()`), `DB::escape()` and `getServerVersion()` call `getPdo()->commit()`, `->lastInsertId()`, `->quote()`, `->getAttribute()` and `->exec()` directly. So the driver supplies a PDO look-alike: `HranaPdo extends PDO` and `HranaStatement extends PDOStatement`, constructed without PDO's constructor (PHP allows this; no PDO driver is involved), with every method Laravel uses overridden to send Hrana requests. `SQLiteHubConnection` extends Laravel's `SQLiteConnection`, so the SQLite grammars, processor and unique-violation parsing are Laravel's own.

What doesn't work:

- **PDO methods Laravel doesn't use** (`bindParam`, `PDO::sqliteCreateFunction`, …) and PDO attributes other than the error mode, driver name and server version. Calling one of the PDO methods that isn't overridden throws "object is uninitialized". `selectResultSets()` returns one set.
- **Connection state between statements.** Each statement outside a transaction runs on a new server-side connection. `PRAGMA foreign_keys` is handled (replayed per request; `Schema::disableForeignKeyConstraints()` works), but other pragmas, `TEMP` tables and `ATTACH` don't carry over. Put such work inside a transaction.
- **`schema:dump` / schema files** (`php artisan schema:dump`, loading `database/schema/*.sql`) need the `sqlite3` CLI and a local file. `php artisan db` (the `sqlite3` shell) doesn't work either; use the console or any libSQL shell.
- `Schema::createDatabase()` / `dropDatabaseIfExists()`: create and delete databases in the console or with the API.
- `cursor()` buffers the whole result (see Performance).
- WebSockets are not used; Hrana over HTTP only.

## Development

```sh
cd packages/laravel
composer install
vendor/bin/phpunit
```

The unit and feature tests run Laravel against a fake Hrana server (`tests/Support/FakeHranaServer.php`, backed by a real SQLite file through `Http::fake()`). The integration test runs migrations, Eloquent and transactions against a real sqld and is skipped unless `SQLD_TEST_HTTP_URL` and `SQLD_TEST_ADMIN_URL` are set:

```sh
docker compose -f ../../compose.test.yml up -d sqld-test
SQLD_TEST_HTTP_URL=http://127.0.0.1:18080 SQLD_TEST_ADMIN_URL=http://127.0.0.1:19090 vendor/bin/phpunit
```

CI (`laravel-driver` job) requires every test to run and 100% line coverage of `src/`:

```sh
php -d extension=pcov.so vendor/bin/phpunit --coverage-clover=coverage.xml && php tests/coverage-check.php coverage.xml
```
