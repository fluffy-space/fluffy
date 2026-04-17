<?php

namespace Fluffy\Data\Context;

use Exception;
use Fluffy\Data\Connector\IConnector;
use Fluffy\Data\Entities\BaseEntity;
use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Data\Query\Column;
use Fluffy\Data\Query\Expression;
use Fluffy\Data\Query\Query;
use RuntimeException;
use Swoole\Coroutine\PostgreSQL;

use function Fluffy\Data\Query\c;
use function Fluffy\Data\Query\x;

class DbContext
{
    public function __construct(private IMapper $mapper, private IConnector $connector) {}

    /**
     * 
     * @param BaseEntity|string $entityType 
     * @param BaseEntityMap|string $entityMap 
     * @return void 
     */
    public static function registerEntity(string $entityType,  string $entityMap)
    {
        EntitiesMap::$map[$entityType] = $entityMap;
    }

    public function execute(Query $query)
    {
        $entityMap = $query->entityTypeMap ?? EntitiesMap::$map[$query->entityType] ?? throw new Exception("{$query->entityType} has no registered entity map.");
        $sqlQueries = $this->buildQuery($query, $entityMap);
        // print_r($query);
        print_r([$sqlQueries]);
        /**
         * @var BaseEntity[] $list
         */
        $list = [];
        if (isset($sqlQueries['list'])) {
            $arr = $this->connector->query($sqlQueries['list']);
            $list = $arr ? array_map(fn($row) => $row ? $this->mapper->mapAssoc($query->entityType, $row) : null, $arr) : [];

            // includes
            $foreignKeys = $entityMap::ForeignKeys();
            foreach ($query->includes as $includeProp) {
                if (!isset($foreignKeys[$includeProp])) {
                    throw new Exception("$includeProp is not configured in $entityMap.");
                }
                // collect ids
                $referenceKey = $foreignKeys[$includeProp]['Columns'][0];
                $columnKey = $foreignKeys[$includeProp]['References'][0];
                $idMap = [];
                $hasIds = false;
                foreach ($list as $entity) {
                    $keyValue = $entity->{$referenceKey};
                    if ($keyValue && !isset($idMap[$keyValue])) {
                        $ids[] = $keyValue;
                        $idMap[$keyValue] = 1;
                        $hasIds = true;
                    }
                }
                if ($hasIds) {
                    $entitiesToInclude = $this->execute(Query::from($foreignKeys[$includeProp]['Table'])
                        ->where(x(c($columnKey), 'IN', $ids))
                        ->withCount(false));

                    $map = [];
                    foreach ($entitiesToInclude['list'] as $entity) {
                        /**
                         * @var BaseEntity $entity
                         */
                        $map[$entity->{$columnKey}] = $entity;
                    }
                    foreach ($list as $entity) {
                        /**
                         * @var BaseEntity $entity
                         */
                        if (isset($map[$entity->{$referenceKey}])) {
                            $entity->{$includeProp} = $map[$entity->{$referenceKey}];
                        }
                    }
                }
            }
        }
        $result = ['list' => $list];
        if ($query->firstOrDefault) {
            return $list ? $list[0] : null;
        }
        if (isset($sqlQueries['count'])) {
            $arr = $this->connector->query($sqlQueries['count']);
            $result['total'] = $arr[0]['count'];
        }

        return $result;
    }

