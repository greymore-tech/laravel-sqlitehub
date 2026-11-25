<?php

namespace SQLiteHub\Laravel\Hrana;

use PDO;
use PDOStatement;
use Throwable;

/**
 * A PDO look-alike that runs SQL on SQLiteHub over Hrana-over-HTTP.
 *
 * It extends PDO (without calling PDO's constructor, so no PDO driver is
 * needed) because Laravel's Connection type-hints PDOStatement and calls
 * getPdo()->commit(), ->lastInsertId(), ->exec() … from many places. Every
 * PDO method Laravel uses is overridden here; the rest are not available.
 *
 * Streams: outside a transaction every statement is its own stream
 * ([execute, close], one HTTP request). BEGIN opens a stream that is kept
 * (its baton is sent with each request) until COMMIT/ROLLBACK closes it.
 * Each pipeline on an open stream also asks get_autocommit, so the
 * transaction state always matches the server's.
 *
 * Connection settings: each stream is a new SQLite connection on the server,
 * so `PRAGMA foreign_keys` is replayed at the start of every stream with the
 * value from the config, or from the last `PRAGMA foreign_keys = …` run here.
 */
class HranaPdo extends PDO
{
    private ?string $baton = null;

    private ?string $baseUrl = null;

    private bool $inTransaction = false;

    private string|false $lastInsertId = false;

    private ?string $serverVersion = null;

    /** @var array<int, mixed> */
    private array $attributes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    /** @param  bool|null  $foreignKeys  PRAGMA foreign_keys for every stream (null: the server's default) */
    public function __construct(public readonly HranaClient $client, private ?bool $foreignKeys = null)
    {
        // PDO::__construct is deliberately not called: there is no local driver.
    }

    public function __destruct()
    {
        $this->closeStream();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new HranaStatement($this, $query);
    }

    /** Runs one statement, or several separated by ";" (as a Hrana sequence). Returns the affected rows (0 for a sequence). */
    public function exec(string $statement): int|false
    {
        $sql = rtrim(trim($statement), "; \n\r\t");

        if (str_contains($sql, ';')) {
            $this->run(['type' => 'sequence', 'sql' => $sql], $sql);

            return 0;
        }

        return (int) $this->run(['type' => 'execute', 'stmt' => ['sql' => $sql, 'want_rows' => false]], $sql)['affected_row_count'];
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);
        if ($fetchMode !== null) {
            $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
        }
        $statement->execute();

        return $statement;
    }

    /** SQLite quoting: single quotes doubled. */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'".str_replace("'", "''", $string)."'";
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new HranaException('There is already an active transaction');
        }

        $this->exec('BEGIN');

        return true;
    }

    public function commit(): bool
    {
        return $this->finishTransaction('COMMIT');
    }

    public function rollBack(): bool
    {
        return $this->finishTransaction('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertId;
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'sqlitehub',
            PDO::ATTR_SERVER_VERSION => $this->serverVersion ??= (string) $this->query('select sqlite_version()')->fetchColumn(),
            PDO::ATTR_CLIENT_VERSION => 'hrana-http/3',
            default => $this->attributes[$attribute] ?? null,
        };
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    public function errorCode(): ?string
    {
        return '00000';
    }

    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    /** The baton of the open stream (inside a transaction), for tests and debugging. */
    public function baton(): ?string
    {
        return $this->baton;
    }

    /** Close the open stream, if any, without committing. Errors are ignored: sqld drops idle streams anyway. */
    public function closeStream(): void
    {
        if ($this->baton === null) {
            return;
        }

        [$baton, $baseUrl] = [$this->baton, $this->baseUrl];
        $this->resetStream();

        try {
            $this->client->pipeline([['type' => 'close']], $baton, $baseUrl);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Run one execute or sequence request and return its result.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed> the StmtResult (empty for a sequence)
     *
     * @throws HranaException
     */
    public function run(array $request, string $sql): array
    {
        $requests = [];
        $fresh = $this->baton === null;
        if ($fresh && $this->foreignKeys !== null) {
            $requests[] = ['type' => 'execute', 'stmt' => ['sql' => 'PRAGMA foreign_keys = '.($this->foreignKeys ? 'ON' : 'OFF'), 'want_rows' => false]];
        }
        $index = count($requests);
        $requests[] = $request;

        // Keep the stream if a transaction is open or this statement may open one.
        $keep = $this->inTransaction || preg_match('/^\s*(BEGIN|SAVEPOINT)\b/i', $sql) === 1;
        $requests[] = $keep ? ['type' => 'get_autocommit'] : ['type' => 'close'];

        $idempotent = ! $keep && preg_match('/^\s*(SELECT|EXPLAIN|VALUES)\b/i', $sql) === 1;

        try {
            $response = $this->client->pipeline($requests, $this->baton, $this->baseUrl, $idempotent);
        } catch (HranaException $e) {
            // The stream (and any transaction on it) can't be trusted any more.
            $this->resetStream();

            throw $e;
        }

        // The PRAGMA's own result is ignored: a failure there must not block the statement.
        $results = $response['results'];
        if ($keep) {
            $autocommit = $results[$index + 1]['response']['is_autocommit'] ?? true;
            $this->baton = $response['baton'];
            $this->baseUrl = $response['base_url'] ?? $this->baseUrl;
            $this->inTransaction = ! $autocommit && $this->baton !== null;
            if (! $this->inTransaction) {
                $this->closeStream();
            }
        }

        $result = $results[$index];
        if ($result['type'] !== 'ok') {
            throw HranaException::fromError($result['error'] ?? []);
        }

        // Like SQLite, ignore it inside a transaction (where it is a no-op).
        if (! $keep && preg_match('/^\s*PRAGMA\s+(?:main\.)?foreign_keys\s*=\s*[\'"]?(\w+)/i', $sql, $m)) {
            $this->foreignKeys = in_array(strtolower($m[1]), ['1', 'on', 'true', 'yes'], true);
        }

        $stmt = $result['response']['result'] ?? [];
        if (isset($stmt['last_insert_rowid'])) {
            $this->lastInsertId = (string) $stmt['last_insert_rowid'];
        }

        return $stmt;
    }

    private function finishTransaction(string $sql): bool
    {
        if (! $this->inTransaction) {
            throw new HranaException('There is no active transaction');
        }

        [$baton, $baseUrl] = [$this->baton, $this->baseUrl];
        $this->resetStream();

        $results = $this->client->pipeline([
            ['type' => 'execute', 'stmt' => ['sql' => $sql, 'want_rows' => false]],
            ['type' => 'close'],
        ], $baton, $baseUrl)['results'];

        if ($results[0]['type'] !== 'ok') {
            throw HranaException::fromError($results[0]['error'] ?? []);
        }

        return true;
    }

    private function resetStream(): void
    {
        $this->baton = null;
        $this->baseUrl = null;
        $this->inTransaction = false;
    }
}
