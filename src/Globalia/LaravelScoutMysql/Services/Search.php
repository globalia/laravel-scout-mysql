<?php

namespace Globalia\LaravelScoutMysql\Services;

use Globalia\LaravelScoutMysql\Models\SearchIndex;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection as ModelsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Search
{
    private $reservedFields = ['id', 'indexable_type', 'indexable_id', 'indexed_at'];

    private $schemaInfo;

    private $diffColumns;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    private function getConnection()
    {
        return $this->db->connection(config('scout_mysql.connection_name'));
    }

    private function getSchemaBuilder()
    {
        return $this->getConnection()->getSchemaBuilder();
    }

    private function getTable()
    {
        return with(new SearchIndex)->getTable();
    }

    private function updateSchema($schema)
    {
        DB::transaction(function () use ($schema) {
            $boostColumns = [];

            Schema::table($this->getTable(), function (Blueprint $table) use (&$boostColumns) {
                foreach ($this->diffColumns as $column => $config) {
                    $columnType = is_array($config) ? $config[0] : $config;

                    call_user_func([$table, $columnType], $column)->nullable();

                    if (in_array($columnType, ['string', 'text'], true)) {
                        if (is_array($config) && Arr::get($config, 'boostable', false)) {
                            $boostColumns[] = $column;
                        }
                    } else {
                        $table->index($column);
                    }
                }
            });

            $searchColumns = [];
            $sb = $this->getSchemaBuilder();
            $columns = $sb->getColumnListing($this->getTable());

            foreach ($columns as $column) {
                if (in_array($column, $this->reservedFields, true)) {
                    continue;
                }

                $type = $sb->getColumnType($this->getTable(), $column);

                if (in_array($type, ['string', 'text'], true)) {
                    $searchColumns[] = $column;
                }
            }

            $sm = $this->getConnection()->getDoctrineSchemaManager();
            $prefixedTable = $this->getConnection()->getTablePrefix() . $this->getTable();
            $indexes = $sm->listTableIndexes($prefixedTable);

            if (isset($indexes['search'])) {
                $sm->dropIndex($indexes['search'], $prefixedTable);
            }

            DB::statement(strtr('ALTER TABLE {table} ADD FULLTEXT INDEX `{index}` ({column})', [
                '{table}' => $this->getTable(),
                '{index}' => 'search',
                '{column}' => implode(',', $searchColumns)
            ]));

            foreach ($boostColumns as $column) {
                DB::statement(strtr('ALTER TABLE {table} ADD FULLTEXT INDEX `{index}` ({column})', [
                    '{table}' => $this->getTable(),
                    '{index}' => $column . '_boostable',
                    '{column}' => $column
                ]));
            }
        });
    }

    private function hasSchemaCompatible(Model $model)
    {
        $diffColumns = $this->diffColumns($model);

        if (count($diffColumns) > 0) {
            return false;
        }

        return true;
    }

    private function diffColumns(Model $model, $refresh = true)
    {
        if ($refresh || null === $this->diffColumns) {
            $modelSchemaInfo = $model->searchIndexSchema();
            $dataSchemaInfo = $this->getSchemaInfoForData($model->toSearchableArray());

            $oldSchemaInfo = $this->getSchemaInfo($refresh);
            $newSchemaInfo = array_merge($dataSchemaInfo, $modelSchemaInfo);

            $this->diffColumns = [];

            foreach ($newSchemaInfo as $field => $config) {
                if (! is_array($config)) {
                    $config = [$config];

                    if (in_array($config[0], ['string', 'text'], true)) {
                        $config['boostable'] = false;
                    }
                }

                if (! isset($oldSchemaInfo[$field]) || $oldSchemaInfo[$field] !== $config) {
                    $this->diffColumns[$field] = $config;
                }
            }
        }

        return $this->diffColumns;
    }

    private function getSchemaInfo($refresh = false)
    {
        if ($refresh === false && null !== $this->schemaInfo) {
            return $this->schemaInfo;
        }

        $schemaInfo = [];

        $sb = $this->getSchemaBuilder();
        $sm = $this->getConnection()->getDoctrineSchemaManager();
        $prefixedTable = $this->getConnection()->getTablePrefix().$this->getTable();

        $columns = $this->getSchemaBuilder()->getColumnListing($this->getTable());
        $indexes = $sm->listTableIndexes($prefixedTable);

        foreach ($columns as $column) {
            if (in_array($column, $this->reservedFields, true)) {
                continue;
            }

            $type = $sb->getColumnType($this->getTable(), $column);

            if ($type === 'datetime') {
                $type = 'dateTime';
            }

            $schemaInfo[$column][0] = $type;

            if (in_array($type, ['string', 'text'], true)) {
                $schemaInfo[$column]['boostable'] = isset($indexes["{$column}_boostable"]);
            }
        }

        return $this->schemaInfo = $schemaInfo;
    }

    private function getSchemaInfoForData(array $data)
    {
        $schemaInfo = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $this->reservedFields, true)) {
                continue;
            }

            if (is_bool($value)) {
                $schemaInfo[$field] = 'boolean';
            } elseif (is_int($value)) {
                $schemaInfo[$field] = 'integer';
            } elseif (is_float($value)) {
                $schemaInfo[$field] = 'float';
            } elseif ($value instanceof \DateTime) {
                $schemaInfo[$field] = 'dateTime';
            } elseif (is_string($value)) {
                $schemaInfo[$field] = 'text';
            } elseif (null !== $value) {
                throw new \InvalidArgumentException(
                    sprintf('Data field %s as illegal type %s.', $field, gettype($value))
                );
            }
        }

        return $schemaInfo;
    }

    public function indexModel(Model $model)
    {
        $this->indexModels(new ModelsCollection([$model]));
    }

    public function indexModels($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $firstModel = $models->first();

        if (! $this->hasSchemaCompatible($firstModel)) {
            $this->updateSchema($this->diffColumns($firstModel, false));
        }

        $this->performIndexModels($models);
    }

    private function performIndexModels($models)
    {
        foreach ($models as $model) {
            $model->searchIndex()->updateOrCreate([], $model->toSearchableArray());
        }
    }
    
    public function deleteModel(Model $model)
    {
        $this->deleteModels(new ModelsCollection([$model]));
    }

    public function deleteModels($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $firstModel = $models->first();

        $morphType = $firstModel->searchIndex()->getMorphType();
        $morphClass = $firstModel->searchIndex()->getMorphClass();
        $foreignKeyName = $firstModel->searchIndex()->getForeignKeyName();

        SearchIndex::where($morphType, $morphClass)
            ->whereIn($foreignKeyName, $models->pluck('id')->all())
            ->delete();
    }

    private function getSearchableColumns()
    {
        return collect($this->getSchemaInfo())->filter(function($columnInfo) {
            return in_array($columnInfo[0], ['string', 'text'], true);
        })->keys()->all();
    }

    private function getStringColumns()
    {
        return collect($this->getSchemaInfo())->filter(function($columnInfo) {
            return $columnInfo[0] === 'string';
        })->keys()->all();
    }

    public function search($term, array $options = [])
    {
        $searchIndex = new SearchIndex;
        $morphType = $searchIndex->indexable()->getMorphType();
        $foreignKey = $searchIndex->indexable()->getForeignKey();

        $query = DB::table($searchIndex->getTable())
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
        } elseif ($termLength < 5 /* config('scout_mysql.match_wildcard_min')*/) {
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
            $results['hits'] = new ModelsCollection;

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
            $results['hits'] = new ModelsCollection;

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
        $searchableColumns = $this->getSearchableColumns();

        if (empty($searchableColumns)) {
            $results['total'] = 0;
            $results['hits'] = new ModelsCollection;

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

            // Or String Like Match Words
            $query->orWhere(function($query) use ($term) {
//            $searchWords = collect(explode(' ', $term))->map(function($value) {
//                $word = trim($value);
//                if (mb_strlen($word) >= config('scout_mysql.like_word_min')) {
//                    return $word;
//                }
//            })->filter()->implode('%');
//
//            foreach ($this->getStringColumns() as $column) {
//                $query->orWhere($column, 'LIKE', "%{$searchWords}%");
//            }

//            foreach ($this->getStringColumns() as $column) {
//                $query->orWhere($column, 'LIKE', "%{$term}%");
//            }
            });
        });

        $results['total'] = $query->count();

        if (! empty($options['boosts'])) {
            $query->addSelect(DB::raw(strtr("{$searchMatch} as ___rel", [
                '{columns}' => implode(',', $searchableColumns),
                '{term}' => $this->getConnection()->getPdo()->quote($term),
            ])));

            $orderBy = '___rel';

            foreach ($options['boosts'] as $boost) {
                $column = $boost->getField();
                $value = $boost->getValue();

                $query->addSelect(DB::raw(strtr("{$searchMatch} as ___rel_{$column}", [
                    '{columns}' => $column,
                    '{term}' => $this->getConnection()->getPdo()->quote($term),
                ])));

                $orderBy .= " + (___rel_{$column} * {$value})";
            }

            $query->orderBy(DB::raw($orderBy), 'DESC');
        }
    }
}
