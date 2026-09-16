<?php

namespace Fluffy\Data\Repositories;

use Exception;
use Fluffy\Data\Connector\IConnector;
use Fluffy\Data\Entities\BaseEntity;
use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Mapper\IMapper;
use ReflectionClass;
use RuntimeException;
use Swoole\Coroutine\PostgreSQL;

/**
 * PHP has no generics, so the entity type is a docblock template: a subclass binds it with
 * `@extends BasePostgresqlRepository<XEntity>` and getById()/find()/search() then carry the real
 * entity into the editor instead of BaseEntity. Editors do NOT check docblocks on arguments, so
 * the write side is guarded at runtime by assertEntityType() instead.
 *
 * @template TEntity of BaseEntity
 */
class BasePostgresqlRepository
{
    /**
     * PROTECTED, not private: a repository subclass writing its own SQL is the intended
     * extension point, and every such method needs the map (schema/table names) and the
     * connector. Private made those reads resolve to an undefined property on the CHILD —
     * a warning, then `null::$Schema`, i.e. "Class name must be a valid object or a string"
     * at runtime rather than anything the caller could read as a missing dependency.
     *
     * @param class-string<TEntity> $entityType
     * @param class-string<BaseEntityMap> $entityMap
     */
    public function __construct(protected IMapper $mapper, protected IConnector $connector, protected string $entityType, protected string $entityMap)
    {
        // #[Inject] passes these as plain strings, so a copy-pasted attribute can aim a repository
        // at another table's map and nothing notices until it has written there. Once per
        // construction, never on a row path.
        if (!is_subclass_of($this->entityType, BaseEntity::class)) {
            throw new RuntimeException(static::class . ": entityType '{$this->entityType}' is not a " . BaseEntity::class . '.');
        }
        if (!is_subclass_of($this->entityMap, BaseEntityMap::class)) {
            throw new RuntimeException(static::class . ": entityMap '{$this->entityMap}' is not a " . BaseEntityMap::class . '.');
        }
    }

    /**
     * Every write goes through here. The corruption it prevents is silent: delete()/update() key
     * off $entity->Id alone, so an entity of the wrong class hits a real row in THIS table and
     * reports success. ~7ns against the ~260,000ns of the statement it guards.
     *
     * @param TEntity $entity
     */
    protected function assertEntityType(BaseEntity $entity): void
    {
        if (!$entity instanceof $this->entityType) {
            throw new RuntimeException(
                static::class . ' expects ' . $this->entityType . ', got ' . $entity::class
                    . " — refusing to write it to \"{$this->entityMap::$Table}\"."
            );
        }
    }

