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

    /** Start an HTTP session: later requests share temporary tables. Returns the session id. */
    function beginSession(int $timeoutSeconds = 60): string;

    /** Leave the session, dropping the named temporary tables first. */
    function endSession(array $temporaryTables = []): void;

    /** In-memory temporary table in the current session, filled from $rows (scalars or value lists). */
    function temporaryTable(string $name, string $structure, array $rows): void;

    /** ClickHouse-safe single-quoted string literal (for baked-in values). */
    function escapeLiteral($value): string;

    function getPool(): IClickHousePool;
}
