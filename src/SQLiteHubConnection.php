<?php

namespace SQLiteHub\Laravel;

use Closure;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use LogicException;
use SQLiteHub\Laravel\Hrana\HranaClient;
use SQLiteHub\Laravel\Hrana\HranaPdo;
use SQLiteHub\Laravel\Schema\SQLiteHubSchemaBuilder;
use Throwable;

/**
 * A Laravel connection to a SQLiteHub database.
 *
 * It is Laravel's SQLiteConnection (SQLite query and schema grammars, post
 * processor, unique-constraint detection) on top of HranaPdo, which sends
 * each statement to SQLiteHub over HTTPS.
 */
class SQLiteHubConnection extends SQLiteConnection
{
    /**
     * Build a connection from its config (see the README for the keys).
     *
     * @param  Closure(): Factory  $http
     */
    public static function fromConfig(array $config, string $name, Closure $http): self
    {
        $endpoint = $config['endpoint'] ?? null;
        if (($endpoint === null || $endpoint === '') && ! empty($config['host'])) {
            // From Laravel's parsing of a 'url' => 'libsql://host[:port]' (driver "libsql").
            $endpoint = 'https://'.$config['host'].(empty($config['port']) ? '' : ':'.$config['port']);
        }

        $client = new HranaClient(
            $http,
            (string) $endpoint,
            $config['token'] ?? null,
            $config['host_header'] ?? null,
            (float) ($config['timeout'] ?? 30),
            (float) ($config['connect_timeout'] ?? 10),
        );

        // A read-only token can't run PRAGMA foreign_keys (sqld counts it as a write), and doesn't need it.
        $foreignKeys = self::tokenIsReadOnly($config['token'] ?? null) ? null : ($config['foreign_key_constraints'] ?? null);
        $config['name'] = $name;
        $config['host'] ??= parse_url($client->url, PHP_URL_HOST);

        return new self(
            fn () => new HranaPdo($client, $foreignKeys === null ? null : (bool) $foreignKeys),
            $config['database'] ?? $config['host'],
            $config['prefix'] ?? '',
            $config,
        );
    }

    /** Whether a SQLiteHub token carries the read-only claim ("a": "ro"). The signature isn't checked here. */
    public static function tokenIsReadOnly(?string $token): bool
    {
        $payload = explode('.', (string) $token)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims) && ($claims['a'] ?? null) === 'ro';
    }

    public function getDriverTitle()
    {
        return 'SQLiteHub';
    }

    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SQLiteHubSchemaBuilder($this);
    }

    /** schema:dump and schema loading need the sqlite3 CLI and a local file. */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        throw new LogicException('Schema dumps are not supported on SQLiteHub connections: they need the sqlite3 CLI and a local database file.');
    }

    /**
     * Never let Laravel reconnect and re-run a statement after a network
     * error: that would repeat writes. HranaClient retries reads itself.
     */
    protected function causedByLostConnection(Throwable $e)
    {
        return false;
    }
}
