<?php

namespace App\Scout;

use Laravel\Scout\EngineManager;
use Illuminate\Support\ServiceProvider;
use App\Scout\Engines\SolrEngine;

class SolrScoutServiceProvider extends ServiceProvider
{
    
    public function boot()
    {
        // extend the Scout engine manager
        resolve(EngineManager::class)->extend('solr', function () {
            return resolve(SolrEngine::class);
        });
    }

    public function register()
    {
        // bind the solarium client as a singleton so we can DI
        $this->app->singleton(\Solarium\Client::class, function ($app) {
            return new \Solarium\Client([
                'endpoint' => config('solr.endpoints')
            ]);
        });

        // publish the solr.php config file when the user publishes this provider
        $this->publishes([
            __DIR__.'/../config/solr.php' => $this->app['path.config'].DIRECTORY_SEPARATOR.'solr.php'
        ]);
    }
}
