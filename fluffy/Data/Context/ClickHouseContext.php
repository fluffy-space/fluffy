<?php

namespace Fluffy\Data\Context;

use Exception;
use Fluffy\Data\Connector\IClickHouseConnector;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Data\Query\Column;
use Fluffy\Data\Query\Expression;
use Fluffy\Data\Query\ExpressionGroup;
use Fluffy\Data\Query\Query;

/**
 * ClickHouse query context — the CH analog of DbContext. Reuses the dialect-agnostic Query /
 * Expression / Column builder; emits ClickHouse SQL (backtick identifiers, `database`.`table`,
 * `count()`, no schema/joins/includes). Keeps its own entity-map registry so the same entity can
 * be backed by Postgres or ClickHouse independently.
 */
class ClickHouseContext
{
    /** @var array<string,string> entityType => entityMap */
    public static array $map = [];

    public function __construct(private IMapper $mapper, private IClickHouseConnector $connector) {}

    public static function registerEntity(string $entityType, string $entityMap): void
    {
        self::$map[$entityType] = $entityMap;
    }

    public function execute(Query $query)
    {
        $entityMap = $query->entityTypeMap
            ?? self::$map[$query->entityType]
            ?? throw new Exception("{$query->entityType} has no registered ClickHouse entity map.");
        $sqlQueries = $this->buildQuery($query, $entityMap);

        $list = [];
        if (isset($sqlQueries['list'])) {
            $arr = $this->connector->query($sqlQueries['list']);
            $list = $arr ? array_map(fn($row) => $row ? $this->mapper->mapAssoc($query->entityType, $row) : null, $arr) : [];
        }
        if ($query->firstOrDefault) {
            return $list ? $list[0] : null;
        }
        $result = ['list' => $list];
        if (isset($sqlQueries['count'])) {
            $arr = $this->connector->query($sqlQueries['count']);
            $result['total'] = (int)($arr[0]['count'] ?? 0);
        }
        return $result;
    }

    /** `db`.`table` (db omitted when the map relies on the connection default). */
    private function table(string $entityMap): string
    {
        $db = $entityMap::$Database;
        return ($db !== '' ? "`$db`." : '') . "`{$entityMap::$Table}`";
    }

    public function buildQuery(Query $query, string $entityMap): array
    {
        $alias = $query->alias ?? '';
        $aliasPrefix = $alias ? "$alias." : "";

        $columns = $entityMap::Columns();
        if ($query->selectColumns !== null) {
            $columns = array_flip($query->selectColumns);
        }
        $select = '';
        $comma = '';
        foreach ($columns as $property => $_) {
            $select .= "$comma{$aliasPrefix}`{$property}`";
            $comma = ', ';
        }

        $orderGlue = 'ORDER BY ';
        $orderBy = '';
        foreach ($query->orderBys as [$column, $orderWay]) {
            $orderBy .= $orderGlue . "{$aliasPrefix}`$column`" . ($orderWay > 0 ? ' ASC' : ' DESC');
            $orderGlue = ', ';
        }

        $wherePart = $query->whereExpression
            ? 'WHERE ' . $this->buildExpression($query->whereExpression)
            : '';

        $table = $this->table($entityMap);
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
            $queries['list'] = "SELECT $select FROM $table $alias $wherePart $orderBy $limit";
        }
        if ($query->withCount && !$query->firstOrDefault) {
            $queries['count'] = "SELECT count() as `count` FROM $table $alias $wherePart";
        }
        return $queries;
    }

    public function buildExpression(Expression $expression): string
    {
        $raw = '';
        if (!($expression->left instanceof ExpressionGroup)) {
            if ($expression->left instanceof Column) {
                $aliasPrefix = $expression->left->alias ? "{$expression->left->alias}." : "";
                $raw .= "$aliasPrefix`{$expression->left->name}`";
            } elseif ($expression->left instanceof Expression) {
                $raw .= $this->buildExpression($expression->left);
            } else {
                $raw .= $this->buildValue($expression->left);
            }

            if ($expression->operatorRaw) {
                $raw .= ' ' . $expression->operatorRaw . ' ';
            }

            if ($expression->right !== null) {
                if ($expression->right instanceof Column) {
                    $aliasPrefix = $expression->right->alias ? "{$expression->right->alias}." : "";
                    $raw .= "$aliasPrefix`{$expression->right->name}`";
                } elseif ($expression->right instanceof Expression) {
                    $raw .= $this->buildExpression($expression->right);
                } else {
                    $raw .= $this->buildValue($expression->right);
                }
            }
        }

        foreach ($expression->children as $child) {
            if ($raw) {
                $raw .= ' ' . $child->glueOperator . ' ';
            }
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

    public function buildValue($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif ($value === null) {
            return 'NULL';
        } elseif (is_integer($value)) {
            return (string)$value;
        } elseif (is_float($value)) {
            return number_format($value, 8, '.', '');
        } elseif (is_array($value)) {
            return '(' . implode(', ', array_map(fn($x) => $this->buildValue($x), $value)) . ')';
        }
        return $this->connector->escapeLiteral($value);
    }
}
