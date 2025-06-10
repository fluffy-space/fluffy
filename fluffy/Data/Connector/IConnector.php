<?php

namespace Fluffy\Data\Connector;

use Fluffy\Swoole\Database\IPostgresqlPool;
use Swoole\Coroutine\PostgreSQL;

interface IConnector
{
    /**
     * 
     * @return PostgreSQL|PDO  
     */
    function get();
    function getPool(): IPostgresqlPool;
    // SQL operations
    function query(string $query, ?int $fetchMode = null): array;
    function escapeLiteral($value): string;
    function affectedRows(): int;    
    function getUserName(): string;
}
