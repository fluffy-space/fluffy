<?php

namespace Fluffy\Data\Context;

use Exception;
use Fluffy\Data\Connector\IConnector;
use Fluffy\Data\Entities\BaseEntity;
use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Data\Query\Query;
use RuntimeException;
use Swoole\Coroutine\PostgreSQL;

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
        $pg = $this->connector->get();
        $entityMap = $query->entityTypeMap ?? EntitiesMap::$map[$query->entityType] ?? throw new Exception("{$query->entityType} has no registered entity map.");
        $sqlQueries = $this->buildQuery($query, $pg, $entityMap);
        // print_r([$sqlQueries]);
        /**
         * @var BaseEntity[] $list
         */
        $list = [];
        if (isset($sqlQueries['list'])) {
            $stmt = $pg->query($sqlQueries['list']);
            if (!$stmt) {
                throw new RuntimeException("{$pg->error} {$pg->errCode}");
            }
            $arr = $stmt->fetchAll(SW_PGSQL_ASSOC);
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
                        ->where([$columnKey, 'in', $ids])
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
            $stmt = $pg->query($sqlQueries['count']);
            if (!$stmt) {
                throw new RuntimeException("{$pg->error} {$pg->errCode}");
            }
            $arr = $stmt->fetchAssoc();
            $result['total'] = $arr['count'];
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
    public function buildQuery(Query $query, PostgreSQL $pg, string $entityMap): array
    {

        $select = '';
        $comma = '';
        $columns = $entityMap::Columns();
        if ($query->selectColumns !== null) {
            $columns = array_flip($query->selectColumns);
        }
        foreach ($columns as $property => $_) {
            $select .= "$comma\"{$property}\"";
            $comma = ', ';
        }

        $orderGlue = "ORDER BY ";
        $orderBy = '';
        foreach ($query->orderBys as [$column, $orderWay]) {
            $orderBy .= $orderGlue . "\"$column\"" . ($orderWay > 0 ? " ASC" : " DESC");
            $orderGlue = ', ';
        }

        $wherePart = $this->buildWhere($query->expressions, $pg);
        if ($wherePart) {
            $wherePart = "WHERE $wherePart";
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
            $queries['list'] =  "SELECT $select FROM {$entityMap::$Schema}.\"{$entityMap::$Table}\" $wherePart $orderBy $limit";
        }

        if ($query->withCount && !$query->firstOrDefault) {
            $queries['count'] = "SELECT COUNT(*) as \"count\" FROM {$entityMap::$Schema}.\"{$entityMap::$Table}\" $wherePart";
        }

        return $queries;
    }

    public function buildWhere(array $where, PostgreSQL $pg, string $concatOperator = "AND"): string
    {
        $wherePart = '';
        $whereGlue = '';
        foreach ($where as $condition) {
            $column = $condition[0];
            $orOperator = is_array($column);
            if ($orOperator) {
                $total = count($condition);
                $wherePart .= $whereGlue . ($total > 1 ? '(' : '') . $this->buildWhere($condition, $pg, 'OR') . ($total > 1 ? ')' : '');
            } else {
                $hasOperator = isset($condition[2]);
                $value = $this->buildValue($hasOperator ? $condition[2] : $condition[1], $pg);
                $operator = $hasOperator ? $condition[1] : '=';

                $wherePart .= $whereGlue . "\"$column\" $operator $value";
            }
            $whereGlue = " $concatOperator ";
        }
        return $wherePart;
    }

    public function buildValue($value, PostgreSQL $pg)
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
            $value = "(" . implode(", ", array_map(fn($x) => $this->buildValue($x, $pg, ""), $value)) . ")";
        } else {
            $value = $pg->escapeLiteral($value);
        }
        return $value;
    }
}
