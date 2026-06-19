<?php

namespace Fluffy\Data\Connector;

use Fluffy\Swoole\Database\IClickHousePool;

interface IClickHouseConnector
{
    /** SELECT → list of assoc rows. Params bind server-side via {name:Type}. */
    function query(string $sql, array $params = []): array;

    /** Bulk INSERT of assoc rows as JSONEachRow. Returns row count sent. */
    function insert(string $table, array $rows, array $columns = []): int;

    /** DDL / DML with no result set (CREATE, ALTER, OPTIMIZE, TRUNCATE, INSERT…SELECT). */
    function command(string $sql, array $params = []): void;

    /** Low-level: full statement (incl. inline data) in body; returns raw response body. */
    function execute(string $sql, array $params = [], array $settings = []): string;

    /** ClickHouse-safe single-quoted string literal (for baked-in values). */
    function escapeLiteral($value): string;

    function getPool(): IClickHousePool;
}
