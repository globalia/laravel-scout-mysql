<?php

namespace Globalia\LaravelScoutMysql\Engines;

use Globalia\LaravelScoutMysql\Models\SearchExpression;
use Globalia\LaravelScoutMysql\Services\Indexer;
use Globalia\LaravelScoutMysql\Services\Searcher;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

class MysqlEngine extends Engine
{
    /**
     * The Indexer Service.
     *
     * @var Indexer
     */
    private $indexer;

    /**
     * The Searcher Service.
     *
     * @var Indexer
     */
    private $searcher;

    /**
     * Create a new engine instance.
     *
     * @param  Indexer  $indexer
     */
    public function __construct(Indexer $indexer, Searcher $searcher)
    {
        $this->indexer = $indexer;

        $this->searcher = $searcher;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     */
    public function update($models)
    {
        $this->indexer->indexModels($models);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     */
    public function delete($models)
    {
        $this->indexer->deleteModels($models);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'orders' => $this->orders($builder),
            'boosts' => $this->boosts($builder),
            'offset' => 0,
            'limit' => $builder->limit,
        ]);
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
        return $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'orders' => $this->orders($builder),
            'boosts' => $this->boosts($builder),
            'offset' => (($page * $perPage) - $perPage),
            'limit' => $perPage,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $options['index'] = $builder->index ?: $builder->model->searchableAs();

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->searcher,
                $builder->query,
                $options
            );
        }

        return $this->searcher->search($builder->query, $options);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function($value, $expression) {
            $searchExpression = new SearchExpression($expression, $value);

            return $searchExpression->isFilter() ? $searchExpression->get() : null;
        })->filter()->all();
    }

    protected function orders(Builder $builder)
    {
        return collect($builder->orders)->mapWithKeys(function($value) {
            return [$value['column'] => $value['direction']];
        })->all();
    }

    protected function boosts(Builder $builder)
    {
        return collect($builder->wheres)->map(function($value, $expression) {
            $searchExpression = new SearchExpression($expression, $value);

            return $searchExpression->isBoost() ? $searchExpression->get() : null;
        })->filter()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return $results['hits']->pluck('id');
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
        if ($results['hits']->count() === 0) {
            return Collection::make();
        }

        $modelTypes = [];
        foreach ($results['hits'] as $result) {
            $modelTypes[$result['type']][$result['id']] = null;
        }

        foreach ($modelTypes as $type => &$map) {
            $modelClass = '\\' . $type;
            $model = new $modelClass;

            $map = $model->whereIn($model->getQualifiedKeyName(), array_keys($map))
                ->get()->keyBy($model->getKeyName());
        }

        return Collection::make($results['hits'])->map(function ($hit) use ($modelTypes) {
            if (isset($modelTypes[$hit['type']][$hit['id']])) {
                return $modelTypes[$hit['type']][$hit['id']];
            }
        })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }
}
