<?php

namespace SQLiteHub\Laravel\Hrana;

use ArrayIterator;
use Iterator;
use PDO;
use PDOStatement;
use stdClass;

/**
 * A PDOStatement look-alike: binds values, runs the statement through
 * HranaPdo when execute() is called, and serves the rows it got back.
 *
 * Fetch modes: FETCH_OBJ (Laravel's default), FETCH_ASSOC, FETCH_NUM,
 * FETCH_BOTH, FETCH_COLUMN, FETCH_KEY_PAIR and FETCH_CLASS.
 */
class HranaStatement extends PDOStatement
{
    /** @var array<int, array<string, mixed>> positional arguments, keyed from 1 */
    private array $positional = [];

    /** @var array<string, array<string, mixed>> */
    private array $named = [];

    /** @var list<string> */
    private array $columns = [];

    /** @var list<?string> */
    private array $declTypes = [];

    /** @var list<list<mixed>> */
    private array $rows = [];

    private int $cursor = 0;

    private int $rowCount = 0;

    private int $fetchMode = PDO::FETCH_BOTH;

    /** @var list<mixed> */
    private array $fetchArgs = [];

    public function __construct(private readonly HranaPdo $pdo, public readonly string $sql)
    {
        // PDOStatement has no constructor to call.
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $encoded = Values::encode($value, $type);

        if (is_int($param)) {
            $this->positional[$param] = $encoded;
        } else {
            $this->named[$param] = $encoded;
        }

        return true;
    }

    public function execute(?array $params = null): bool
    {
        foreach ($params ?? [] as $key => $value) {
            $this->bindValue(is_int($key) ? $key + 1 : $key, $value);
        }

        ksort($this->positional);
        $stmt = ['sql' => $this->sql, 'args' => array_values($this->positional)];
        if ($this->named !== []) {
            $stmt['named_args'] = array_map(
                fn ($name, $value) => ['name' => $name, 'value' => $value],
                array_keys($this->named), $this->named,
            );
        }

        $result = $this->pdo->run(['type' => 'execute', 'stmt' => $stmt], $this->sql);

        $this->columns = array_map(fn ($col) => (string) ($col['name'] ?? ''), $result['cols'] ?? []);
        $this->declTypes = array_map(fn ($col) => $col['decltype'] ?? null, $result['cols'] ?? []);
        $this->rows = array_map(fn ($row) => array_map(Values::decode(...), $row), $result['rows'] ?? []);
        $this->rowCount = (int) ($result['affected_row_count'] ?? 0);
        $this->cursor = 0;

        return true;
    }

    public function setFetchMode(int $mode, mixed ...$args): true
    {
        $this->fetchMode = $mode;
        $this->fetchArgs = $args;

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (! isset($this->rows[$this->cursor])) {
            return false;
        }

        $args = $mode === PDO::FETCH_DEFAULT ? $this->fetchArgs : [];

        return $this->shape($this->rows[$this->cursor++], $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode, $args);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        [$mode, $args] = $mode === PDO::FETCH_DEFAULT ? [$this->fetchMode, $this->fetchArgs] : [$mode, $args];
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        if ($mode === PDO::FETCH_KEY_PAIR) {
            return array_column(array_map(fn ($row) => [$row[0], $row[1] ?? null], $rows), 1, 0);
        }

        return array_map(fn ($row) => $this->shape($row, $mode, $args), $rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return isset($this->rows[$this->cursor]) ? ($this->rows[$this->cursor++][$column] ?? null) : false;
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $row = $this->fetch(PDO::FETCH_ASSOC);

        return $row === false ? false : $this->hydrate($row, $class ?? stdClass::class, $constructorArgs);
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->fetchAll());
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function getColumnMeta(int $column): array|false
    {
        if (! isset($this->columns[$column])) {
            return false;
        }

        return ['name' => $this->columns[$column], 'native_type' => $this->declTypes[$column], 'sqlite:decl_type' => $this->declTypes[$column], 'flags' => [], 'len' => -1, 'precision' => 0, 'pdo_type' => PDO::PARAM_STR];
    }

    public function closeCursor(): bool
    {
        $this->rows = [];
        $this->cursor = 0;

        return true;
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function errorCode(): ?string
    {
        return '00000';
    }

    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    /** @param  list<mixed>  $row */
    private function shape(array $row, int $mode, array $args): mixed
    {
        $assoc = fn () => array_combine($this->columns, $row);

        return match ($mode) {
            PDO::FETCH_OBJ => (object) $assoc(),
            PDO::FETCH_ASSOC => $assoc(),
            PDO::FETCH_NUM => $row,
            PDO::FETCH_BOTH => $assoc() + $row,
            PDO::FETCH_COLUMN => $row[$args[0] ?? 0] ?? null,
            PDO::FETCH_CLASS => $this->hydrate($assoc(), $args[0] ?? stdClass::class, $args[1] ?? []),
            default => throw new HranaException("PDO fetch mode {$mode} is not supported by the SQLiteHub driver."),
        };
    }

    /** @param  array<string, mixed>  $row */
    private function hydrate(array $row, string $class, array $constructorArgs): object
    {
        $object = new $class(...$constructorArgs);
        foreach ($row as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }
}
