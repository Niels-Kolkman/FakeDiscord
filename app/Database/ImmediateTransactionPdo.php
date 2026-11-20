<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * A SQLite PDO whose transactions start with BEGIN IMMEDIATE (ISSUE-42),
 * for PHP before 8.4.
 *
 * PDO::beginTransaction() always sends a plain BEGIN (DEFERRED) on PHP
 * < 8.4, which ignores the connection's transaction_mode. A deferred
 * transaction that reads before it writes (the database queue reserving a
 * job, an insert whose observer looks something up first) cannot wait for
 * the write lock in WAL mode: when another process wrote in the meantime,
 * SQLite returns "database is locked" straight away, busy_timeout or not.
 * BEGIN IMMEDIATE takes the write lock up front, where busy_timeout does
 * apply, so writers queue up instead of failing.
 *
 * PHP 8.4 sends BEGIN {transaction_mode} itself
 * (Illuminate\Database\SQLiteConnection), so App\Database\SQLiteConnector
 * only uses this class on older versions.
 */
class ImmediateTransactionPdo extends PDO
{
    public string $beginStatement = 'BEGIN IMMEDIATE';

    private bool $inTransaction = false;

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new PDOException('There is already an active transaction');
        }

        $this->exec($this->beginStatement);
        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        if (! $this->inTransaction) {
            throw new PDOException('There is no active transaction');
        }

        $this->exec('COMMIT');
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (! $this->inTransaction) {
            throw new PDOException('There is no active transaction');
        }

        $this->inTransaction = false;

        try {
            $this->exec('ROLLBACK');
        } catch (PDOException $e) {
            // SQLite already rolled back by itself (some errors do that):
            // there is nothing left to undo.
            if (! str_contains($e->getMessage(), 'no transaction is active')) {
                throw $e;
            }
        }

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
