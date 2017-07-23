<?php

namespace Globalia\LaravelScoutMysql\Models;

class Boost
{
    private $field;

    private $value;

    public function __construct($field, $value)
    {
        $this->field = $field;

        $this->value = $value;
    }

    public function getField()
    {
        return $this->field;
    }

    public function getValue()
    {
        return $this->value;
    }
}
