<?php

namespace Scout\Solr\Engines;

use Exception;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Builder;
use Solarium\Client as SolariumClient;
use Solarium\QueryType\Select\Query\Query;
use Illuminate\Database\Eloquent\Collection;

class SolrEngine extends Engine
{


    /**
     * The Solarium client we are currently using
     *
     * @var \Solarium\Client
     */
    private $client;

    private $pkFieldName;

    /**
     * Constructor takes an initialized Solarium client as its only parameter
     */
    public function __construct(SolariumClient $client)
    {
        $this->client = $client;
        $this->pkFieldName = config('solr.pk_field_name', 'id');
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $update = $this->client->createUpdate();
        $documents = $models->map(function ($model, $key) use ($update) {
            $document = $update->createDocument();
            $attrs = $model->toSearchableArray();
            if (empty($attrs)) {
                return;
            }
            $attrs[$this->pkFieldName] = $model->getKey();
            // leave this extra here to allow for modification if needed
            foreach ($attrs as $key => $attr) {
                $document->$key = $attr;
            }
            return $document;
        });
        $update->addDocuments($documents->filter()->toArray());
        $update->addCommit();
        $this->client->update($update, $models->first()->searchableAs());
    }
    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $delete = $this->client->createUpdate();
        $model = $models->first();
        $endpoint = $model->searchableAs();
        $modelIds = $models->pluck($model->getKeyName());
        $delete->addDeleteQuery("{$this->pkFieldName}:(%1%)", [$modelIds->implode(' ')]);
        $delete->addCommit();
        $this->client->update($delete, $endpoint);
    }
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }
    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        //decrement the page number as we're actually dealing with an offset, not page number
        $page--;
        return $this->performSearch($builder, [
            'start' => $page * $perPage,
            'rows' => $perPage
        ]);
    }
    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        // how do we get the pk without a model?
        return collect($results)->pluck($this->pkFieldName)->values();
    }
    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if (count($results) === 0) {
            return Collection::make();
        }

        $ids = collect($results)
            ->filter(function ($result) use ($model) {
                return $result['model_table'] === $model->getTable();
            })
            ->pluck($this->pkFieldName)
            ->values()
            ->flatten();
        if ($ids->isEmpty()) {
            return Collection::make();
        }
        // TODO: Is there a better way to handle including faceting on a mapped result?
        if (($facetSet = $results->getFacetSet())->count() > 0) {
            $facets = $facetSet->getFacets();
        } else {
            $facets = [];
        }
        // TODO: (cont'd) Because attaching facets to every model feels kludgy
        // solution is to implement a custom collection class that can hold the facets
        $models = $model->whereIn($model->getKeyName(), $ids)
            ->orderByRaw(sprintf('FIELD(%s, %s)', $model->getKeyName(), $ids->implode(','), 'ASC'))
            ->get()
            ->map(function ($item) use ($facets) {
                $item->facets = $facets;
                return $item;
            });
        return $models;
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results->getNumFound();
    }

    /**
     * Actually perform the search, allows for options to be passed like pagination
     *
     * @param \Laravel\Scout\Builder $builder The query builder we were passed
     * @param array $options An array of options to use to do things like pagination, faceting?
     *
     * @return \Solarium\Core\Query\Result\Result The results of the query
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $endpoint = $builder->model->searchableAs();
        // build the query string for the q parameter
        if (is_array($builder->query)) {
            $queryString = collect($builder->query)
                ->map(function ($item, $key) {
                    if (is_array($item)) {
                        // there are multiple search queries for this term
                        $query = [];
                        foreach ($item as $query) {
                            $query[] = (is_numeric($key)) ? $query : "$key:$query";
                        }
                        return implode(' ', $query);
                    } else {
                        return (is_numeric($key)) ? $query : "$key:$query";
                    }
                })
                ->filter()
                ->implode(' ');
        } else {
            $queryString = $builder->query;
        }
        $query = $this->client->createSelect();
        if ($builder->isDismax()) {
            $dismax = $query->getDisMax();
            if (empty($queryString)) {
                $dismax->setQueryAlternative('*:*');
            }
        }
        $query->setQuery($queryString);

        // get the filter query
        // TODO: this is highly inefficient due to the unique key? Or will the query still be cached?
        $query->createFilterQuery(uniqid())->setQuery($this->filters($builder));

        // build any faceting
        $facetSet = $query->getFacetSet();
        if (!empty($builder->facetFields)) {
            foreach ($builder->facetFields as $field) {
                $facetSet->createFacetField("$field-field")->setField($field);
            }
        }
        if (!empty($builder->facetQueries)) {
            foreach ($builder->facetQueries as $field => $queries) {
                if (count($queries) > 1) {
                    $facet = $facetSet->createFacetMultiQuery("$field-multiquery");
                    foreach ($queries as $i => $query) {
                        $facet->createQuery("$field-multiquery-$i", $query);
                    }
                } else {
                    $facetSet->createQuery("$field-query")->setQuery("$field:{$queries[0]}");
                }
            }
        }
        if (!empty($builder->facetPivots)) {
            foreach ($builder->facetPivots as $fields) {
                $facetSet->createFacetPivot(implode('-', $fields))->addFields(implode(',', $fields));
            }
        }

        // Set the boost fields
        if (isset($dismax) && $builder->hasBoosts()) {
            $dismax->setQueryFields($builder->getBoosts());
        }

        // allow for pagination here
        if (array_key_exists('start', $options)) {
            $query->setStart($options['start']);
        }
        if (array_key_exists('rows', $options)) {
            $query->setRows($options['rows']);
        }

        return $this->client->select($query, $endpoint);
    }

    /**
     * Convert a set of wheres (key => value pairs) into a filter query for Solr
     *
     * @param \Laravel\Scout\Builder $builder The query builder that contains the wheres
     *
     * @return string[] The filter queries built from the builder wheres
     */
    protected function filters(Builder $builder)
    {
        $collection = collect($builder->wheres);
        $query = $collection->reduce([$this, 'buildFilter']);
        return $query;
    }

    /**
     * build an individual filter
     *
     * @param string $query The current query string to pass to fq
     * @param array  $item
     * @return string
     */
    public function buildFilter($query, $item)
    {
        if (!empty($query)) {
            // prepend the boolean from this query
            $query .= " {$item['boolean']} ";
        } else {
            //initialize the query string
            $query = '';
        }
        if ($item['field'] === 'nested') {
            // handle the nested queries recursively
            $query .= ' (' . collect($item['queries'])->reduce([$this, 'buildFilter']) . ') ';
        } elseif (is_array($item['query'])) {
            $query .= '(';
            $query .= collect($item['query'])
                ->map(function ($qString, $key) use ($item) {
                    return "{$item['field']}:$qString";
                })
                ->filter()
                ->implode(' OR ');
            $query .= ')';
        } else {
            $query .= "{$item['field']}:{$item['query']}";
        }
        return $query;
    }
}
