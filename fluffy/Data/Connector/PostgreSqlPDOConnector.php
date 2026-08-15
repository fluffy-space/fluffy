<?php

namespace Fluffy\Data\Connector;

use DotDi\Interfaces\IDisposable;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IPostgresqlPool;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Swoole\Database\DetectsLostConnections;
use Throwable;

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
    /**
     * True once a statement reached the server on the current connection.
     *
     * @var bool
     */
    private $used = false;

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
        if (isset($this->pg)) {
            return $this->pg;
        }
        $pg = $this->connectionPool->get();
        if (!$pg) {
            throw new RuntimeException('PostgreSQL connection pool is closed or exhausted.');
        }
        $this->used = false;
        return $this->pg = $pg;
    }

    public function getPool(): IPostgresqlPool
    {
        return $this->connectionPool;
    }

    public function query(string $query, ?int $fetchMode = null): array
    {
        $fetchMode = $fetchMode ?? PDO::FETCH_ASSOC;
        try {
            return $this->run($query, $fetchMode);
        } catch (PDOException $exception) {
            // A PostgreSQL restart kills every connection sitting in the pool, so the next request
            // to pick one up gets "terminating connection due to administrator command". Swoole's
            // PDOProxy has its own reconnect for this, but it only fires when the handle reports no
            // open transaction - and pdo_pgsql reports a dead connection as PQTRANS_UNKNOWN, which
            // PDO::inTransaction() maps to true, so the built-in retry never runs for pgsql.
            // Replace the dead connection and run the statement once more on a fresh one.
            if (!$this->canRetry($exception, $query)) {
                throw $exception;
            }
            $this->discard();
            echo '[Server] PostgreSQL connection lost, retrying on a new connection.' . PHP_EOL;
            return $this->run($query, $fetchMode);
        }
    }

    private function run(string $query, int $fetchMode): array
    {
        $pdo = $this->get();
        $this->lastStatement = $pdo->query($query, $fetchMode);
        if (!$this->lastStatement) {
            $errorMessage = implode(' ', $pdo->errorInfo());
            $errorCode = $pdo->errorCode();
            throw new RuntimeException("$errorMessage $errorCode");
        }
        // The server has executed the statement by now, even if fetching the rows fails below.
        $this->used = true;
        return $this->lastStatement->fetchAll($fetchMode);
    }

    private function canRetry(PDOException $exception, string $query): bool
    {
        if (!DetectsLostConnections::causedByLostConnection($exception)) {
            return false;
        }
        // Nothing has run on this connection yet: it went stale while it sat in the pool, so the
        // statement never reached a live server and replaying it - write or not - is safe.
        // Once a statement has succeeded the connection died mid-request, and a write may have been
        // applied before it went away, so only reads may be replayed.
        return !$this->used || preg_match('/^\s*(SELECT|SHOW|EXPLAIN)\s/i', $query) === 1;
    }

    /**
     * Drop the current connection without handing it back to the pool.
     */
    private function discard(): void
    {
        if (isset($this->pg)) {
            unset($this->pg);
            $this->used = false;
            $this->replaceInPool();
        }
    }

    /**
     * Tell the pool its connection is gone; it opens a replacement straight away.
     */
    private function replaceInPool(): void
    {
        try {
            $this->connectionPool->put(null);
        } catch (Throwable $throwable) {
            // PostgreSQL is still unreachable. The pool slot is free either way, so the next get()
            // makes the connection attempt instead - don't let this escape into request disposal.
            echo '[Server] PostgreSQL connection could not be replaced: ' . $throwable->getMessage() . PHP_EOL;
        }
    }

    public function affectedRows(): int
    {
        return $this->lastStatement?->rowCount() ?? 0;
    }

    public function escapeLiteral($value): string
    {
        // Swoole's PDOProxy is strict-typed: quote() rejects non-strings, so
        // scalars must be handled here (int/float are safe SQL literals as-is).
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'NULL';
        }
        return $this->get()->quote((string) $value, PDO::PARAM_STR);
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
            $pg = $this->pg;
            unset($this->pg);
            $this->used = false;
            if ($broken) {
                $this->replaceInPool();
            } else {
                $this->connectionPool->put($pg);
            }
            // echo (microtime(true) - $startedAt) . " PostgreSqlPDOConnector\n";
        }
    }
}
