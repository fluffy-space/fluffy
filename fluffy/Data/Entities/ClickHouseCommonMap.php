<?php

namespace Fluffy\Data\Entities;

/**
 * ClickHouse column-type constants — the CH analog of CommonMap. Types are native ClickHouse
 * type names (rendered verbatim into DDL). Nullability is encoded in the type itself
 * (`Nullable(...)`), matching how ClickHouse declares columns.
 */
class ClickHouseCommonMap
{
    // Identity (ClickHouse has no auto-increment — Id is assigned by the app).
    public static array $Id = ['type' => 'UInt64', 'null' => false];

    public static array $UInt64 = ['type' => 'UInt64', 'null' => false];
    public static array $UInt32 = ['type' => 'UInt32', 'null' => false];
    public static array $Int64  = ['type' => 'Int64', 'null' => false];
    public static array $Int32  = ['type' => 'Int32', 'null' => false];
    public static array $Float64 = ['type' => 'Float64', 'null' => false];

    public static array $String     = ['type' => 'String', 'null' => false];
    public static array $StringNull = ['type' => 'Nullable(String)', 'null' => true];

    // Low-cardinality dictionary-encoded string — ideal for country/device/browser/os dimensions.
    public static array $LowCardinality = ['type' => 'LowCardinality(String)', 'null' => false];

    // Boolean as UInt8 (0/1).
    public static array $Bool = ['type' => 'UInt8', 'null' => false, 'default' => '0'];

    // Microsecond epoch stored as Int64 — matches Fluffy's MicroDateTime / BaseEntity int timestamps.
    public static array $MicroDateTime     = ['type' => 'Int64', 'null' => false];
    public static array $MicroDateTimeNull = ['type' => 'Nullable(Int64)', 'null' => true];

    // Native ClickHouse datetime types (alternative to MicroDateTime when you want CH date functions).
    public static array $DateTime64 = ['type' => "DateTime64(6, 'UTC')", 'null' => false];
    public static array $DateTime   = ['type' => "DateTime('UTC')", 'null' => false];
    public static array $Date       = ['type' => 'Date', 'null' => false];

    /**
     * The same column type with a compression codec attached, e.g.
     *   ClickHouseCommonMap::withCodec(ClickHouseCommonMap::$MicroDateTime, 'DoubleDelta, ZSTD(1)')
     *
     * Kept as a wrapper rather than baked into the type constants: the right codec depends on the
     * data's shape (DoubleDelta for near-monotonic timestamps, T64 for small-range integers,
     * ZSTD for text), and guessing on a shared constant would apply it to tables it doesn't suit.
     */
    public static function withCodec(array $meta, string $codec): array
    {
        $meta['codec'] = $codec;
        return $meta;
    }

    public static function LowCard(string $inner = 'String'): array
    {
        return ['type' => "LowCardinality($inner)", 'null' => false];
    }

    public static function Nullable(string $inner): array
    {
        return ['type' => "Nullable($inner)", 'null' => true];
    }
}
