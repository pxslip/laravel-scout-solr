<?php

namespace Scout\Solr;

use Closure;
use Laravel\Scout\Builder as ScoutBuilder;
use Scout\Solr\Engines\SolrEngine;

/**
 * Extend the Scout Builder class to allow for more complicated queries against Solr.
 */
class Builder extends ScoutBuilder
{
    /**
     * Array of options for the facetSet. <option> => <value> format.
     *
     * @var string[]
     */
    public $facetOptions = [];

    /**
     * Array of facet fields to facet on.
     *
     * @var string[]
     */
    public $facetFields = [];

    /**
     * Array of facet queries mapped by field.
     *
     * @var array|string[string]
     */
    public $facetQueries = [];

    /**
     * Array of array of fields to do a facet pivot.
     *
     * @var [][]
     */
    public $facetPivots = [];

    /**
     * Array of field => boost values to add to the query.
     *
     * @var array
     */
    private $boostFields = [];

    /**
     * Gets set when either the useDismax() method is called, or if one of the boosting methods is called.
     *
     * @var bool
     */
    private $useDismax = false;

    /**
     * Gets set when either the useEDismax() method is called, or if the query contains a wildcard.
     *
     * @var bool
     */
    private $useExtendedDismax = false;

    /**
     * The offset to start the search at.
     *
     * @var int
     */
    private $start = null;

    /**
     * Determine whether we want the spellcheck component to run
     *
     * @var boolean
     */
    private $useSpellcheck = false;

    /**
     * If the search returns a collation for spellcheck, automatically re-run it to extend the results
     *
     * @var boolean
     */
    private $doAutoSpellcheckSearch = false;

    /**
     * Options for the spellcheck component
     *
     * @var array
     */
    private $spellcheckOptions = [];

    /**
     * Add a filter query, uses the solarium placeholder syntax
     * 
     * @see https://solarium.readthedocs.io/en/stable/queries/query-helper/placeholders/
     *
     * @param string|Closure $query The query string
     * @param array $bindings Any bindings to placeholders
     * @param string $boolean 'AND' or 'OR'
     *
     * @return self $this to allow for fluent queries
     */
    public function where($query, $bindings = [], $boolean = 'AND')
    {
        if ($query instanceof Closure) {
            // let's make it possible to do fluent nested queries
            call_user_func($query, $query = $this->builderForNested());
            $this->wheres[] = [
                'type' => SolrEngine::NESTED_QUERY,
                'queries' => $query->wheres,
                'boolean' => $boolean,
            ];
        } else {
            $this->wheres[] = [
                'type' => SolrEngine::SIMPLE_QUERY,
                'query' => $query,
                'bindings' => $bindings,
                'boolean' => $boolean,
            ];
        }

        return $this;
    }

    /**
     * Add a filter query separated by OR. Uses the solarium placeholder syntax
     * 
     * @see https://solarium.readthedocs.io/en/stable/queries/query-helper/placeholders/
     *
     * @param string|Closure $query the name of the field to filter against
     * @param array $bindings The value bindings to use
     *
     * @return self to allow for fluent queries
     */
    public function orWhere($query, $bindings = [])
    {
        return $this->where($query, $bindings, 'OR');
    }

    /**
     * Add the ability to easily set a range query.
     *
     * @param string $field The name of the field to filter against
     * @param string $low   The low value of the range
     * @param string $high  The high value of the range
     * @param string $mode The placeholder syntax mode
     * @param string $boolean 'AND' or 'OR'
     *
     * @return self          $this to allow for fluent queries
     */
    public function whereRange(string $field, string $low, string $high, string $mode = 'L', string $boolean = 'AND')
    {
        $query = "{$field}:[%{$mode}1% TO %{$mode}2%]";

        return $this->where($query, [$low, $high], $boolean);
    }

    /**
     * Add a facet to this search on the given field.
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
     * Add a facet query.
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
                $query,
            ];
        }

        return $this;
    }

    /**
     * Add a facet pivot query.
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
     * Add an option to apply on the Solarium FacetSet
     * See https://github.com/solariumphp/solarium/blob/master/src/Component/FacetSet.php for possible options.
     *
     * @param  string $option The option name
     * @param  mixed  $value  The option value
     *
     * @return self  To allow for fluent chaining
     */
    public function setFacetOption($option, $value)
    {
        $this->facetOptions[$option] = $value;

        return $this;
    }

    /**
     * Get a new builder that can be used to build a nested query.
     *
     * @return static
     */
    private function builderForNested()
    {
        return new self($this->model, $this->query, null);
    }

    /**
     * Add a boost to the query.
     *
     * @param string $field
     * @param string|int $boost
     * @return $this
     */
    public function boostField($field, $boost)
    {
        $this->selectQueryParser();
        $this->boostFields[$field] = $boost;

        return $this;
    }

    /**
     * Set `$useDismax` or `$useExtendedDismax` to `true` based on the query.
     */
    private function selectQueryParser()
    {
        if (strpos($this->query, '*') !== false) {
            $this->useEDismax();
        } else {
            $this->useDismax();
        }
    }

    /**
     * @return array
     */
    public function getBoostsArray()
    {
        return $this->getBoostsCollection()
            ->toArray();
    }

    /**
     * @return string
     */
    public function getBoosts()
    {
        return $this->getBoostsCollection()
            ->implode(' ');
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getBoostsCollection()
    {
        return collect($this->boostFields)
            ->map(function ($boost, $field): string {
                return "$field^$boost";
            });
    }

    public function hasBoosts(): bool
    {
        return !empty($this->boostFields);
    }

    /**
     * Inform the builder that we want to use dismax when building the query.
     *
     * @return $this
     */
    public function useDismax()
    {
        $this->useDismax = true;

        return $this;
    }

    /**
     * Inform the builder that we want to use the *extended* dismax parser when building the query.
     *
     * @return $this
     */
    public function useEDismax()
    {
        $this->useExtendedDismax = true;

        return $this;
    }

    /**
     * @return bool
     */
    public function isEDismax()
    {
        return $this->useExtendedDismax;
    }

    /**
     * @return bool
     */
    public function isDismax()
    {
        return $this->useDismax;
    }

    /**
     * Set the start offset for this query.
     *
     * @param int $value
     * @return self
     */
    public function setStart(int $value): self
    {
        $this->start = $value;

        return $this;
    }

    public function getStart(): ?int
    {
        return $this->start;
    }

    /**
     * Enable the spellcheck component
     *
     * @param array $options Spellcheck options
     * @return self
     */
    public function spellcheck($options = []): self
    {
        $this->useSpellcheck = true;
        $this->spellcheckOptions = $options;
        return $this;
    }

    /**
     * Determine whether this search wants the spellcheck component
     *
     * @return boolean
     */
    public function getUseSpellcheck(): bool
    {
        return $this->useSpellcheck;
    }

    /**
     * If enabled will automatically re-search the index for any collated searches returned by the spellcheck component
     *
     * @return self
     */
    public function autoSpellcheckSearch(): self
    {
        $this->doAutoSpellcheckSearch = true;
        // force collation to make sure we get alternate results back
        $this->spellcheckOptions['collate'] = true;
        return $this;
    }

    /**
     * Define if we want to perform an auto re-search
     *
     * @return boolean
     */
    public function getDoAutoSpellcheckSearch(): bool
    {
        return $this->doAutoSpellcheckSearch;
    }
}