    /**
     * 
     * @param Query $query 
     * @param PostgreSQL $pg 
     * @param BaseEntityMap|string $entityMap 
     * @return array 
     */
    public function buildQuery(Query $query, string $entityMap): array
    {
        $alias = $query->alias ?? '';
        $select = '';
        $comma = '';
        $columns = $entityMap::Columns();
        if ($query->selectColumns !== null) {
            $columns = array_flip($query->selectColumns);
        }
        $aliasPrefix = $alias ? "$alias." : "";
        foreach ($columns as $property => $_) {
            $select .= "$comma{$aliasPrefix}\"{$property}\"";
            $comma = ', ';
        }

        $orderGlue = "ORDER BY ";
        $orderBy = '';
        foreach ($query->orderBys as [$column, $orderWay]) {
            $orderBy .= $orderGlue . "{$aliasPrefix}\"$column\"" . ($orderWay > 0 ? " ASC" : " DESC");
            $orderGlue = ', ';
        }


        $wherePart = '';
        if ($query->whereExpression) {
            $wherePart = "WHERE " . $this->buildExpression($query->whereExpression);
        }

        $joins = "";
        foreach ($query->joins as $joinQuery) {
            $joinEntityMap = $joinQuery->entityTypeMap ?? EntitiesMap::$map[$joinQuery->entityType] ?? throw new Exception("{$joinQuery->entityType} has no registered entity map.");
            $joinAlias = $joinQuery->alias ?? '';
            $joins .= " INNER JOIN {$joinEntityMap::$Schema}.\"{$joinEntityMap::$Table}\" $joinAlias";
            $joinOn = $this->buildExpression($joinQuery->onExpression);
            $joins .= " ON $joinOn";
        }

        $queries = [];

        $limit = '';
        if ($query->take !== 0) {
            if ($query->page > 0) {
                $offset = ($query->page - 1) * $query->take;
                $limit = "LIMIT {$query->take} OFFSET $offset";
            } elseif ($query->skip) {
                $limit = "LIMIT {$query->take} OFFSET {$query->skip}";
            } elseif ($query->take > 0) {
                $limit = "LIMIT {$query->take}";
            }
            $queries['list'] =  "SELECT $select FROM {$entityMap::$Schema}.\"{$entityMap::$Table}\" $alias $joins $wherePart $orderBy $limit";
        }

        if ($query->withCount && !$query->firstOrDefault) {
            $queries['count'] = "SELECT COUNT(*) as \"count\" FROM {$entityMap::$Schema}.\"{$entityMap::$Table}\" $alias $joins $wherePart";
        }

        return $queries;
    }

    public function buildExpression(Expression $expression): string
    {
        $raw = '';
        if ($expression->left instanceof Column) {
            $aliasPrefix = $expression->left->alias ? "{$expression->left->alias}." : "";
            $raw .= "$aliasPrefix\"{$expression->left->name}\"";
        } elseif ($expression->left instanceof Expression) {
            $raw .= $this->buildExpression($expression->left);
        } else {
            $raw .= $this->buildValue($expression->left);
        }

        if ($expression->operatorRaw) {
            $raw .= ' ' . $expression->operatorRaw . ' ';
        }

        if ($expression->right) {
            if ($expression->right instanceof Column) {
                $aliasPrefix = $expression->left->alias ? "{$expression->left->alias}." : "";
                $raw .= "$aliasPrefix\"{$expression->right->name}\"";
            } elseif ($expression->right instanceof Expression) {
                $raw .= $this->buildExpression($expression->right);
            } else {
                $raw .= $this->buildValue($expression->right);
            }
        }

        foreach ($expression->children as $child) {
            $raw .= ' ' . $child->glueOperator . ' ';
            $enclose = count($child->expression->children) > 0;
            if ($enclose) {
                $raw .= '(';
            }
            $raw .= $this->buildExpression($child->expression);
            if ($enclose) {
                $raw .= ')';
            }
        }

        return $raw;
    }

    /**
     * 
     * @param Expression[] $where 
     * @param string $concatOperator 
     * @return string 
     */
    public function buildWhere(array $where, string $concatOperator = "AND"): string
    {
        $wherePart = '';
        $whereGlue = '';
        foreach ($where as $expression) {
            $wherePart .= $whereGlue . $this->buildExpression($expression);
            $whereGlue = " $concatOperator ";
        }
        return $wherePart;
    }

    public function buildValue($value)
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } else if ($value === null) {
            $value = 'NULL';
        } else if (is_integer($value)) {
            // same
        } else if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        } else if (is_array($value)) {
            $value = "(" . implode(", ", array_map(fn($x) => $this->buildValue($x, ""), $value)) . ")";
        } else {
            $value = $this->connector->escapeLiteral($value);
        }
        return $value;
    }
}