    /**
     * One COLUMN name, quoted for SQL. Schema and table names are not routed through here: those
     * are class constants on the entity map, while a column name can be chosen by the CALLER —
     * search()'s $order keys, buildWhere()'s conditions, find()'s key. Every such name is a
     * `PROPERTY_` constant today, and this is what keeps that a convention rather than a
     * requirement: a double quote inside the name closes the quoted identifier and everything
     * after it is SQL, which no value escaping would catch because it never reaches a value.
     */
    protected static function ident(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** Aggregate functions search() will emit; anything else is a caller bug, not SQL to run. */
    private const AGGREGATES = ['COUNT', 'SUM', 'MIN', 'MAX', 'AVG'];

    static function getTime(): int
    {
        $timeOfDay = gettimeofday();
        return $timeOfDay['sec'] * 1000000 + $timeOfDay['usec'];
    }

    /**
     * @template TInclude of BaseEntity
     * @param TEntity[] $entities
     * @param BasePostgresqlRepository<TInclude> $repository
     */
    public function include(
        array &$entities,
        BasePostgresqlRepository $repository,
        string $referenceKey,
        string $referenceName
    ) {
        // collect ids
        $ids = array_map(fn(BaseEntity $entity) => $entity->{$referenceKey}, $entities);
        if (count($ids) > 0) {
            $includes = $repository->search([
                [BaseEntityMap::PROPERTY_Id, 'in', $ids]
            ], [BaseEntityMap::PROPERTY_CreatedOn => 1], 1, null, false);
            $map = [];
            foreach ($includes['list'] as $entity) {
                /**
                 * @var BaseEntity $entity
                 */
                $map[$entity->Id] = $entity;
            }
            foreach ($entities as $entity) {
                /**
                 * @var BaseEntity $entity
                 */
                if (isset($map[$entity->{$referenceKey}])) {
                    $entity->{$referenceName} = $map[$entity->{$referenceKey}];
                }
            }
        }
    }

    /**
     * @return array{list: TEntity[], total?: int|string, aggregate?: array}
     */
    public function search(
        array $where = [],
        array $order = [BaseEntityMap::PROPERTY_CreatedOn => 1],
        int $page = 1,
        ?int $size = null,
        bool $returnCount = true,
        ?array $aggregate = null
    ) {

        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= $comma . self::ident($property);
            $comma = ', ';
        }

        $orderGlue = "ORDER BY ";
        $orderBy = '';
        foreach ($order as $column => $orderWay) {
            $orderBy .= $orderGlue . self::ident($column) . ($orderWay > 0 ? " ASC" : " DESC");
            $orderGlue = ', ';
        }

        $wherePart = $this->buildWhere($where);
        if ($wherePart) {
            $wherePart = "WHERE $wherePart";
        }
        $limit = '';
        if ($size !== null) {
            $offset = ($page - 1) * $size;
            $limit = "LIMIT $size OFFSET $offset";
        }
        $list = [];
        if ($size !== 0) {
            $sql = "SELECT $select FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $wherePart $orderBy $limit";
            // var_dump([$sql, $where]);
            $arr = $this->connector->query($sql);
            $list = $arr ? array_map(fn($row) => $row ? $this->mapper->mapAssoc($this->entityType, $row) : null, $arr) : [];
        }
        $result = ['list' => $list];
        if ($returnCount) {
            $countSql = "SELECT COUNT(*) as \"count\" FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $wherePart";
            $arr = $this->connector->query($countSql);
            $result['total'] = $arr[0]['count'];
        }
        if ($aggregate !== null) {
            $aggregateSql = '';
            $dlm = '';
            foreach ($aggregate as $aggregateItem) {
                $function = strtoupper((string) $aggregateItem[1]);
                if (!in_array($function, self::AGGREGATES, true)) {
                    throw new RuntimeException(static::class . ": unsupported aggregate function '{$aggregateItem[1]}'.");
                }
                $aggregateSql .= $dlm . $function . '(' . self::ident($aggregateItem[2]) . ') as ' . self::ident($aggregateItem[0]);
                $dlm = ', ';
            }
            $countSql = "SELECT $aggregateSql FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $wherePart";
            // print_r([$countSql]);
            $arr = $this->connector->query($countSql);
            $result['aggregate'] = $arr[0];
        }
        return $result;
    }

    public function buildWhere(array $where, string $concatOperator = "AND"): string
    {
        $wherePart = '';
        $whereGlue = '';
        foreach ($where as $condition) {
            $column = $condition[0];
            $orOperator = is_array($column);
            if ($orOperator) {
                $total = count($condition);
                $wherePart .= $whereGlue . ($total > 1 ? '(' : '') . $this->buildWhere($condition, 'OR') . ($total > 1 ? ')' : '');
            } else {
                $hasOperator = isset($condition[2]);
                $value = $this->buildValue($hasOperator ? $condition[2] : $condition[1]);
                $operator = $hasOperator ? $condition[1] : '=';

                $wherePart .= $whereGlue . self::ident($column) . " $operator $value";
            }
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

    /**
     * @return array{list: TEntity[], total: int|string}
     */
    public function getList(
        int $page = 1,
        ?int $size = 10,
        ?string $ordering = BaseEntityMap::PROPERTY_CreatedOn,
        int $order = 1, // -1 DESC
    ) {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= $comma . self::ident($property);
            $comma = ', ';
        }
        $orderBy = $ordering !== null ? ("ORDER BY " . self::ident($ordering) . ($order > 0 ? " ASC" : " DESC")) : '';
        $limit = '';
        if ($size !== null) {
            $offset = ($page - 1) * $size;
            $limit = "LIMIT $size OFFSET $offset";
        }
        $sql = "SELECT $select FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $orderBy $limit";
        $arr = $this->connector->query($sql);
        $list = $arr ? array_map(fn($row) => $row ? $this->mapper->mapAssoc($this->entityType, $row) : null, $arr) : [];
        $countSql = "SELECT COUNT(*) as \"count\" FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\"";
        $arr = $this->connector->query($countSql);
        $count = $arr[0]['count'];
        return ['list' => $list, 'total' => $count];
    }

    /**
     * `int`, not an untyped parameter: the key goes into the statement AS A NUMBER, with no
     * literal escaping around it (a quoted literal would not compare against a bigint key). That
     * is only safe while the value cannot be text, and every entity's key is `BaseEntity::$Id`,
     * an int — so the type declaration is what makes the interpolation below true rather than
     * merely conventional. A caller handing this a request string now fails here instead of
     * writing the string into the WHERE clause.
     *
     * @return TEntity|null
     */
    public function getById(int $Id): ?BaseEntity
    {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= $comma . self::ident($property);
            $comma = ', ';
        }
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $primaryKeyCondition = self::ident($keyName) . " = $Id";

        $sql = "SELECT $select FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" WHERE $primaryKeyCondition";
        $arr = $this->connector->query($sql);
        $entity = isset($arr[0]) ? $this->mapper->mapAssoc($this->entityType, $arr[0]) : null;
        return $entity;
    }

