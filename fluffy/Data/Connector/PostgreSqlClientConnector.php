<?php

namespace Fluffy\Data\Connector;

use Fluffy\Swoole\Database\PostgreSQLPool;
use DotDi\Interfaces\IDisposable;
use Exception;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IPostgresqlPool;
use Fluffy\Swoole\Database\PostgreSqlConnectionPool;
use PDO;
use Swoole\ConnectionPool;
use Swoole\Coroutine\PostgreSQL;

class PostgreSqlClientConnector implements IConnector, IDisposable
{
    /**
     * 
     * @var PostgreSQL|PDO
     */
    private $pg;

    public function __construct(private IPostgresqlPool $connectionPool, private Config $config)
    {
    }

    /**
     * 
     * @return Swoole\Coroutine\PostgreSQL|PDO 
     */
    public function get()
    {
        return $this->pg ?? ($this->pg = $this->connectionPool->get());
    }

    public function getPool(): IPostgresqlPool
    {
        return $this->connectionPool;
    }

    public function dispose()
    {
        if (isset($this->pg)) {
            $broken = $this->pg->error && !$this->pg->resultDiag['sqlstate'];
            // echo "PUT connection $broken" . PHP_EOL;
            $this->connectionPool->put($broken ? null : $this->pg);
            unset($this->pg);
        }
    }

        public function getUserName(): string
    {
        return $this->config->values['postgresql']['user'];
    }
}
