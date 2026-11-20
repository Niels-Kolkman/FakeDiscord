<?php

namespace App\Database;

use Illuminate\Database\Connectors\SQLiteConnector as BaseConnector;
use PDO;

/**
 * Laravel's SQLite connector, plus transaction_mode on PHP before 8.4
 * (ISSUE-42): with IMMEDIATE or EXCLUSIVE configured, the connection is an
 * ImmediateTransactionPdo, so DB::transaction() takes the write lock up
 * front on every PHP version. Bound as "db.connector.sqlite" in
 * App\Providers\AppServiceProvider.
 */
class SQLiteConnector extends BaseConnector
{
    /** IMMEDIATE or EXCLUSIVE for the connection being made, else null. */
    private ?string $transactionMode = null;

    /**
     * @param  array<string, mixed>  $config
     * @return PDO
     */
    public function connect(array $config)
    {
        $mode = strtoupper((string) ($config['transaction_mode'] ?? ''));
        $this->transactionMode = in_array($mode, ['IMMEDIATE', 'EXCLUSIVE'], true) ? $mode : null;

        return parent::connect($config);
    }

    /**
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  array<int, mixed>  $options
     * @return PDO
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        if ($this->transactionMode === null || version_compare(PHP_VERSION, '8.4.0', '>=')) {
            return parent::createPdoConnection($dsn, $username, $password, $options);
        }

        $pdo = new ImmediateTransactionPdo($dsn, $username, $password, $options);
        $pdo->beginStatement = "BEGIN {$this->transactionMode}";

        return $pdo;
    }
}
