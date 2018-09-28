<?php

namespace App\Scout;

trait Searchable
{
    use \Laravel\Scout\Searchable;

    /**
     * Returns the name of the index/core where this data is searchable
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
     * @return \App\Scout\Builder
     */
    public static function search($query, $callback = null)
    {
        return new Builder(new static, $query, $callback);
    }
}
