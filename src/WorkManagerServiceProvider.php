<?php

namespace GregPriday\LaravelWorkManager;

use Illuminate\Support\ServiceProvider;

class WorkManagerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/work-manager.php', 'work-manager'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/work-manager.php' => config_path('work-manager.php'),
            ], 'work-manager-config');
        }
    }
}