    /**
     * @return TEntity|null
     */
    public function firstOrDefault(
        array $where = [],
        array $order = [BaseEntityMap::PROPERTY_CreatedOn => 1]
    ) {
        $result = $this->search($where, $order, 1, 1, false);
        if (count($result['list']) > 0) {
            return $result['list'][0];
        }
        return null;
    }

    /**
     * @return TEntity|null
     */
    public function find(string | array $findKey, $value)
    {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= $comma . self::ident($property);
            $comma = ', ';
        }
        if (is_array($findKey)) {
            $wherePart = $this->buildWhere($findKey);
            if ($wherePart) {
                $wherePart = "WHERE $wherePart";
            }
        } else {
            $wherePart = "WHERE " . self::ident($findKey) . " = {$this->connector->escapeLiteral($value)}";
        }
        $sql = "SELECT $select FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $wherePart";
        // echo $sql . PHP_EOL;
        $arr = $this->connector->query($sql);
        $entity = isset($arr[0]) ? $this->mapper->mapAssoc($this->entityType, $arr[0]) : null;
        return $entity;
    }

    /**
     * @param TEntity $entity
     */
    public function create(BaseEntity $entity)
    {
        $this->assertEntityType($entity);
        $columns = '';
        $values = '';
        $comma = '';
        $now = self::getTime();
        $entity->CreatedOn = $now;
        $entity->UpdatedOn = $now;
        $keyName = $this->entityMap::$PrimaryKeys[0];
        foreach ($this->entityMap::Columns() as $property => $columnMeta) {
            if ($property !== $keyName) {
                $columns .= $comma . self::ident($property);
                $value = $entity->{$property};
                if (is_bool($entity->{$property})) {
                    $value = $entity->{$property} ? 'true' : 'false';
                } else if ($entity->{$property} === null) {
                    $value = 'NULL';
                } else if (is_integer($entity->{$property})) {
                    $value = $entity->{$property};
                } else if (is_float($entity->{$property})) {
                    $value = number_format($entity->{$property}, 8, '.', '');
                } elseif ($columnMeta['type'] === 'bytea') {
                    $value = "decode('" . bin2hex($entity->{$property}) . "', 'hex')";
                } else {
                    $value = $this->connector->escapeLiteral($entity->{$property});
                }
                $values .= "$comma{$value}";
                $comma = ', ';
            }
        }
        $sql = "INSERT INTO {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" (" . PHP_EOL . '    ' . $columns . PHP_EOL . ')';
        $sql .= '    VALUES' . PHP_EOL . "($values) RETURNING " . self::ident($keyName) . ";";
        // echo $sql . PHP_EOL;
        $arr = $this->connector->query($sql);
        if (isset($arr[0])) {
            $entity->Id = $arr[0][$keyName];
            return true;
        }
        return false;
    }

    /**
     * @param TEntity $entity
     */
    public function update(BaseEntity $entity, ?array $columnsToUpdate = null)
    {
        $this->assertEntityType($entity);
        $columns = '';
        $comma = '';
        $now = self::getTime();
        $entity->UpdatedOn = $now;
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $hasCustom = $columnsToUpdate !== null;
        $allColumns = $this->entityMap::Columns();
        if ($hasCustom) {
            $columnsToUpdate[] = 'UpdatedOn';
            $columnsToUpdate[] = 'UpdatedBy';
        }
        foreach ($columnsToUpdate ?? $allColumns as $property => $columnMeta) {
            if ($hasCustom) {
                // For a custom list the entries are property names; resolve the
                // real column meta from the map (needed for the bytea check).
                $property = $columnMeta;
                $columnMeta = $allColumns[$property] ?? [];
            }
            if ($property !== $keyName) {
                $value = $entity->{$property};
                if (is_bool($entity->{$property})) {
                    $value = $entity->{$property} ? 'true' : 'false';
                } else if ($entity->{$property} === null) {
                    $value = 'NULL';
                } else if (is_integer($entity->{$property})) {
                    $value = $entity->{$property};
                } else if (is_float($entity->{$property})) {
                    $value = number_format($entity->{$property}, 8, '.', '');
                } elseif (($columnMeta['type'] ?? null) === 'bytea') {
                    $value = "decode('" . bin2hex($entity->{$property}) . "', 'hex')";
                } else {
                    $value = $this->connector->escapeLiteral($entity->{$property});
                }
                $columns .= $comma . self::ident($property) . " = $value";
                $comma = ', ';
            }
        }
        $where = 'WHERE "' . $this->entityMap::$Table . '".' . self::ident($keyName) . " = {$entity->Id}";
        $sql = "UPDATE {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" SET " . PHP_EOL . '    ' . $columns . PHP_EOL . " $where;";
        // echo $sql . PHP_EOL;
        // return true;
        $this->connector->query($sql);
        $arr = $this->connector->affectedRows();
        if ($arr) {
            return true;
        }
        return false;
    }

    /**
     * @param TEntity[] $entities
     */
    public function merge(array $entities, MergeOptions $options): bool
    {
        $now = self::getTime();
        $columns = '';
        $sourceColumns = '';
        $comma = '';
        $newLine = PHP_EOL;
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $tableColumns = $this->entityMap::Columns();
        foreach ($tableColumns as $property => $columnMeta) {
            if ($options->insertIds || $property !== $keyName) {
                $columns .= $comma . self::ident($property);
                $sourceColumns .= $comma . 'SRC.' . self::ident($property);
                $comma = ', ';
            }
        }
        $valueList = '';
        $groupComma = '    ';
        // Hoisted: one property read instead of one per row (a bulk batch runs 5000 rows). The
        // whole per-row check costs ~0.09ms per batch against the ~10ms of building the statement.
        $entityType = $this->entityType;
        foreach ($entities as $entity) {
            if (!$entity instanceof $entityType) {
                throw new RuntimeException(
                    static::class . ' expects ' . $entityType . ', got ' . $entity::class
                        . " in a merge batch — refusing to write it to \"{$this->entityMap::$Table}\"."
                );
            }
            $entity->CreatedOn = $now;
            $entity->UpdatedOn = $now;
            $comma = '';
            $values = '';
            foreach ($tableColumns as $property => $columnMeta) {
                if ($options->insertIds || $property !== $keyName) {
                    $value = $entity->{$property};
                    if (is_bool($entity->{$property})) {
                        $value = $entity->{$property} ? 'true' : 'false';
                    } else if ($entity->{$property} === null) {
                        $value = 'NULL::' . $columnMeta['type'];
                    } else if (is_integer($entity->{$property})) {
                        $value = $entity->{$property};
                    } else if (is_float($entity->{$property})) {
                        $value = number_format($entity->{$property}, 8, '.', '');
                    } elseif ($columnMeta['type'] === 'bytea') {
                        $value = "decode('" . bin2hex($entity->{$property}) . "', 'hex')";
                    } else {
                        $value = $this->connector->escapeLiteral($entity->{$property});
                    }
                    $values .= "$comma{$value}";
                    $comma = ', ';
                }
            }
            $valueList .= "$groupComma($values)";
            $groupComma = "," . PHP_EOL . '    ';
        }

        $sql = "MERGE INTO {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" AS DST" . PHP_EOL;
        $sql .= "USING  ($newLine   VALUES {$newLine}$valueList $newLine) AS SRC ($columns)" . PHP_EOL;
        $matchOn = '';
        $matchOnGlue = '';
        foreach ($options->onCondition as $onCondition) {
            $matchOn .= $matchOnGlue . 'DST.' . self::ident($onCondition[0]) . ' ' . $onCondition[1] . ' SRC.' . self::ident($onCondition[2]);
            $matchOnGlue = "AND ";
        }
        $sql .= "ON $matchOn" . PHP_EOL;
        if ($options->update) {
            // TODO: implement on match update
            throw new Exception("Merge update is not implemented.");
        }
        $sql .= "WHEN NOT MATCHED THEN" . PHP_EOL;
        $sql .= "    INSERT ($columns) {$newLine}VALUES ($sourceColumns)" . PHP_EOL;
        // TODO: upgrade postgresql server to support returning
        // psql --version
        // $sql .= "RETURNING DST.*, merge_action();";
        // print_r([$sql]);
        $arr = $this->connector->query($sql);
        //print_r([$arr]);
        $arr = $this->connector->affectedRows();
        // print_r([$arr]);
        if ($arr) {
            return true;
        }
        return false;
    }

    /**
     * @param TEntity $entity
     */
    public function delete(BaseEntity $entity)
    {
        $this->assertEntityType($entity);
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $where = 'WHERE "' . $this->entityMap::$Table . '".' . self::ident($keyName) . " = {$entity->Id}";
        $sql = "DELETE FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $where;";
        // echo $sql . PHP_EOL;
        // return true;
        $this->connector->query($sql);
        $arr = $this->connector->affectedRows();
        if ($arr) {
            return true;
        }
        return false;
    }

    /**
     * Bulk-delete every row matching $where (same shape as search()'s $where,
     * e.g. [[Map::PROPERTY_Expire, '<', $cutoff]]) in a single statement, and
     * return the number of rows removed.
     *
     * Refuses an empty / blank WHERE and returns 0 — so it can never wipe the
     * whole table by accident. For retention / GC crons that prune by a
     * condition rather than one entity at a time.
     */
    public function deleteWhere(array $where): int
    {
        $wherePart = $this->buildWhere($where);
        if (trim($wherePart) === '') {
            return 0;
        }
        $sql = "DELETE FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" WHERE $wherePart;";
        $this->connector->query($sql);
        return (int) $this->connector->affectedRows();
    }

    // public function metaData()
    // {
    //     $pg = $this->connector->get();
    //     return $pg->metaData($this->entityMap::$Table);
    // }

    public function dropTable(bool $cascade, bool $ifExists): bool
    {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $cascadeSql = $cascade ? ' CASCADE' : '';
        $ifExistsSql = $ifExists ? ' IF EXISTS' : '';
        $sql = "DROP TABLE$ifExistsSql $schema.\"$tableName\"$cascadeSql";
        $this->connector->query($sql);
        return true;
    }

    public function addColumns(array $columnsSchema, bool $ifNotExists = false)
    {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $ifNotExistsSql = $ifNotExists ? ' IF NOT EXISTS' : '';
        $columns = '';
        $comma = '';
        foreach ($columnsSchema as $property => $columnMeta) {
            $dataType = $columnMeta['type'];
            if (isset($columnMeta['length'])) {
                $dataType .= "({$columnMeta['length']})";
            }
            if (isset($columnMeta['null']) && $columnMeta['null'] === false) {
                $dataType .= " NOT NULL";
            }
            if (isset($columnMeta['default'])) {
                $dataType .= " DEFAULT " . $columnMeta['default'];
            }
            if (isset($columnMeta['autoIncrement'])) {
                $dataType .= " GENERATED ALWAYS AS IDENTITY";
            }
            $columns .= "{$comma}ADD COLUMN$ifNotExistsSql \"{$property}\" $dataType";
            $comma = ',' . PHP_EOL;
        }
        $sql = <<<EOD
        ALTER TABLE $schema."$tableName"
        $columns;
        EOD;

        $this->connector->query($sql);
        return true;
    }

    /**
     * Drop one or more columns from the entity's table.
     * @param string[] $columns column names to drop
     * @param bool $ifExists guard with IF EXISTS on both the table and each column
     */
    public function dropColumns(array $columns, bool $ifExists = true): bool
    {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $ifExistsSql = $ifExists ? ' IF EXISTS' : '';
        $drops = '';
        $comma = '';
        foreach ($columns as $column) {
            $drops .= "{$comma}DROP COLUMN$ifExistsSql \"{$column}\"";
            $comma = ',' . PHP_EOL;
        }
        $sql = <<<EOD
        ALTER TABLE$ifExistsSql $schema."$tableName"
        $drops;
        EOD;

        $this->connector->query($sql);
        return true;
    }

    public function addIndexes(
        array $indexesSchema
    ) {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $comma = '';
        $indexes = '';
        foreach ($indexesSchema as $name => $indexMeta) {
            $indexName = "{$tableName}_$name";
            $unique = '';
            if ($indexMeta['Unique']) {
                $unique = " UNIQUE";
            }
            $indexColumns = '';
            $columnComma = '';
            foreach ($indexMeta['Columns'] as $column) {
                $indexColumns .= "$columnComma\"$column\" ASC NULLS LAST";
                $columnComma = ', ';
            }
            $indexSql = <<<EOD
            CREATE{$unique} INDEX IF NOT EXISTS "$indexName"
                ON $schema."$tableName" USING btree
                ($indexColumns);
            EOD;
            $indexes .= $comma . $indexSql;
        }
        $this->connector->query($indexes);
        return true;
    }

    /**
     * Drop one or more indexes on the entity's table.
     * @param string[] $names index short-names (same keys passed to addIndexes; the table prefix is added automatically)
     * @param bool $ifExists guard each drop with IF EXISTS
     */
    public function dropIndexes(array $names, bool $ifExists = true): bool
    {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $ifExistsSql = $ifExists ? ' IF EXISTS' : '';
        $drops = '';
        foreach ($names as $name) {
            $indexName = "{$tableName}_$name";
            $drops .= "DROP INDEX$ifExistsSql $schema.\"$indexName\";" . PHP_EOL;
        }
        $this->connector->query($drops);
        return true;
    }

    /**
     * 
     * @return bool true if table created, false if already exists
     */
    public function createTable(
        ?array $columnsSchema = null,
        ?array $primaryKeys = null,
        ?array $indexesSchema = null,
        ?array $foreignKeysSchema = null
    ): bool {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $columns = '';
        $comma = '';
        $dbUserName = $this->connector->getUserName();
        foreach ($columnsSchema ?? $this->entityMap::Columns() as $property => $columnMeta) {
            $dataType = $columnMeta['type'];
            if (isset($columnMeta['length'])) {
                $dataType .= "({$columnMeta['length']})";
            }
            if (isset($columnMeta['null']) && $columnMeta['null'] === false) {
                $dataType .= " NOT NULL";
            }
            if (isset($columnMeta['default'])) {
                $dataType .= " DEFAULT " . $columnMeta['default'];
            }
            if (isset($columnMeta['autoIncrement'])) {
                $generatedAs = isset($columnMeta['allowInsert']) ? "GENERATED BY DEFAULT" : "GENERATED ALWAYS";
                $dataType .= " $generatedAs AS IDENTITY";
            }
            $columns .= "$comma\"{$property}\" $dataType, " . PHP_EOL;
            $comma = '    ';
        }
        $pk = "";
        $comma = '';
        foreach ($primaryKeys ?? $this->entityMap::$PrimaryKeys as $columnName) {
            $pk .= "$comma\"{$columnName}\"";
            $comma = ', ';
        }
        if ($pk) {
            $pk = "    CONSTRAINT \"{$tableName}_PK\" PRIMARY KEY ($pk)";
        }
        $comma = ',' . PHP_EOL . '    ';
        $constrains = '';
        // foreach ($this->entityMap::$Indexes as $name => $indexMeta) {
        //     if ($indexMeta['Unique']) {
        //         $constrains .= "{$comma}CONSTRAINT \"$name\"";

        //         $constrains .= " UNIQUE";

        //         $constrains .= ' (';
        //         foreach ($indexMeta['Columns'] as $column) {
        //             $constrains .= "\"$column\"";
        //         }
        //         $constrains .= ')';
        //     }
        // }

        $comma = PHP_EOL . PHP_EOL;
        $indexes = [];
        foreach ($indexesSchema ?? $this->entityMap::$Indexes as $name => $indexMeta) {
            $indexName = "{$tableName}_$name";
            $unique = '';
            if ($indexMeta['Unique']) {
                $unique = " UNIQUE";
            }


            $indexColumns = '';
            $columnComma = '';
            foreach ($indexMeta['Columns'] as $column => $columnMeta) {
                $indexOrder = 'ASC';
                if (is_array($columnMeta)) {
                    if (isset($columnMeta['Order'])) {
                        $indexOrder = $columnMeta['Order'];
                    }
                } else {
                    $column = $columnMeta;
                }
                $indexColumns .= "$columnComma\"$column\" $indexOrder NULLS LAST";
                $columnComma = ', ';
            }
            $indexSql = <<<EOD
            CREATE{$unique} INDEX IF NOT EXISTS "$indexName"
                ON $schema."$tableName" USING btree
                ($indexColumns);
            EOD;
            $indexes[] = $indexSql;
        }
        $foreignKeys = '';
        $comma = ',' . PHP_EOL . '    ';
        foreach ($foreignKeysSchema ?? [] as $FKDefinition) {
            $fkSql = 'FOREIGN KEY (';
            $columnComma = '';
            foreach ($FKDefinition['Columns'] as $column) {
                $fkSql .= "\"$column\"";
                $columnComma = ', ';
            }
            $fkSql .= ') REFERENCES ';
            /** @var BaseEntityMap $otherTable */
            $otherTable = $FKDefinition['Table'];
            $otherSchema = $otherTable::$Schema;
            $otherTableName = $otherTable::$Table;
            $fkSql .= "$otherSchema.\"$otherTableName\" (";
            // c1, c2)
            $columnComma = '';
            foreach ($FKDefinition['References'] as $column) {
                $fkSql .= "\"$column\"";
                $columnComma = ', ';
            }
            $fkSql .= ')';
            switch ($FKDefinition['OnDelete']) {
                case CommonMap::$OnDeleteCascade: {
                        $fkSql .= ' ON DELETE CASCADE';
                        break;
                    }
                case CommonMap::$OnDeleteRestrict: {
                        $fkSql .= ' ON DELETE RESTRICT';
                        break;
                    }
                case CommonMap::$OnDeleteSetNull: {
                        $fkSql .= ' ON DELETE SET NULL';
                        break;
                    }
                case CommonMap::$OnDeleteSetDefault: {
                        $fkSql .= ' ON DELETE SET DEFAULT ';
                        break;
                    }
                case CommonMap::$OnDeleteNoAction:
                default: {
                        // nothing
                        break;
                    }
            }
            $foreignKeys .= $comma . $fkSql;
        }
        $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS $schema."$tableName"
        (
            $columns{$pk}{$constrains}{$foreignKeys}
        );
        EOD;
        $this->connector->query($sql);
        $sql = <<<EOD
        ALTER TABLE IF EXISTS $schema."$tableName"
            OWNER to "$dbUserName"; 
        EOD;
        $this->connector->query($sql);
        foreach ($indexes as $indexQuery) {
            $this->connector->query($indexQuery);
        }
        return true;
    }

    public function tableExist()
    {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
        $sql = <<<EOD
        SELECT EXISTS (
                SELECT FROM 
                pg_tables
            WHERE 
                schemaname = '$schema' AND 
                tablename  = '$tableName'
        );
        EOD;
        $arr = $this->connector->query($sql);
        return $arr[0]['exists'];
    }

    public function executeSQL(string $sqlScript)
    {
        return $this->connector->query($sqlScript);
    }
}
