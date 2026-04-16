<?php

namespace Fluffy\Data\Query;

class ExpressionTuple
{
    public function __construct(
        public Expression $expression,
        public $glueOperator = 'and'
    ) {}
}
