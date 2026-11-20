<?php

namespace App\Database;

use Illuminate\Database\SQLiteConnection as BaseConnection;

/**
 * Laravel's SQLite connection with a WAL-safe schema builder (ISSUE-42,
 * SQLiteSchemaBuilder). Registered for the "sqlite" driver in
 * App\Providers\AppServiceProvider.
 */
class SQLiteConnection extends BaseConnection
{
    public function getSchemaBuilder()
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new SQLiteSchemaBuilder($this);
    }
}
