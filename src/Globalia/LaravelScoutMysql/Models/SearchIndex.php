<?php

namespace Globalia\LaravelScoutMysql\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\SearchableScope;

class SearchIndex extends Model
{
    public $timestamps = true;

    const CREATED_AT = null;

    const UPDATED_AT = 'indexed_at';

    public static function bootSearchable()
    {
        static::addGlobalScope(new SearchableScope);
    }

    public static function search($query, $callback = null)
    {
        return new Builder(new static, $query, $callback);
    }

    public function searchableUsing()
    {
        return app(EngineManager::class)->engine();
    }

    public function getTable()
    {
        return config('scout_mysql.table');
    }

    /**
     * Get the owning indexable model.
     */
    public function indexable()
    {
        return $this->morphTo();
    }

    public function searchableAs()
    {
        return '*';
    }
}
