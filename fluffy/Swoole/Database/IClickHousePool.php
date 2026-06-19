<?php

namespace Fluffy\Swoole\Database;

use Swoole\Coroutine\Http\Client;

interface IClickHousePool
{
    /**
     * @return Client
     */
    function get();
    /**
     * @param Client|null $connection
     */
    function put($connection);
}
