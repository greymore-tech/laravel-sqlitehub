# Changelog

## 0.1.1 (25 Nov 2026)

- `transaction_mode` (DEFERRED / IMMEDIATE / EXCLUSIVE) is honoured on every PHP version; Laravel itself sends a plain BEGIN before PHP 8.4.
- Tests pass on PHP 8.2 and 8.3 and on Laravel 12 (verified against sqld); 0.1.0's suite assumed PHP 8.4 and Laravel 13.

## 0.1.0 (25 Nov 2026)

First release. Laravel database driver for SQLiteHub over HTTPS (Hrana 3 over HTTP), pure PHP: query builder, Eloquent, migrations and Schema builder, transactions with savepoints, unique-constraint exceptions, read-only tokens. Tested against sqld v0.24.33.
