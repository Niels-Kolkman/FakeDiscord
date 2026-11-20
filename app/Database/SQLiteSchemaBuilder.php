<?php

namespace App\Database;

use Illuminate\Database\Schema\SQLiteBuilder;

/**
 * `migrate:fresh` and `db:wipe` empty the SQLite file. In WAL mode
 * (ISSUE-42) the latest changes are still in the "-wal" file next to it,
 * and SQLite would bring them back, so the "fresh" database keeps old
 * rows. Leaving WAL mode first writes them into the file and removes the
 * "-wal" file; the next connection turns WAL back on (config/database.php).
 */
class SQLiteSchemaBuilder extends SQLiteBuilder
{
    /**
     * @param  string|null  $path
     * @return void
     */
    public function refreshDatabaseFile($path = null)
    {
        if (strtolower((string) $this->pragma('journal_mode')) === 'wal') {
            $this->connection->scalar('pragma journal_mode = delete');
        }

        parent::refreshDatabaseFile($path);
    }
}
