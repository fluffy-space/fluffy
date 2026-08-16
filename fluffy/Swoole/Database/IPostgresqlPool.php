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
    /**
     * Forget a connection the caller is discarding, WITHOUT opening a replacement now -
     * the next get() opens one lazily. See ReleasesPoolSlots for why put(null) is not that.
     */
    function release(): void;
}
