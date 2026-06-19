<?php

namespace Fluffy\Data\Repositories;

use Fluffy\Data\Connector\IClickHouseConnector;
use Fluffy\Data\Entities\BaseEntity;
use Fluffy\Data\Entities\BaseClickHouseEntityMap;
use Fluffy\Data\Mapper\IMapper;

/**
 * Base repository for ClickHouse-backed entities — the CH analog of BasePostgresqlRepository.
 * Injected with entityType/entityMap via #[Inject], same as the Postgres repos:
 *
 *   #[Inject(['entityType' => ClickEventEntity::class, 'entityMap' => ClickEventEntityMap::class])]
 *   class ClickEventRepository extends BaseClickHouseRepository {}
 *
 * Differences from Postgres baked in here: no RETURNING (Id is app-assigned), no FK, MergeTree DDL
 * (ENGINE / ORDER BY / PARTITION BY / TTL), bulk INSERT via JSONEachRow, and update/delete as
 * ALTER … mutations (async, heavy — not for hot paths).
 */
class BaseClickHouseRepository
{
    /**
     * @param BaseEntity|string $entityType
     * @param BaseClickHouseEntityMap|string $entityMap
     */
    public function __construct(
        private IMapper $mapper,
        private IClickHouseConnector $connector,
        private string $entityType,
        private string $entityMap
    ) {}

    static function getTime(): int
    {
        $t = gettimeofday();
        return $t['sec'] * 1000000 + $t['usec'];
    }

    /** `db`.`table` (db omitted when the map relies on the connection default database). */
    private function table(): string
    {
        $db = $this->entityMap::$Database;
        return ($db !== '' ? "`$db`." : '') . "`{$this->entityMap::$Table}`";
    }

    // ------------------------------------------------------------------ reads

    /**
     * @param array $where  flat AND-ed conditions: [['Col','=',$v], ['Col','in',[...]], ...]
     * @param array $order  ['Col' => 1|-1]  (1 = ASC, -1 = DESC)
     */
    public function search(
        array $where = [],
        array $order = [BaseClickHouseEntityMap::PROPERTY_CreatedOn => -1],
        int $page = 1,
        ?int $size = 100,
        bool $returnCount = true
    ): array {
        $select = '';
        $comma = '';
        foreach ($this->entityMap::Columns() as $property => $_) {
            $select .= "$comma`$property`";
            $comma = ', ';
        }

        $wherePart = $this->buildWhere($where);
        $whereSql = $wherePart === '' ? '' : "WHERE $wherePart";

        $orderBy = '';
        $glue = 'ORDER BY ';
        foreach ($order as $column => $way) {
            $orderBy .= $glue . "`$column`" . ($way > 0 ? ' ASC' : ' DESC');
            $glue = ', ';
        }

        $limit = '';
        if ($size !== null) {
            $offset = ($page - 1) * $size;
            $limit = "LIMIT $size OFFSET $offset";
        }

        $rows = $this->connector->query("SELECT $select FROM {$this->table()} $whereSql $orderBy $limit");
        $list = array_map(fn($row) => $this->mapper->mapAssoc($this->entityType, $row), $rows);

        $result = ['list' => $list];
        if ($returnCount) {
            $count = $this->connector->query("SELECT count() AS `count` FROM {$this->table()} $whereSql");
            $result['total'] = (int)($count[0]['count'] ?? 0);
        }
        return $result;
    }

    public function firstOrDefault(array $where = [], array $order = [BaseClickHouseEntityMap::PROPERTY_CreatedOn => -1]): ?BaseEntity
    {
        $res = $this->search($where, $order, 1, 1, false);
        return $res['list'][0] ?? null;
    }

    public function getById($id): ?BaseEntity
    {
        return $this->firstOrDefault([[BaseClickHouseEntityMap::PROPERTY_Id, '=', $id]]);
    }

    public function count(array $where = []): int
    {
        $wherePart = $this->buildWhere($where);
        $whereSql = $wherePart === '' ? '' : "WHERE $wherePart";
        $count = $this->connector->query("SELECT count() AS `count` FROM {$this->table()} $whereSql");
        return (int)($count[0]['count'] ?? 0);
    }

