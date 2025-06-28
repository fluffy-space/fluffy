<?php

namespace Fluffy\Data\Connector;

use DotDi\Interfaces\IDisposable;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IPostgresqlPool;
use RuntimeException;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Coroutine\PostgreSQLStatement;

class PostgreSqlClientConnector implements IConnector, IDisposable
{
    /**
     * 
     * @var PostgreSQL
     */
    private $pg;
    /**
     * 
     * @var PostgreSQLStatement|false
     */
    private $lastStatement = false;

    public function __construct(private IPostgresqlPool $connectionPool, private Config $config) {}

    public function query(string $query, ?int $fetchMode = null): array
    {
        $pg = $this->get();
        $this->lastStatement = $pg->query($query);
        if (!$this->lastStatement) {
            throw new RuntimeException("{$pg->error} {$pg->errCode}");
        }
        $arr = $this->lastStatement->fetchAll(SW_PGSQL_ASSOC);
        return $arr;
    }

    public function escapeLiteral($value): string
    {
        return $this->get()->escapeLiteral($value);
    }

    public function affectedRows(): int
    {
        return $this->lastStatement->affectedRows();
    }

    /**
     * 
     * @return PostgreSQL 
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
