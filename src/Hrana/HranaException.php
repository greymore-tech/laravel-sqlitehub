<?php

namespace SQLiteHub\Laravel\Hrana;

use PDOException;
use Throwable;

/**
 * An error from sqld, the SQLiteHub edge or the network.
 *
 * It extends PDOException so code that catches database errors keeps working;
 * Laravel wraps it in an Illuminate\Database\QueryException (or a
 * UniqueConstraintViolationException) with the SQL and bindings.
 */
class HranaException extends PDOException
{
    /**
     * @param  string|null  $errorCode  sqld's code (e.g. SQLITE_CONSTRAINT_UNIQUE) or the edge's (e.g. TOKEN_EXPIRED)
     * @param  int|null  $status  the HTTP status, for errors that were not a statement result
     */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        // PDOException codes are strings (SQLSTATE); use the server's code where there is one.
        $this->code = $errorCode ?? 'HY000';
        $this->errorInfo = ['HY000', $errorCode, $message];
    }

    /** An error result of one statement: "SQLITE_CONSTRAINT_UNIQUE: SQLite error: UNIQUE constraint failed: users.email". */
    public static function fromError(array $error): self
    {
        $code = isset($error['code']) ? (string) $error['code'] : null;
        $message = (string) ($error['message'] ?? 'Unknown error');

        return new self($code === null ? $message : "{$code}: {$message}", $code);
    }

    /** A non-2xx HTTP answer: the edge sends JSON {message, code}; sqld sends {error} or text. */
    public static function fromHttp(int $status, string $body): self
    {
        $json = json_decode($body, true);
        $code = is_array($json) && isset($json['code']) ? (string) $json['code'] : null;
        $message = is_array($json)
            ? (string) ($json['message'] ?? $json['error'] ?? $body)
            : (trim($body) === '' ? 'no response body' : mb_strimwidth(trim($body), 0, 300, '…'));

        return new self(($code === null ? '' : "{$code}: ")."{$message} (HTTP {$status})", $code, $status);
    }
}