    private function buildWhere(array $where): string
    {
        $sql = '';
        $glue = '';
        foreach ($where as [$column, $op, $value]) {
            $sql .= $glue . "`$column` " . strtoupper($op) . ' ' . $this->literal($value);
            $glue = ' AND ';
        }
        return $sql;
    }

    private function literal($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif ($value === null) {
            return 'NULL';
        } elseif (is_int($value)) {
            return (string)$value;
        } elseif (is_float($value)) {
            return number_format($value, 8, '.', '');
        } elseif (is_array($value)) {
            return '(' . implode(', ', array_map(fn($x) => $this->literal($x), $value)) . ')';
        }
        return $this->connector->escapeLiteral($value);
    }

    // ------------------------------------------------------------------ writes

    /** Single-row insert. ClickHouse has no auto-increment — set $entity->Id yourself. */
    public function create(BaseEntity $entity): bool
    {
        return $this->insertBatch([$entity]) > 0;
    }

    /** Bulk append (one HTTP round-trip via JSONEachRow). The fast path for analytics ingest. */
    public function insertBatch(array $entities): int
    {
        if (!$entities) {
            return 0;
        }
        $columns = array_keys($this->entityMap::Columns());
        $now = self::getTime();
        $rows = [];
        foreach ($entities as $entity) {
            if (property_exists($entity, 'CreatedOn') && empty($entity->CreatedOn)) {
                $entity->CreatedOn = $now;
            }
            $row = [];
            foreach ($columns as $property) {
                $row[$property] = $entity->{$property} ?? null;
            }
            $rows[] = $row;
        }
        return $this->connector->insert($this->table(), $rows, $columns);
    }

    /** Mutation: ALTER TABLE … UPDATE. Async + heavy; for corrections, not the hot path. */
    public function update(BaseEntity $entity, ?array $columnsToUpdate = null): bool
    {
        $columns = $columnsToUpdate ?? array_keys($this->entityMap::Columns());
        $set = '';
        $comma = '';
        foreach ($columns as $property) {
            if ($property === BaseClickHouseEntityMap::PROPERTY_Id) {
                continue;
            }
            $set .= "$comma`$property` = " . $this->literal($entity->{$property} ?? null);
            $comma = ', ';
        }
        $this->connector->command(
            "ALTER TABLE {$this->table()} UPDATE $set WHERE `Id` = " . $this->literal($entity->Id)
        );
        return true;
    }

    /** Mutation: ALTER TABLE … DELETE. Async + heavy; prefer TTL/partition DROP for retention. */
    public function delete(BaseEntity $entity): bool
    {
        $this->connector->command(
            "ALTER TABLE {$this->table()} DELETE WHERE `Id` = " . $this->literal($entity->Id)
        );
        return true;
    }

    public function deleteWhere(array $where): bool
    {
        $wherePart = $this->buildWhere($where);
        if ($wherePart === '') {
            return false;
        }
        $this->connector->command("ALTER TABLE {$this->table()} DELETE WHERE $wherePart");
        return true;
    }

    public function truncate(): bool
    {
        $this->connector->command("TRUNCATE TABLE IF EXISTS {$this->table()}");
        return true;
    }

    // ------------------------------------------------------------------ DDL

    /**
     * Emit CREATE TABLE from the map: columns + ENGINE + ORDER BY + optional PARTITION BY / TTL /
     * data-skipping indexes. No PRIMARY KEY / FOREIGN KEY (ClickHouse has neither).
     */
    /** One column's DDL fragment: `name` Type [DEFAULT expr]. Shared by createTable + ALTER. */
    private function columnDdl(string $property, array $meta): string
    {
        $ddl = "`$property` {$meta['type']}";
        if (isset($meta['default'])) {
            $ddl .= " DEFAULT {$meta['default']}";
        }
        return $ddl;
    }

