<?php

namespace Fluffy\Swoole\Database;

use Fluffy\Domain\Configuration\Config;
use RuntimeException;
use Swoole\ConnectionPool;
use Swoole\Coroutine\PostgreSQL;

class PostgreSQLPool extends PostgreSqlConnectionPool implements IPostgresqlPool
{
    public function __construct(private Config $config, int $size = self::DEFAULT_SIZE)
    {
        
        parent::__construct(function () {
            $connection = new PostgreSQL();
            $pgConfig = $this->config->values['postgresql'];
            $conn = $connection->connect("host={$pgConfig['host']} port={$pgConfig['port']} dbname={$pgConfig['dbname']} user={$pgConfig['user']} password={$pgConfig['password']}");
            if (!$conn) {
                throw new RuntimeException("Failed to connect to DB: {$connection->error} code {$connection->errCode}");
            }
            return $connection;
        }, $size);
    }
}
