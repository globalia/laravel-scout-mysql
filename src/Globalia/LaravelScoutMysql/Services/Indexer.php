<?php

namespace Globalia\LaravelScoutMysql\Services;

use Globalia\LaravelScoutMysql\Models\SearchIndex;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;

class Indexer
{
    private $connection;

    private $reservedFields = ['id', 'indexable_type', 'indexable_id', 'indexed_at'];

    private $schemaInfo;

    private $diffColumns;

    public function __construct(DatabaseManager $db)
    {
        $this->connection = $db->connection(config('scout_mysql.connection_name'));
    }

    private function getTable()
    {
        return with(new SearchIndex)->getTable();
    }

    private function isSearchColumn($column, $type)
    {
        return ! in_array($column, $this->reservedFields, true)
            && in_array($type, ['string', 'text'], true);
    }

    private function getSearchColumns()
    {
        $sb = $this->connection->getSchemaBuilder();

        $columns = $sb->getColumnListing($this->getTable());

        $searchColumns = [];

        foreach ($columns as $column) {
            $type = $sb->getColumnType($this->getTable(), $column);

            if ($this->isSearchColumn($column, $type)) {
                $searchColumns[] = $column;
            }
        }

        return $searchColumns;
    }

    private function fetchSearchColumns(Array $schema)
    {
        $searchColumns = [];

        foreach ($schema as $column => $config) {
            $type = is_array($config) ? $config['type'] : $config;

            if ($this->isSearchColumn($column, $type)) {
                $searchColumns[] = $column;
            }
        }

        return $searchColumns;
    }

    private function fetchBoostColumns(Array $schema)
    {
        $boostColumns = [];

        foreach ($schema as $column => $config) {
            $type = is_array($config) ? $config['type'] : $config;
            $boostable = Arr::get($config, 'boostable', false);

            if ($boostable && $this->isSearchColumn($column, $type)) {
                $boostColumns[] = $column;
            }
        }

        return $boostColumns;
    }

    private function alterSchema($schema)
    {
        $this->connection->transaction(function () use ($schema) {
            $this->alterColumns($schema);

            $this->alterBoostIndexes($schema);

            $this->alterSearchIndex();
        });
    }

    private function alterColumns($schema)
    {
        $sb = $this->connection->getSchemaBuilder();

        $sb->table($this->getTable(), function (Blueprint $blueprint) use ($schema) {
            $searchColumns = $this->fetchSearchColumns($schema);

            foreach ($schema as $column => $config) {
                $type = is_array($config) ? $config['type'] : $config;

                call_user_func([$blueprint, $type], $column)->nullable();

                if (! in_array($column, $searchColumns, true)) {
                    $blueprint->index($column);
                }
            }
        });
    }

    private function alterBoostIndexes(Array $schema)
    {
        $boostColumns = $this->fetchBoostColumns($schema);

        foreach ($boostColumns as $column) {
            $this->connection->statement(strtr('ALTER TABLE {table} ADD FULLTEXT INDEX `{index}` ({column})', [
                '{table}' => $this->getTable(),
                '{index}' => 'search_' . $column,
                '{column}' => $column
            ]));
        }
    }

    private function alterSearchIndex()
    {
        $searchColumns = $this->getSearchColumns();

        $sm = $this->connection->getDoctrineSchemaManager();
        $table = $this->connection->getTablePrefix() . $this->getTable();
        $indexes = $sm->listTableIndexes($table);

        if (isset($indexes['search'])) {
            $sm->dropIndex($indexes['search'], $table);
        }

        $this->connection->statement(strtr('ALTER TABLE {table} ADD FULLTEXT INDEX `{index}` ({column})', [
            '{table}' => $this->getTable(),
            '{index}' => 'search',
            '{column}' => implode(',', $searchColumns)
        ]));
    }

    private function diffColumns(Model $model, $refresh = true)
    {
        if (! $refresh && null !== $this->diffColumns) {
            return $this->diffColumns;
        }

        $modelSchemaInfo = $model->searchIndexSchema();
        $dataSchemaInfo = $this->getSchemaInfoForData($model->toSearchableArray());

        $oldSchemaInfo = $this->getSchemaInfo($refresh);
        $newSchemaInfo = array_merge($dataSchemaInfo, $modelSchemaInfo);

        $this->diffColumns = [];

        foreach ($newSchemaInfo as $field => $config) {
            if (! is_array($config)) {
                $config = [$config];

                if (in_array($config['type'], ['string', 'text'], true)) {
                    $config['boostable'] = false;
                }
            }

            if (! isset($oldSchemaInfo[$field]) || $oldSchemaInfo[$field] !== $config) {
                $this->diffColumns[$field] = $config;
            }
        }

        return $this->diffColumns;
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

    public function getSchemaInfo($refresh = false)
    {
        if ($refresh === false && null !== $this->schemaInfo) {
            return $this->schemaInfo;
        }

        $schemaInfo = [];

        $sb = $this->connection->getSchemaBuilder();
        $sm = $this->connection->getDoctrineSchemaManager();
        $table = $this->connection->getTablePrefix().$this->getTable();

        $columns = $sb->getColumnListing($this->getTable());
        $indexes = $sm->listTableIndexes($table);

        foreach ($columns as $column) {
            if (in_array($column, $this->reservedFields, true)) {
                continue;
            }

            $type = $sb->getColumnType($this->getTable(), $column);

            if ($type === 'datetime') {
                $type = 'dateTime';
            }

            $schemaInfo[$column]['type'] = $type;

            if (in_array($type, ['string', 'text'], true)) {
                $schemaInfo[$column]['boostable'] = isset($indexes["search_{$column}"]);
            }
        }

        return $this->schemaInfo = $schemaInfo;
    }

    public function indexModels(Collection $models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $diffSchema = $this->diffColumns($models->first());

        if (count($diffSchema) > 0) {
            $this->alterSchema($diffSchema);
        }

        foreach ($models as $model) {
            $model->searchIndex()->updateOrCreate([], $model->toSearchableArray());
        }
    }

    public function deleteModels($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $searchIndexRelation = $models->first()->searchIndex();

        $morphType = $searchIndexRelation->getMorphType();
        $morphClass = $searchIndexRelation->getMorphClass();
        $foreignKeyName = $searchIndexRelation->getForeignKeyName();

        SearchIndex::query()
            ->where($morphType, $morphClass)
            ->whereIn($foreignKeyName, $models->pluck('id')->all())
            ->delete();
    }
}
