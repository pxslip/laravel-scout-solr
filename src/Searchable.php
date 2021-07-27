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

    /**
     * Give the model access to the solr query escaper for terms.
     *
     * @param string $query
     * @return string
     */
    public static function escapeSolrQueryAsTerm(string $query): string
    {
        return app(EngineManager::class)->engine()->escapeQueryAsTerm($query);
    }

    /**
     * Give the model access to the solr query escaper for phrases.
     *
     * @param string $query
     * @return string
     */
    public static function escapeSolrQueryAsPhrase(string $query): string
    {
        return app(EngineManager::class)->engine()->escapeQueryAsPhrase($query);
    }

    /**
     * Override the newCollection method on Model to use the SolrCollection class if no collection is set by the Model.
     *
     * @param array $models
     * @return SolrCollection
     */
    public function newCollection(array $models = [])
    {
        return new SolrCollection($models);
    }
}
