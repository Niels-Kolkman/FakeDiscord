<?php

namespace App\Providers;

use App\Database\SQLiteConnection;
use App\Database\SQLiteConnector;
use App\Support\AuditLog;
use App\Support\RateLimits;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request, so AuditLog::withoutRecording() covers every
        // observer that fires inside it (ISSUE-30).
        $this->app->scoped(AuditLog::class);

        // SQLite tuning (ISSUE-42): BEGIN IMMEDIATE on PHP < 8.4 too, and a
        // migrate:fresh that works in WAL mode.
        $this->app->bind('db.connector.sqlite', SQLiteConnector::class);
        Connection::resolverFor('sqlite', fn ($pdo, string $database, string $prefix, array $config): SQLiteConnection => new SQLiteConnection($pdo, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimits::register();
    }
}
