<?php

namespace App\Scout;

trait Searchable
{
    use \Laravel\Scout\Searchable;

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
