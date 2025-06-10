<?php

namespace Fluffy\Swoole\Database;

use PDO;
use Swoole\Coroutine\PostgreSQL;

interface IPostgresqlPool
{
    /**
     * 
     * @return PostgreSQL|PDO 
     */
    function get();
    /**
     * 
     * @param mixed PostgreSQL|PDO 
     * @return mixed 
     */
    function put($connection);
}
