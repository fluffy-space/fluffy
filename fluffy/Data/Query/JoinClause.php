<?php

namespace Fluffy\Data\Query;

use Fluffy\Data\Entities\BaseEntity;

class JoinClause
{
    public Expression $onExpression;

    public function __construct(public $join)
    {
        
    }
}
