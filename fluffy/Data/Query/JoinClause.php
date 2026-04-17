<?php

namespace Fluffy\Data\Query;

class JoinClause
{
    public Expression $onExpression;

    /**
     * 
     * @param string|BaseEntity $entityType $entityType 
     * @param Query $parentQuery 
     * @param null|string $alias 
     * @return void 
     */
    public function __construct(public $entityType, public Query $parentQuery, public ?string $alias = null) {}


    public function on(Expression $expression): Query
    {
        $this->onExpression = $expression;
        return $this->parentQuery;
    }
}
