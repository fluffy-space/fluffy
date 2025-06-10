<?php

namespace Fluffy\Swoole\Database;

use Fluffy\Domain\Configuration\Config;
use RuntimeException;
use Swoole\ConnectionPool;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Database\PDOPool;

class PostgresPDOPool extends PDOPool implements IPostgresqlPool
{
}
