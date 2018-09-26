<?php

namespace App\Scout;

use Laravel\Scout\Builder as ScoutBuilder;
use Closure;

/**
 * Extend the Scout Builder class to allow for more complicated queries against Solr
 */
class Builder extends ScoutBuilder
{
    
    /**
     * Array of facet fields to facet on
     *
     * @var string[]
     */
    public $facetFields = [];

    /**
     * Array of facet queries mapped by field
     *
     * @var string[string]
     */
    public $facetQueries = [];

    /**
     * Array of array of fields to do a facet pivot
     *
     * @var [][]
     */
    public $facetPivots = [];

    /**
     * Array of field => boost values to add to the query
     *
     * @var array
     */
    private $boostFields = [];

    /**
     * Gets set when either the useDismax() method is called, or if one of the boosting methods is called
     *
     * @var boolean
     */
    private $useDismax = false;
    
    /**
     * Add a simple key=value filter
     *
     * @param string|Closure|array $field The field to compare against
     * @param                      $value The value to compare to
     *
     * @return self   $this to allow for fluent queries
     */
    public function where($field, $query = null, $boolean = 'AND')
    {
        if (is_array($field)) {
            // we're tring to add a nested query via array
            $this->wheres[] = [
                'field' => 'nested',
                'queries' => $field,
                'boolean' => $boolean
            ];
            return $this;
        }

        if ($field instanceof Closure) {
            // let's make it possible to do fluent nested queries
            call_user_func($field, $query = $this->builderForNested());
            $this->wheres[] = [
                'field' => 'nested',
                'queries' => $query->wheres,
                'boolean' => $boolean
            ];
            return $this;
        }
        $this->wheres[] = [
            'field' => $field,
            'query' => $query,
            'boolean' => $boolean
        ];
        return $this;
    }

    /**
     * Add a filter query separated by OR
     *
     * @param string|Closure|array $field the name of the field to filter against
     * @param string               $query the query to filter using
     *
     * @return self   to allow for fluent queries
     */
    public function orWhere($field, $query = null)
    {
        return $this->where($field, $query, 'OR');
    }
    
    /**
     * Add the ability to easily set a range query
     *
     * @param string $field The name of the field to filter against
     * @param string $low   The low value of the range
     * @param string $high  The high value of the range
     *
     * @return self          $this to allow for fluent queries
     */
    public function whereRange(string $field, string $low, string $high, $boolean = 'AND')
    {
        $query = "[$low TO $high]";
        return $this->where($field, $query, $boolean);
    }
    /**
     * Add a facet to this search on the given field
     *
     * @param  string $field The field to include for faceting
     *
     * @return self   For fluent chaining
     */
    public function facetField(string $field)
    {
        $this->facetFields[] = $field;
        return $this;
    }

    /**
     * Add a facet query
     *
     * @param  string $field the field to facet on
     * @param  string $query the query to work with
     *
     * @return self   Allow for fluent chaining
     */
    public function facetQuery(string $field, string $query)
    {
        if (array_key_exists($field, $this->facetQueries)) {
            // we already have a facet query for this field, add another
            $this->facetQueries[$field][] = $query;
        } else {
            $this->facetQueries[$field] = [
                $query
            ];
        }
        return $this;
    }

    /**
     * Add a facet pivot query
     *
     * @param  array $fields the fields to pivot on
     *
     * @return self  To allow for fluent chaining
     */
    public function facetPivot(array $fields)
    {
        $this->facetPivots[] = $fields;
        return $this;
    }

    /**
     * Get a new builder that can be used to build a nested query
     *
     * @return void
     */
    private function builderForNested()
    {
        return new Builder($this->model, $this->query, null);
    }

    /**
     * Add a boost to the query
     *
     * @param string $field
     * @param string|int $boost
     * @return $this
     */
    public function boostField($field, $boost)
    {
        $this->useDismax = true;
        $this->boostFields[$field] = $boost;
        return $this;
    }

    public function getBoostsArray()
    {
        return $this->getBoostsCollection()
            ->toArray();
    }

    public function getBoosts()
    {
        return $this->getBoostsCollection()
            ->implode(' ');
    }

    public function getBoostsCollection()
    {
        return collect($this->boostFields)
            ->map(function ($boost, $field) {
                return "$field^$boost";
            });
    }

    public function hasBoosts()
    {
        return !empty($this->boostFields);
    }

    /**
     * Inform the builder that we want to use dismax when building the query
     *
     * @return $this
     */
    public function useDismax()
    {
        $this->useDismax = true;
        return $this;
    }

    public function isDismax()
    {
        return $this->useDismax;
    }
}
