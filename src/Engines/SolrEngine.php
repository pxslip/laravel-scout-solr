<?php

namespace Scout\Solr\Engines;

use Scout\Solr\Builder;
use Scout\Solr\Searchable;
use Laravel\Scout\Engines\Engine;
use Solarium\Client as SolariumClient;
use Laravel\Scout\Builder as BaseBuilder;
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
     * Is searching/updating disabled for this instance.
     *
     * @var bool
     */
    private $enabled = true;

    /**
     * Constructor takes an initialized Solarium client as its only parameter.
     * @param SolariumClient $client The Solarium client to use
     */
    public function __construct(SolariumClient $client)
    {
        $this->client = $client;
        $this->enabled = config('solr.enabled', true);
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if ($this->enabled) {
            $model = $models->first();
            $update = $this->client->createUpdate();
            $documents = $models->map(
                /**
                 * @return false|\Solarium\QueryType\Update\Query\Document\Document
                 */
                function ($model) use ($update) {
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
                    $class = is_object($model) ? get_class($model) : false;
                    if ($class) {
                        $document->_modelClass = $class;
                    }

                    return $document;
                }
            )->filter();
            $update->addDocuments($documents->filter()->toArray());
            $update->addCommit();
            $this->client->update($update, $model->searchableAs());
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($this->enabled) {
            $model = $models->first();
            $delete = $this->client->createUpdate();
            $endpoint = $model->searchableAs();
            $ids = $models->map(function ($model) {
                return $model->getScoutKey();
            });
            $delete->addDeleteByIds($ids->all());
            $delete->addCommit();
            $this->client->update($delete, $endpoint);
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param BaseBuilder $builder
     *
     * @return mixed
     */
    public function search(BaseBuilder $builder)
    {
        if (! $this->enabled) {
            return Collection::make();
        }

        return $this->performSearch($builder);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param BaseBuilder $builder
     * @param int  $perPage
     * @param int  $page
     *
     * @return mixed
     */
    public function paginate(BaseBuilder $builder, $perPage, $page)
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
     * @param BaseBuilder $builder
     * @param \Solarium\QueryType\Select\Result\Result  $results
     * @param \Illuminate\Database\Eloquent\Model  $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(BaseBuilder $builder, $results, $model)
    {
        if (count($results) === 0) {
            return Collection::make();
        }

        $ids = collect($results)
            ->pluck($model->getKeyName())
            ->values();

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
            ->orderByRaw($this->orderQuery($model, $ids))
            ->get()
            ->map(function ($item) use ($facets) {
                $item->facets = $facets;

                return $item;
            });

        return $models;
    }

    /**
     * Return the appropriate sorting(ranking) query for the SQL driver.
     *
     * @param \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Eloquent\Collection  $ids
     *
     * @return string The query that will be used for the ordering (ranking)
     */
    private function orderQuery($model, $ids)
    {
        $driver = $model->getConnection()->getDriverName();
        $model_key = $model->getKeyName();
        $query = '';

        if ($driver == 'pgsql') {
            foreach ($ids as $id) {
                $query .= sprintf('%s=%s desc, ', $model_key, $id);
            }
            $query = rtrim($query, ', ');
        } elseif ($driver == 'mysql') {
            $id_list = $ids->implode(',');
            $query = sprintf('FIELD(%s, %s)', $model_key, $id_list, 'ASC');
        } else {
            throw new \Exception('The SQL driver is not supported.');
        }

        return $query;
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
     * @param Builder|BaseBuilder $builder The query builder we were passed
     * @param array $options An array of options to use to do things like pagination, faceting?
     *
     * @return \Solarium\Core\Query\Result\Result The results of the query
     */
    protected function performSearch($builder, array $options = [])
    {
        if (! ($builder instanceof Builder)) {
            throw new \Exception('Your model must use the Scout\\Solr\\Searchable trait in place of Laravel\\Scout\\Searchable');
        }
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
        } elseif ($builder->isEDismax()) {
            $dismax = $query->getEDisMax();
        }

        if (isset($dismax) && empty($queryString)) {
            $dismax->setQueryAlternative('*:*');
        }
        $query->setQuery($queryString);

        // get the filter query
        // TODO: this is highly inefficient due to the unique key? Or will the query still be cached?
        $filterQuery = $this->filters($builder);
        $query->createFilterQuery(md5($filterQuery['query']))->setQuery($filterQuery['query'], $filterQuery['items']);

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
     * @param Builder $builder
     *
     * @return string[] The filter queries built from the builder wheres
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->reduce([$this, 'buildFilter']);
    }

    /**
     * build an individual filter.
     *
     * @param array  $carry The current query string to pass to fq
     * @param array  $data
     * @return array
     */
    public function buildFilter(array $carry = null, array $data): array
    {
        $carryItems = $carry['items'] ?? [];
        $start = $carry['placeholderStart'] ?? 0;

        if ($data['field'] === 'nested') {
            // handle the nested queries recursively
            $nested = collect($data['queries'])->reduce([$this, 'buildFilter'], ['placeholderStart' => $start]);
            $query = $nested['query'];
            $items = $nested['items'];
            $start = $nested['placeholderStart'];
        } else {
            $field = $data['field'];
            $mode = $data['mode'];
            $items = is_array($data['query']) ? $data['query'] : [$data['query']];
            $end = $start + count($items);
            $query = collect(range($start + 1, $end))
                ->map(function (int $index) use ($field, $mode): string {
                    return "$field:%$mode $index%";
                })->implode(' OR ');
            $start = $end;
        }

        $carryQuery = $carry['query'] ?? '';

        return [
            'query' => empty($carryQuery) ?
                sprintf('(%s)', $query) : sprintf('%s %s (%s)', $carryQuery, $data['boolean'], $query),
            'items' => array_merge($carryItems, $items),
            'placeholderStart' => $start,
        ];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $class = is_object($model) ? get_class($model) : false;
        if ($class) {
            $update = $this->client->createUpdate();
            $update->addDeleteQuery("_modelClass:$class");
            $update->addCommit();
            $this->client->update($update);
        }
    }
}
