<?php

namespace App\Providers;

use App\Database\SQLiteConnection;
use App\Database\SQLiteConnector;
use App\Models\ChannelPermissionOverride;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Server;
use App\Models\ServerMember;
use App\Models\User;
use App\Observers\ForgetsServerCache;
use App\Support\AuditLog;
use App\Support\RateLimits;
use App\Support\ServerCache;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request, so AuditLog::withoutRecording() covers every
        // observer that fires inside it.
        $this->app->scoped(AuditLog::class);

        // Per request / queue job: it keeps values in memory (ISSUE-42).
        $this->app->scoped(ServerCache::class, fn (): ServerCache => new ServerCache(Cache::store()));

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

        // Permissions and member lists are cached per server (ISSUE-42).
        foreach ([Role::class, RoleUser::class, ServerMember::class, ChannelPermissionOverride::class, Server::class, User::class] as $model) {
            $model::observe(ForgetsServerCache::class);
        }
        // A migrated (or rebuilt) database must not meet old entries.
        Event::listen([MigrationsEnded::class, DatabaseRefreshed::class], fn () => $this->app->make(ServerCache::class)->flush());

        // N+1 guard (ISSUE-42): outside production, lazy-loading a relation
        // on a model that came with others (an N+1 in a loop) throws, so
        // tests and local development catch it. Production only logs it.
        Model::preventLazyLoading();
        if ($this->app->isProduction()) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('Lazy loading ['.$relation.'] on ['.$model::class.'] (N+1).');
            });
        }
    }
}
