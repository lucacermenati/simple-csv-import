<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use Illuminate\Support\ServiceProvider;
use LucaCermenati\SimpleCsvImport\Console\DryRunCommand;

class SimpleCsvImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/simple-csv-import.php', 'simple-csv-import');

        // One set of lookups per request or queue job.
        $this->app->scoped(CodeLookups::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/simple-csv-import.php' => config_path('simple-csv-import.php'),
        ], 'simple-csv-import-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'simple-csv-import-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DryRunCommand::class]);
        }
    }
}
