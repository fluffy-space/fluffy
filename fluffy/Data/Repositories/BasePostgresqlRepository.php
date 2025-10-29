<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Connector\IConnector;
use Fluffy\Data\Entities\BaseEntity;
use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Mapper\IMapper;
use ReflectionClass;
use RuntimeException;
use Swoole\Coroutine\PostgreSQL;

class BasePostgresqlRepository
{
    /**
     * 
     * @param IMapper $mapper 
     * @param IConnector $connector 
     * @param BaseEntity|string $entityType 
     * @param BaseEntityMap|string $entityMap 
     * @return void 
     */
    public function __construct(private IMapper $mapper, private IConnector $connector, private string $entityType, private string $entityMap) {}

    static function getTime(): int
    {
        $timeOfDay = gettimeofday();
        return $timeOfDay['sec'] * 1000000 + $timeOfDay['usec'];
    }

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
            $select .= "$comma\"{$property}\"";
            $comma = ', ';
        }

        $orderGlue = "ORDER BY ";
        $orderBy = '';
        foreach ($order as $column => $orderWay) {
            $orderBy .= $orderGlue . "\"$column\"" . ($orderWay > 0 ? " ASC" : " DESC");
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
                $aggregateSql .= $dlm . $aggregateItem[1] . '("' . $aggregateItem[2] . '") as "' . $aggregateItem[0] . '"';
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

                $wherePart .= $whereGlue . "\"$column\" $operator $value";
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

    public function getList(
        int $page = 1,
        ?int $size = 10,
        ?string $ordering = BaseEntityMap::PROPERTY_CreatedOn,
        int $order = 1, // -1 DESC
    ) {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= "$comma\"{$property}\"";
            $comma = ', ';
        }
        $orderBy = $ordering !== null ? ("ORDER BY \"$ordering\"" . ($order > 0 ? " ASC" : " DESC")) : '';
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

    public function getById($Id): ?BaseEntity
    {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= "$comma\"{$property}\"";
            $comma = ', ';
        }
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $primaryKeyCondition = "\"{$keyName}\" = $Id";

        $sql = "SELECT $select FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" WHERE $primaryKeyCondition";
        $arr = $this->connector->query($sql);
        $entity = isset($arr[0]) ? $this->mapper->mapAssoc($this->entityType, $arr[0]) : null;
        return $entity;
    }

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

    public function find(string | array $findKey, $value)
    {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= "$comma\"{$property}\"";
            $comma = ', ';
        }
        if (is_array($findKey)) {
            $wherePart = $this->buildWhere($findKey);
            if ($wherePart) {
                $wherePart = "WHERE $wherePart";
            }
        } else {
            $wherePart = "WHERE \"{$findKey}\" = {$this->connector->escapeLiteral($value)}";
        }
        $sql = "SELECT $select FROM {$this->entityMap::$Schema}.\"{$this->entityMap::$Table}\" $wherePart";
        // echo $sql . PHP_EOL;
        $arr = $this->connector->query($sql);
        $entity = isset($arr[0]) ? $this->mapper->mapAssoc($this->entityType, $arr[0]) : null;
        return $entity;
    }

    public function create(BaseEntity $entity)
    {
        $columns = '';
        $values = '';
        $comma = '';
        $now = self::getTime();
        $entity->CreatedOn = $now;
        $entity->UpdatedOn = $now;
        $keyName = $this->entityMap::$PrimaryKeys[0];
        foreach ($this->entityMap::Columns() as $property => $columnMeta) {
            if ($property !== $keyName) {
                $columns .= "$comma\"{$property}\"";
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
        $sql .= '    VALUES' . PHP_EOL . "($values) RETURNING \"$keyName\";";
        // echo $sql . PHP_EOL;
        $arr = $this->connector->query($sql);
        if (isset($arr[0])) {
            $entity->Id = $arr[0][$keyName];
            return true;
        }
        return false;
    }

    public function update(BaseEntity $entity, ?array $columnsToUpdate = null)
    {
        $columns = '';
        $comma = '';
        $now = self::getTime();
        $entity->UpdatedOn = $now;
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $hasCustom = $columnsToUpdate !== null;
        if ($hasCustom) {
            $columnsToUpdate[] = 'UpdatedOn';
            $columnsToUpdate[] = 'UpdatedBy';
        }
        foreach ($columnsToUpdate ?? $this->entityMap::Columns() as $property => $columnMeta) {
            if ($hasCustom) {
                $property = $columnMeta;
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
                } elseif ($columnMeta['type'] === 'bytea') {
                    $value = "decode('" . bin2hex($entity->{$property}) . "', 'hex')";
                } else {
                    $value = $this->connector->escapeLiteral($entity->{$property});
                }
                $columns .= "$comma\"{$property}\" = $value";
                $comma = ', ';
            }
        }
        $where = "WHERE \"{$this->entityMap::$Table}\".\"$keyName\" = {$entity->Id}";
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
     * 
     * @param {BaseEntity[]} $entities 
     * @param array $onCondition 
     * @param bool $update 
     * @return void 
     */
    public function merge(array $entities, array $onCondition, bool $update = true)
    {
        $now = self::getTime();
        $columns = '';
        $sourceColumns = '';
        $comma = '';
        $newLine = PHP_EOL;
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $tableColumns = $this->entityMap::Columns();
        foreach ($tableColumns as $property => $columnMeta) {
            if ($property !== $keyName) {
                $columns .= "$comma\"{$property}\"";
                $sourceColumns .= "{$comma}SRC.\"{$property}\"";
                $comma = ', ';
            }
        }
        $valueList = '';
        $groupComma = '    ';
        foreach ($entities as $entity) {
            $entity->CreatedOn = $now;
            $entity->UpdatedOn = $now;
            $comma = '';
            $values = '';
            foreach ($tableColumns as $property => $columnMeta) {
                if ($property !== $keyName) {
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
        $matchOn = 'DST."' . $onCondition[0] . '" ' .  $onCondition[1] .  ' SRC."' . $onCondition[2] . '"';
        $sql .= "ON $matchOn" . PHP_EOL;
        if ($update) {
            // TODO: implement on match update
        }
        $sql .= "WHEN NOT MATCHED THEN" . PHP_EOL;
        $sql .= "    INSERT ($columns) {$newLine}VALUES ($sourceColumns)" . PHP_EOL;
        // TODO: upgrade postgresql server to support returning
        // psql --version
        // $sql .= "RETURNING DST.*, merge_action();";
        //print_r([$sql]);
        $arr = $this->connector->query($sql);
        //print_r([$arr]);
        $arr = $this->connector->affectedRows();
        print_r([$arr]);
    }

    public function delete(BaseEntity $entity)
    {
        $keyName = $this->entityMap::$PrimaryKeys[0];
        $where = "WHERE \"{$this->entityMap::$Table}\".\"$keyName\" = {$entity->Id}";
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

    // TODO: drop columns
    // -- ALTER TABLE IF EXISTS public."User" DROP COLUMN IF EXISTS "UserName";
    public function addColumns(array $columnsSchema)
    {
        $tableName = $this->entityMap::$Table;
        $schema = $this->entityMap::$Schema;
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
            $columns .= "{$comma}ADD COLUMN \"{$property}\" $dataType";
            $comma = ',' . PHP_EOL;
        }
        $sql = <<<EOD
        ALTER TABLE $schema."$tableName"
        $columns;
        EOD;

        $this->connector->query($sql);
        return true;
    }

    // TODO: drop indexes
    // -- DROP INDEX IF EXISTS public."User_UX_Email";
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
                $dataType .= " GENERATED ALWAYS AS IDENTITY";
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
