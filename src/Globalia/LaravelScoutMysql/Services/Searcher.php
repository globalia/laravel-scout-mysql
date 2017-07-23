<?php

namespace Globalia\LaravelScoutMysql\Services;

use Globalia\LaravelScoutMysql\Models\SearchIndex;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class Searcher
{
    private $connection;

    private $indexer;

    public function __construct(DatabaseManager $db, Indexer $indexer)
    {
        $this->connection = $db->connection(config('scout_mysql.connection_name'));

        $this->indexer = $indexer;
    }

    private function getSearchColumns()
    {
        return collect($this->indexer->getSchemaInfo())->filter(function($columnInfo) {
            return in_array($columnInfo['type'], ['string', 'text'], true);
        })->keys()->all();
    }

    private function getStringColumns()
    {
        return collect($this->indexer->getSchemaInfo())->filter(function($columnInfo) {
            return $columnInfo['type'] === 'string';
        })->keys()->all();
    }

    public function search($term, array $options = [])
    {
        $searchIndex = new SearchIndex;
        $morphType = $searchIndex->indexable()->getMorphType();
        $foreignKey = $searchIndex->indexable()->getForeignKey();

        $query = $this->connection->table($searchIndex->getTable())
            ->select($foreignKey, $morphType);

        if (Arr::get($options, 'index', '*') !== '*') {
            $query->where($morphType, $options['index']);
        }

        foreach (Arr::get($options, 'filters', []) as $filter) {
            $filter->apply($query);
        }

        $results = [];

        $termLength = mb_strlen($term);

        if ($termLength === 0) {
            $results['total'] = $query->count();
        } elseif ($termLength < config('scout_mysql.like_search_min')) {
            $this->searchStringStart($term, $options, $query, $results);
        } elseif ($termLength < config('scout_mysql.match_search_min')) {
            $this->searchStringLikeMatch($term, $options, $query, $results);
        } elseif ($termLength < config('scout_mysql.match_wildcard_min')) {
            $this->searchAllMatch($term, $options, $query, $results);
        } else {
            $this->searchAllMatch($term, $options, $query, $results, true);
        }

        if (isset($results['hits'])) {
            return $results;
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        if (Arr::get($options, 'offset', 0) > 0) {
            $query = $query->offset($options['offset']);
        }

        if (! empty($options['orders'])) {
            $query->orders = null;

            foreach ($options['orders'] as $column => $direction) {
                $query->orderBy($column, $direction);
            }
        }

        $results['hits'] = $query->get()->map(function($row) use ($morphType, $foreignKey) {
            return ['id' => $row->{$foreignKey}, 'type' => $row->{$morphType}];
        });

        return $results;
    }

    private function searchStringStart($term, $options, $query, &$results)
    {
        $stringColumns = $this->getStringColumns();

        if (empty($stringColumns)) {
            $results['total'] = 0;
            $results['hits'] = new Collection;

            return;
        }

        $query->where(function($query) use ($term, $stringColumns) {
            foreach ($stringColumns as $column) {
                $query->orWhere($column, 'LIKE', "{$term}%");
            }
        });

        $results['total'] = $query->count();
    }

    private function searchStringLikeMatch($term, $options, $query, &$results)
    {
        $stringColumns = $this->getStringColumns();

        if (empty($stringColumns)) {
            $results['total'] = 0;
            $results['hits'] = new Collection;

            return;
        }

        $query->where(function($query) use ($term, $stringColumns) {
            foreach ($stringColumns as $column) {
                $query->orWhere($column, 'LIKE', "%{$term}%");
            }
        });

        $results['total'] = $query->count();
    }

    private function searchAllMatch($term, $options, $query, &$results, $wildcard = false)
    {
        $stringColumns = $this->getStringColumns();
        $searchableColumns = $this->getSearchColumns();

        if (empty($searchableColumns)) {
            $results['total'] = 0;
            $results['hits'] = new Collection;

            return;
        }

        $searchMatch = 'MATCH({columns}) AGAINST ({term} IN BOOLEAN MODE)';

        $query->where(function($query) use ($term, $searchMatch, $stringColumns, $searchableColumns, $wildcard) {
            $query->whereRaw(strtr($searchMatch, [
                '{columns}' => implode(',', $searchableColumns),
                '{term}' => '?',
            ]), [$wildcard ? $term.'*' : $term]);

            // Or String Like Match Term
            foreach ($stringColumns as $column) {
                $query->orWhere($column, 'LIKE', "%{$term}%");
            }

//            // Or String Like Match Words
//            $query->orWhere(function($query) use ($term) {
//                $searchWords = collect(explode(' ', $term))->map(function($value) {
//                    $word = trim($value);
//                    if (mb_strlen($word) >= config('scout_mysql.like_word_min')) {
//                        return $word;
//                    }
//                })->filter()->implode('%');
//
//                foreach ($this->getStringColumns() as $column) {
//                    $query->orWhere($column, 'LIKE', "%{$searchWords}%");
//                }
//
//                foreach ($this->getStringColumns() as $column) {
//                    $query->orWhere($column, 'LIKE', "%{$term}%");
//                }
//            });
        });

        $results['total'] = $query->count();

        if (! empty($options['boosts'])) {
            $query->addSelect($this->connection->raw(strtr("{$searchMatch} as ___rel", [
                '{columns}' => implode(',', $searchableColumns),
                '{term}' => $this->connection->getPdo()->quote($term),
            ])));

            $orderBy = '___rel';

            foreach ($options['boosts'] as $boost) {
                $column = $boost->getField();
                $value = $boost->getValue();

                $query->addSelect($this->connection->raw(strtr("{$searchMatch} as ___rel_{$column}", [
                    '{columns}' => $column,
                    '{term}' => $this->connection->getPdo()->quote($term),
                ])));

                $orderBy .= " + (___rel_{$column} * {$value})";
            }

            $query->orderBy($this->connection->raw($orderBy), 'DESC');
        }
    }
}
