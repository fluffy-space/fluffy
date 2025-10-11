<?php

namespace Fluffy\Data\Connector;

use DotDi\Interfaces\IDisposable;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IPostgresqlPool;
use PDO;
use PDOStatement;
use RuntimeException;

class PostgreSqlPDOConnector implements IConnector, IDisposable
{
    /**
     * 
     * @var PDO
     */
    private $pg;
    /**
     * 
     * @var PDOStatement|false
     */
    private $lastStatement = null;

    public function __construct(private IPostgresqlPool $connectionPool, private Config $config) {}

    public function getUserName(): string
    {
        return $this->config->values['postgresql']['user'];
    }

    /**
     * 
     * @return PDO 
     */
    public function get()
    {
        return $this->pg ?? ($this->pg = $this->connectionPool->get());
    }

    public function getPool(): IPostgresqlPool
    {
        return $this->connectionPool;
    }

    public function query(string $query, ?int $fetchMode = null): array
    {
        $pdo = $this->get();
        $fetchMode = $fetchMode ?? PDO::FETCH_ASSOC;
        $this->lastStatement = $pdo->query($query, $fetchMode);
        if (!$this->lastStatement) {
            $errorMessage = implode(' ', $pdo->errorInfo());
            $errorCode = $pdo->errorCode();
            throw new RuntimeException("$errorMessage $errorCode");
        }
        return $this->lastStatement->fetchAll($fetchMode);
    }

    public function affectedRows(): int
    {
        return $this->lastStatement?->rowCount() ?? 0;
    }

    public function escapeLiteral($value): string
    {
        return $this->get()->quote($value, PDO::PARAM_STR);
    }

    public function dispose()
    {
        if (isset($this->pg)) {
            // $startedAt = microtime(true);
            $broken = $this->pg->errorInfo()[1] !== null;
            // echo "PUT connection $broken" . $this->pg->errorCode() .  PHP_EOL;
            // Possible to improve, see https://www.postgresql.org/docs/current/errcodes-appendix.html
            // var_dump($this->pg->errorInfo());
            // if ($broken) {
            //     var_dump($this->pg->errorInfo());
            // }
            $this->connectionPool->put($broken ? null : $this->pg);
            unset($this->pg);
            // echo (microtime(true) - $startedAt) . " PostgreSqlPDOConnector\n";
        }
    }
}
