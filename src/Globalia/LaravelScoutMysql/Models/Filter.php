<?php

namespace Globalia\LaravelScoutMysql\Models;

use Illuminate\Database\Query\Builder;

class Filter
{
    private $expression;

    private $operator;

    private $value;

    public function __construct($expression, $operator = null, $value = null)
    {
        $this->expression = $expression;

        $this->operator = $operator;

        $this->value = $value;
    }

    public function apply(Builder $query)
    {
        if (null === $this->operator) {
            $query->whereRaw($this->expression, $this->value ?: []);
        } else {
            $columns = explode(',', str_replace(' ', '', $this->expression));

            $query->where(function(Builder $query) use ($columns) {
                foreach ($columns as $column) {
                    $query->orWhere($column, $this->operator, $this->value);
                }
            });
        }
    }
}
