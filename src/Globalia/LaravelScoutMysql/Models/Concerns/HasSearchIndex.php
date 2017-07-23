<?php

namespace Globalia\LaravelScoutMysql\Models\Concerns;

use Laravel\Scout\Searchable;
use Globalia\LaravelScoutMysql\Models\SearchIndex;

trait HasSearchIndex
{
    use Searchable;

    public function searchIndexSchema()
    {
        if (property_exists($this, 'searchIndexSchema')) {
            return $this->searchIndexSchema;
        }

        return [];
    }

    /**
     * Get the search index model.
     */
    public function searchIndex()
    {
        return $this->morphOne(SearchIndex::class, 'indexable');
    }

    public function searchableAs()
    {
        return get_class($this);
    }
}
