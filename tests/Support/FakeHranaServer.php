<?php

namespace SQLiteHub\Laravel\Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PDO;
use PDOException;

/**
 * A small Hrana-over-HTTP server for Http::fake(), backed by a real SQLite
 * file: each stream (baton) is its own SQLite connection, as in sqld.
 *
 * Supports execute, sequence, get_autocommit and close; answers unknown
 * batons with HTTP 400 like sqld does for expired streams.
 */
class FakeHranaServer
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var array<string, PDO> */
    private array $streams = [];

    /** Answer a request before the server does (return null to let it through). */
    public ?Closure $intercept = null;

    public readonly string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'hrana').'.sqlite';
    }

    public function __destruct()
    {
        $this->streams = [];
        @unlink($this->path);
    }

    public function __invoke(Request $request): PromiseInterface
    {
        $this->requests[] = $request;

        if ($this->intercept && ($answer = ($this->intercept)($request)) !== null) {
            return $answer;
        }

        $body = $request->data();
        $baton = $body['baton'] ?? null;
        if ($baton !== null && ! isset($this->streams[$baton])) {
            return Http::response(['error' => 'Stream expired'], 400);
        }

        $pdo = $baton === null ? $this->connect() : $this->streams[$baton];
        unset($this->streams[$baton ?? '']);

        $closed = false;
        $results = [];
        foreach ($body['requests'] as $req) {
            $results[] = $this->handle($pdo, $req, $closed);
        }

        $next = null;
        if (! $closed) {
            $next = bin2hex(random_bytes(8));
            $this->streams[$next] = $pdo;
        }

        return Http::response(['baton' => $next, 'base_url' => null, 'results' => $results]);
    }

    /** Pipelines sent so far: the requests array of each HTTP request. */
    public function pipelines(): array
    {
        return array_map(fn (Request $r) => $r->data()['requests'], $this->requests);
    }

    /** SQL of every execute/sequence sent, in order, without the per-stream PRAGMA. */
    public function statements(): array
    {
        $sql = [];
        foreach ($this->pipelines() as $requests) {
            foreach ($requests as $req) {
                $text = $req['stmt']['sql'] ?? $req['sql'] ?? null;
                if ($text !== null && ! str_starts_with($text, 'PRAGMA foreign_keys')) {
                    $sql[] = $text;
                }
            }
        }

        return $sql;
    }

    public function openStreams(): int
    {
        return count($this->streams);
    }

    /** Direct access to the database, outside any stream. */
    public function db(): PDO
    {
        return $this->connect();
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 0);

        return $pdo;
    }

    private function handle(PDO $pdo, array $req, bool &$closed): array
    {
        try {
            return match ($req['type']) {
                'execute' => ['type' => 'ok', 'response' => ['type' => 'execute', 'result' => $this->execute($pdo, $req['stmt'])]],
                'sequence' => tap(['type' => 'ok', 'response' => ['type' => 'sequence']], fn () => $pdo->exec($req['sql'])),
                'get_autocommit' => ['type' => 'ok', 'response' => ['type' => 'get_autocommit', 'is_autocommit' => self::autocommit($pdo)]],
                'close' => tap(['type' => 'ok', 'response' => ['type' => 'close']], function () use (&$closed) {
                    $closed = true;
                }),
            };
        } catch (PDOException $e) {
            return ['type' => 'error', 'error' => [
                'message' => 'SQLite error: '.($e->errorInfo[2] ?? $e->getMessage()),
                'code' => ($e->errorInfo[1] ?? null) === 19 ? 'SQLITE_CONSTRAINT' : 'SQLITE_ERROR',
            ]];
        }
    }

    private function execute(PDO $pdo, array $stmt): array
    {
        $sql = $stmt['sql'];
        $args = $stmt['args'] ?? [];

        // PDO binds floats as text; make SQLite see a REAL like sqld would.
        $n = 0;
        $sql = preg_replace_callback('/\?/', function () use (&$n, $args) {
            return ($args[$n++]['type'] ?? null) === 'float' ? 'CAST(? AS REAL)' : '?';
        }, $sql);

        $statement = $pdo->prepare($sql);
        foreach ($args as $i => $value) {
            $this->bind($statement, $i + 1, $value);
        }
        foreach ($stmt['named_args'] ?? [] as $arg) {
            $this->bind($statement, $arg['name'], $arg['value']);
        }
        $statement->execute();

        $cols = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            $cols[] = ['name' => $meta['name'], 'decltype' => $meta['sqlite:decl_type'] ?? null];
        }

        $rows = ($stmt['want_rows'] ?? true) ? array_map(
            fn ($row) => array_map($this->encode(...), $row),
            $statement->fetchAll(PDO::FETCH_NUM),
        ) : [];

        return [
            'cols' => $cols,
            'rows' => $rows,
            'affected_row_count' => $statement->columnCount() > 0 ? 0 : $statement->rowCount(),
            'last_insert_rowid' => $pdo->lastInsertId() === '0' ? null : (string) $pdo->lastInsertId(),
            'rows_read' => 0,
            'rows_written' => 0,
            'query_duration_ms' => 0.1,
        ];
    }

    private function bind(\PDOStatement $statement, int|string $key, array $value): void
    {
        match ($value['type']) {
            'null' => $statement->bindValue($key, null, PDO::PARAM_NULL),
            'integer' => $statement->bindValue($key, (int) $value['value'], PDO::PARAM_INT),
            'float' => $statement->bindValue($key, (string) $value['value']),
            'text' => $statement->bindValue($key, $value['value']),
            'blob' => $statement->bindValue($key, base64_decode($value['base64']), PDO::PARAM_LOB),
        };
    }

    private function encode(mixed $value): array
    {
        return match (true) {
            $value === null => ['type' => 'null'],
            is_int($value) => ['type' => 'integer', 'value' => (string) $value],
            is_float($value) => ['type' => 'float', 'value' => $value],
            ! mb_check_encoding($value, 'UTF-8') => ['type' => 'blob', 'base64' => base64_encode($value)],
            default => ['type' => 'text', 'value' => $value],
        };
    }

    /**
     * Whether the SQLite connection is outside a transaction. PDO::inTransaction() only sees transactions
     * opened through PDO before PHP 8.4, not an SQL BEGIN, so ask SQLite: BEGIN fails inside a transaction.
     */
    private static function autocommit(\PDO $pdo): bool
    {
        try {
            $pdo->exec('BEGIN');
        } catch (\PDOException) {
            return false;
        }
        $pdo->exec('ROLLBACK');

        return true;
    }
}