    public function createTable(): bool
    {
        $map = $this->entityMap;
        $lines = [];
        foreach ($map::Columns() as $property => $meta) {
            $lines[] = '    ' . $this->columnDdl($property, $meta);
        }
        foreach ($map::$Indexes as $name => $ix) {
            $granularity = $ix['Granularity'] ?? 1;
            $lines[] = "    INDEX `$name` {$ix['Expression']} TYPE {$ix['Type']} GRANULARITY $granularity";
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table()}\n(\n"
            . implode(",\n", $lines) . "\n)"
            . "\nENGINE = {$map::$Engine}";
        if ($map::$PartitionBy !== null) {
            $sql .= "\nPARTITION BY {$map::$PartitionBy}";
        }
        $sql .= "\nORDER BY (" . implode(', ', array_map(fn($c) => "`$c`", $map::$OrderBy)) . ")";
        if ($map::$Ttl !== null) {
            $sql .= "\nTTL {$map::$Ttl}";
        }

        $this->connector->command($sql);
        return true;
    }

    public function dropTable(bool $ifExists = true): bool
    {
        $exists = $ifExists ? 'IF EXISTS ' : '';
        $this->connector->command("DROP TABLE {$exists}{$this->table()}");
        return true;
    }

    public function tableExists(): bool
    {
        $r = $this->connector->query("EXISTS TABLE {$this->table()}");
        return (int)($r[0]['result'] ?? 0) === 1;
    }

    // ------------------------------------------------------------------ ALTER (for migrations)
    // ADD/DROP/RENAME COLUMN and index ops are lightweight metadata changes (synchronous, instant).
    // MODIFY COLUMN that changes the stored type rewrites parts in the background. ClickHouse has no
    // transactions, so keep each migration step single-statement and make down() idempotent (IF EXISTS).

    /** @param array $columnsSchema  ['Col' => ClickHouseCommonMap::$X, ...] (same shape as Columns()) */
    public function addColumns(array $columnsSchema, bool $ifNotExists = true): bool
    {
        $guard = $ifNotExists ? 'IF NOT EXISTS ' : '';
        $actions = [];
        foreach ($columnsSchema as $property => $meta) {
            $actions[] = "ADD COLUMN {$guard}" . $this->columnDdl($property, $meta);
        }
        $this->connector->command("ALTER TABLE {$this->table()} " . implode(', ', $actions));
        return true;
    }

    public function dropColumns(array $columns, bool $ifExists = true): bool
    {
        $guard = $ifExists ? 'IF EXISTS ' : '';
        $actions = array_map(fn($c) => "DROP COLUMN {$guard}`$c`", $columns);
        $this->connector->command("ALTER TABLE {$this->table()} " . implode(', ', $actions));
        return true;
    }

    public function modifyColumn(string $column, array $meta): bool
    {
        $this->connector->command("ALTER TABLE {$this->table()} MODIFY COLUMN " . $this->columnDdl($column, $meta));
        return true;
    }

    public function renameColumn(string $from, string $to): bool
    {
        $this->connector->command("ALTER TABLE {$this->table()} RENAME COLUMN `$from` TO `$to`");
        return true;
    }

    public function addIndex(string $name, string $expression, string $type, int $granularity = 1, bool $materialize = true): bool
    {
        $this->connector->command("ALTER TABLE {$this->table()} ADD INDEX IF NOT EXISTS `$name` $expression TYPE $type GRANULARITY $granularity");
        if ($materialize) {
            // A new skip index only covers future inserts until materialized over existing parts.
            $this->connector->command("ALTER TABLE {$this->table()} MATERIALIZE INDEX IF EXISTS `$name`");
        }
        return true;
    }

    public function dropIndex(string $name, bool $ifExists = true): bool
    {
        $guard = $ifExists ? 'IF EXISTS ' : '';
        $this->connector->command("ALTER TABLE {$this->table()} DROP INDEX {$guard}`$name`");
        return true;
    }

    /** Set the table TTL (retention), or pass null to remove it. */
    public function modifyTtl(?string $ttl): bool
    {
        $this->connector->command($ttl === null
            ? "ALTER TABLE {$this->table()} REMOVE TTL"
            : "ALTER TABLE {$this->table()} MODIFY TTL $ttl");
        return true;
    }

    /** Extend the sorting key (ClickHouse only allows appending columns to an existing ORDER BY). */
    public function modifyOrderBy(array $orderBy): bool
    {
        $this->connector->command("ALTER TABLE {$this->table()} MODIFY ORDER BY (" . implode(', ', array_map(fn($c) => "`$c`", $orderBy)) . ")");
        return true;
    }

    /** Raw escape hatch for any ALTER action not covered above. */
    public function alter(string $action): bool
    {
        $this->connector->command("ALTER TABLE {$this->table()} $action");
        return true;
    }
}
