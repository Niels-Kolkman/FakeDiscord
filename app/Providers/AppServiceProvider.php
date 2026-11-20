<?php

namespace App\Providers;

use App\Support\AuditLog;
use App\Support\RateLimits;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimits::register();
    }
}
