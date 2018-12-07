<?php

namespace Scout\Solr\Engines;

use Laravel\Scout\Builder;
use Scout\Solr\Searchable;
use Laravel\Scout\Engines\Engine;
use Solarium\Client as SolariumClient;
use Illuminate\Database\Eloquent\Collection;

class SolrEngine extends Engine
{
    /**
     * The Solarium client we are currently using.
     *
     * @var \Solarium\Client
     */
    private $client;

    /**
     * Constructor takes an initialized Solarium client as its only parameter.
     * @param SolariumClient $client The Solarium client to use
     */
    public function __construct(SolariumClient $client)
    {
        $this->client = $client;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $model = $models->first();
        if (! $model->shouldBeSearchable()) {
            return;
        }

        $update = $this->client->createUpdate();
        $documents = $models->map(function ($model) use ($update) {
            /** @var \Solarium\QueryType\Update\Query\Document\Document */
            $document = $update->createDocument();
            /** @var Searchable $model */
            $attrs = $model->toSearchableArray();
            if (empty($attrs)) {
                return false;
            }
            // introduce functionality for solr meta data
            if (array_key_exists('meta', $attrs)) {
                $meta = $attrs['meta'];
                // check if their are boosts to apply to the document
                if (array_key_exists('boosts', $meta)) {
                    $boosts = $meta['boosts'];
                    if (array_key_exists('document', $boosts)) {
                        if (is_float($boosts['document'])) {
                            $document->setBoost($boosts['document']);
                        }
                        unset($boosts['document']);
                    }
                    foreach ($boosts as $field => $boost) {
                        if (is_float($boost)) {
                            $document->setFieldBoost($field, $boost);
                        }
                    }
                }
                unset($attrs['meta']);
            }
            // leave this extra here to allow for modification if needed
            foreach ($attrs as $key => $attr) {
                $document->$key = $attr;
            }

            return $document;
        })->filter();
        $update->addDocuments($documents->toArray());
        $update->addCommit();
        $this->client->update($update, $model->searchableAs());
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $model = $models->first();
        $delete = $this->client->createUpdate();
        $endpoint = $model->searchableAs();
        $ids = $models->map(function (Searchable $model) {
            return $model->getScoutKey();
        });
        $delete->addDeleteByIds($ids->all());
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

        $builder->take($perPage);
        return $this->performSearch($builder, [
            'start' => $page * $perPage,
        ]);
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  \Solarium\QueryType\Select\Result\Result $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        // how do we get the pk without a model?
        return collect($results)->pluck('id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Solarium\QueryType\Select\Result\Result  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results) === 0) {
            return Collection::make();
        }

        $ids = collect($results)
            ->pluck($model->getKeyName())
            ->values()
            ->all();

        // TODO: Is there a better way to handle including faceting on a mapped result?
        $facetSet = $results->getFacetSet();
        if ($facetSet->count() > 0) {
            $facets = $facetSet->getFacets();
        } else {
            $facets = [];
        }
        // TODO: (cont'd) Because attaching facets to every model feels kludgy
        // solution is to implement a custom collection class that can hold the facets
        $models = $model->whereIn($model->getKeyName(), $ids)
            ->orderByRaw(sprintf('FIELD(%s, %s)', $model->getKeyName(), implode(',', $ids), 'ASC'))
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
     * Actually perform the search, allows for options to be passed like pagination.
     *
     * @param \Scout\Solr\Builder $builder The query builder we were passed
     * @param array $options An array of options to use to do things like pagination, faceting?
     *
     * @return \Solarium\Core\Query\Result\Result The results of the query
     */
    protected function performSearch(\Scout\Solr\Builder $builder, array $options = [])
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
                        return (is_numeric($key)) ? $item : "$key:$item";
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
        $filterQuery = $this->filters($builder);
        $query->createFilterQuery(md5($filterQuery))->setQuery($filterQuery);

        // build any faceting
        $facetSet = $query->getFacetSet();
        $facetSet->setOptions($builder->facetOptions);
        if (! empty($builder->facetFields)) {
            foreach ($builder->facetFields as $field) {
                $facetSet->createFacetField("$field-field")->setField($field);
            }
        }
        if (! empty($builder->facetQueries)) {
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
        if (! empty($builder->facetPivots)) {
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
        if ($builder->limit) {
            $query->setRows($builder->limit);
        }

        return $this->client->select($query, $endpoint);
    }

    /**
     * Convert a set of wheres (key => value pairs) into a filter query for Solr.
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
     * build an individual filter.
     *
     * @param string $query The current query string to pass to fq
     * @param array  $item
     * @return string
     */
    public function buildFilter($query, $item)
    {
        if (! empty($query)) {
            // prepend the boolean from this query
            $query .= " {$item['boolean']} ";
        } else {
            //initialize the query string
            $query = '';
        }
        if ($item['field'] === 'nested') {
            // handle the nested queries recursively
            $query .= ' ('.collect($item['queries'])->reduce([$this, 'buildFilter']).') ';
        } elseif (is_array($item['query'])) {
            $query .= '(';
            $query .= collect($item['query'])
                ->map(function ($qString) use ($item) {
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
