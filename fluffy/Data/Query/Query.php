<?php

namespace Fluffy\Data\Query;

use Fluffy\Data\Entities\BaseEntity;

class Query
{
    public array $expressions = [];
    public array $orderBys = [];
    public array $joins = [];
    public array $includes = [];
    public int $page = -1;
    public int $take = -1;
    public int $skip = 0;
    public bool $withCount = true;
    public ?string $entityTypeMap = null;
    public bool $firstOrDefault = false;
    /**
     * 
     * @param string|BaseEntity $entityType 
     * @return void 
     */
    public function __construct(public $entityType, public ?Query $parentQuery = null, public ?string $alias = null) {}

    /**
     * 
     * @param string|BaseEntity $entityType 
     * @return Query 
     */
    public static function from($entityType, ?string $alias = null): self
    {
        return new Query($entityType, null, $alias);
    }

    public static function or($expression): array
    {
        return $expression;
    }

    public function where($expression)
    {
        $this->expressions[] = $expression;
        return $this;
    }

    public function orderBy($orderValue): self
    {
        $this->orderBys[] = [$orderValue, 1];
        return $this;
    }

    public function orderByDescending($orderValue): self
    {
        $this->orderBys[] = [$orderValue, -1];
        return $this;
    }

    public function thenBy($orderValue): self
    {
        $this->orderBys[] = [$orderValue, 1];
        return $this;
    }

    public function thenByDescending($orderValue): self
    {
        $this->orderBys[] = [$orderValue, -1];
        return $this;
    }

    public function as($aliasName): self
    {
        $this->alias = $aliasName;
        return $this;
    }

    /**
     * 
     * @param string|BaseEntity $entityType 
     * @return Join 
     */
    public function join($entityType, ?string $alias = null): self
    {
        $join = new Query($entityType, $this, $alias);
        $this->joins[] = $join;
        return $join;
    }

    public function on($expression): self
    {
        $this->expressions[] = $expression;
        return $this->parentQuery;
    }

    public function include($include): self
    {
        $this->includes[] = $include;
        return $this;
    }

    public function page(int $page): self
    {
        $this->page = $page;
        return $this;
    }

    public function skip(int $skip): self
    {
        $this->skip = $skip;
        return $this;
    }

    public function take(int $take): self
    {
        $this->take = $take;
        return $this;
    }

    public function withCount(bool $withCount = true): self
    {
        $this->withCount = $withCount;
        return $this;
    }

    public function withEntityMap(string $entityMap): self
    {
        $this->entityTypeMap = $entityMap;
        return $this;
    }

    public function firstOrDefault(): self
    {
        $this->firstOrDefault = true;
        $this->skip = 0;
        $this->take = 1;
        return $this;
    }
}
