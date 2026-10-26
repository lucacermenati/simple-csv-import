<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucaCermenati\SimpleCsvImport\SimpleCsvImportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SimpleCsvImportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('simple-csv-import.user_model', Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('iso', 2)->unique();
            $table->string('name');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('country_id')->constrained();
            $table->unsignedInteger('population')->nullable();
            $table->string('climate')->nullable();
            $table->unique(['name', 'country_id']);
        });
    }
}
