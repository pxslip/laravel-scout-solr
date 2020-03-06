<?php

namespace Scout\Solr;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Scout\Solr\Engines\SolrEngine;

class ScoutSolrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // extend the Scout engine manager
        resolve(EngineManager::class)->extend('solr', function () {
            return resolve(SolrEngine::class);
        });
        // publish the solr.php config file when the user publishes this provider
        $this->publishes([
            __DIR__.'/../config/scout-solr.php' => config_path('scout-solr.php'),
        ]);
    }

    public function register(): void
    {
        // bind the solarium client as a singleton so we can DI
        $this->app->singleton(\Solarium\Client::class, function ($app) {
            return new \Solarium\Client([
                'endpoint' => config('solr.endpoints'),
            ]);
        });
        $this->mergeConfigFrom(__DIR__.'/../config/scout-solr.php', 'scout-solr');
    }
}
