<?php

namespace Fluffy\Data\Entities;

/**
 * Base map for ClickHouse-backed entities — the CH analog of BaseEntityMap. Instead of a primary
 * key + foreign keys (ClickHouse has neither), a MergeTree table is described by its ENGINE, a
 * sorting key (ORDER BY — the closest analog to a PK), an optional PARTITION BY, and an optional
 * TTL for retention. `Columns()` remains the source of truth for the column set.
 */
abstract class BaseClickHouseEntityMap
{
    public const PROPERTY_Id = 'Id';
    public const PROPERTY_CreatedOn = 'CreatedOn';

    public static string $Table = '';

    /** ClickHouse database; '' = use the connection's default (X-ClickHouse-Database header). */
    public static string $Database = '';

    /** Table engine — MergeTree family by default (ReplacingMergeTree / SummingMergeTree / etc.). */
    public static string $Engine = 'MergeTree';

    /** Sorting key (ORDER BY). REQUIRED by MergeTree; localizes per-key range scans. */
    public static array $OrderBy = ['Id'];

    /** Optional PARTITION BY expression, e.g. 'toYYYYMM(CreatedOn)' — enables DROP-partition retention. */
    public static ?string $PartitionBy = null;

    /** Optional table TTL, e.g. "toDateTime(CreatedOn) + INTERVAL 90 DAY" — auto-prunes old rows. */
    public static ?string $Ttl = null;

    /**
     * Optional data-skipping indexes:
     *   'IX_Name' => ['Expression' => 'Country', 'Type' => 'minmax', 'Granularity' => 1]
     */
    public static array $Indexes = [];

    public static array $Ignore = [];

    public static function Columns(): array
    {
        return [
            'Id' => ClickHouseCommonMap::$Id,
            'CreatedOn' => ClickHouseCommonMap::$MicroDateTime,
        ];
    }
}
