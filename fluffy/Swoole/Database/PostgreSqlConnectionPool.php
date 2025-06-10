<?php

namespace Fluffy\Swoole\Database;

use Fluffy\Domain\Configuration\Config;
use RuntimeException;
use Swoole\ConnectionPool;
use Swoole\Coroutine\PostgreSQL;

class PostgreSqlConnectionPool extends ConnectionPool {}
