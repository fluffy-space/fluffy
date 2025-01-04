<?php

namespace Fluffy\Data\Query;

class Expression
{
    public $expressions = [];

    public function __construct($expression) {
        $this->expressions[] = ['and', $expression];
    }

    public static function x(?array $condition = null): self
    {
        return new Expression('and', $condition);
    }

    public function or($expression)
    {
        $this->expressions[] = ['or', $expression];
        return $this;
    }

    public function and($expression)
    {
        $this->expressions[] = ['and', $expression];
        return $this;
    }
}
