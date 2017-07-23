<?php

namespace Globalia\LaravelScoutMysql;

use Globalia\LaravelScoutMysql\Engines\MysqlEngine;
use Globalia\LaravelScoutMysql\Models\SearchIndex;
use Globalia\LaravelScoutMysql\Services\Search;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class ScoutMysqlServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->publishes([
            __DIR__.'/../../../config/scout_mysql.php' => config_path('scout_mysql.php'),
        ]);

        SearchIndex::unguard();

        $this->app->make(EngineManager::class)->extend('mysql', function () {
            return new MysqlEngine($this->app->make(Search::class));
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/default.php', 'scout_mysql');

        $this->app->singleton(Search::class);
    }
}
