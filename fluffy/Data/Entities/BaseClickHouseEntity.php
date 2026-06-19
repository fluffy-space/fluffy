<?php

namespace Fluffy\Data\Entities;

/**
 * Base for ClickHouse-backed entities — the CH analog of BaseEntity, without the relational audit
 * columns (CreatedBy / UpdatedOn / UpdatedBy) that don't apply to append-only event tables.
 *
 * Mirrors BaseClickHouseEntityMap (Id + CreatedOn):
 *  - $CreatedOn is a microsecond epoch, auto-filled by BaseClickHouseRepository::insertBatch().
 *  - $Id is app-assigned (ClickHouse has no auto-increment) and optional — pure event tables can
 *    simply omit it from their map's Columns().
 * Defaults keep empty()/insert checks well-defined for unset rows.
 */
abstract class BaseClickHouseEntity
{
    public int $Id = 0;
    public int $CreatedOn = 0;
}
