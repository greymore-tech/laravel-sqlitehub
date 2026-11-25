<?php

namespace SQLiteHub\Laravel\Hrana;

use InvalidArgumentException;
use PDO;
use Stringable;

/**
 * Converts between PHP values and Hrana values.
 *
 * Hrana sends integers as strings (64-bit safe in JSON), floats as numbers,
 * text as strings and blobs as base64.
 */
final class Values
{
    /** @return array<string, mixed> */
    public static function encode(mixed $value, int $type = PDO::PARAM_STR): array
    {
        if (is_resource($value)) {
            return ['type' => 'blob', 'base64' => base64_encode((string) stream_get_contents($value))];
        }

        return match (true) {
            $value === null => ['type' => 'null'],
            is_bool($value) => ['type' => 'integer', 'value' => $value ? '1' : '0'],
            is_int($value) => ['type' => 'integer', 'value' => (string) $value],
            is_float($value) => ['type' => 'float', 'value' => $value],
            is_string($value) => $type === PDO::PARAM_LOB || ! mb_check_encoding($value, 'UTF-8')
                ? ['type' => 'blob', 'base64' => base64_encode($value)]
                : ['type' => 'text', 'value' => $value],
            $value instanceof Stringable => ['type' => 'text', 'value' => (string) $value],
            default => throw new InvalidArgumentException('Cannot bind a value of type '.get_debug_type($value).' to a SQLiteHub query.'),
        };
    }

    /** @param  array<string, mixed>  $value */
    public static function decode(array $value): int|float|string|null
    {
        return match ($value['type'] ?? null) {
            'integer' => (int) $value['value'],
            'float' => (float) $value['value'],
            'text' => (string) $value['value'],
            'blob' => (string) base64_decode((string) $value['base64'], true),
            default => null,
        };
    }
}
