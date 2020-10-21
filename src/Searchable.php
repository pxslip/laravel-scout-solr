<?php

namespace Scout\Solr;


use Laravel\Scout\EngineManager;
trait Searchable
{
    use \Laravel\Scout\Searchable;

    /**
     * Returns the name of the index/core where this data is searchable.
     *
     * @return string the name of the core to search
     */
    public function searchableAs()
    {
        return config('solr.core');
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  Closure  $callback
     * @return \Scout\Solr\Builder
     */
    public static function search($query, $callback = null)
    {
        return new Builder(new static(), $query, $callback);
    }

    public static function escapeSolrQueryAsTerm($query): string
    {
        return app(EngineManager::class)->engine()->escapeQueryAsTerm($query);
    }

    public static function escapeSolrQueryAsPhrase($query): string
    {
        return app(EngineManager::class)->engine()->escapeQueryAsPhrase($query);
    }
}
