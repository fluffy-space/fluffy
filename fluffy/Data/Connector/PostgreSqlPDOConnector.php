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
        if (!$this->isLostConnection($exception->errorInfo[0] ?? null, $exception->getMessage())) {
            return false;
        }
        // Nothing has run on this connection yet: it went stale while it sat in the pool, so the
        // statement never reached a live server and replaying it - write or not - is safe.
        // Once a statement has succeeded the connection died mid-request, and a write may have been
        // applied before it went away, so only reads may be replayed.
        return !$this->used || preg_match('/^\s*(SELECT|SHOW|EXPLAIN)\s/i', $query) === 1;
    }

    /**
     * True when an error means the CONNECTION is gone, as opposed to the statement being
     * rejected on a perfectly healthy one. A unique violation or an unknown column leaves the
     * connection usable, so those must not count here: treating them as death recycles the
     * connection for nothing, and the replacement is opened eagerly (see replaceInPool).
     *
     * Getting this wrong in the safe direction is cheap - a genuinely dead connection that
     * slips back into the pool throws on its next use, which is what the retry in query()
     * is there for. Getting it wrong in the other direction churns the pool under ordinary
     * application errors.
     */
    private function isLostConnection(?string $sqlState, string $message): bool
    {
        // Class 08 is "connection exception"; 57P01/02/03 are what PostgreSQL sends while it is
        // terminating a backend or is not accepting connections yet (restart, crash of a sibling
        // process, recovery). Swoole's text matcher recognises NONE of the 57P0x wordings.
        if ($sqlState !== null && (str_starts_with($sqlState, '08') || $sqlState === '57P01' || $sqlState === '57P02' || $sqlState === '57P03')) {
            return true;
        }
        // pdo_pgsql reports a socket that died between statements as a generic HY000, so the
        // SQLSTATE alone can't decide it - fall back to matching the driver's text. The matcher
        // only takes a Throwable, hence the throwaway exception.
        return DetectsLostConnections::causedByLostConnection(new PDOException($message));
    }

    /**
     * Drop the current connection without handing it back to the pool.
     */
    private function discard(): void
    {
        if (isset($this->pg)) {
            unset($this->pg);
            $this->used = false;
            $this->connectionPool->release();
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
            // errorInfo() carries the LAST operation on this handle, which is a plain SQL error far
            // more often than it is a dead socket - see isLostConnection for why the difference
            // matters. https://www.postgresql.org/docs/current/errcodes-appendix.html
            $errorInfo = $this->pg->errorInfo();
            $broken = $errorInfo[1] !== null
                && $this->isLostConnection($errorInfo[0] ?? null, (string) ($errorInfo[2] ?? ''));
            $pg = $this->pg;
            unset($this->pg);
            $this->used = false;
            if ($broken) {
                // Close the dead connection BEFORE freeing its slot, so the process never holds
                // two of PostgreSQL's connection slots for one pool entry. The pool opens the
                // replacement lazily on the next get(), which is also what keeps a server-side
                // blip from turning into every worker reconnecting in the same instant.
                unset($pg);
                $this->connectionPool->release();
            } else {
                $this->connectionPool->put($pg);
            }
            // echo (microtime(true) - $startedAt) . " PostgreSqlPDOConnector\n";
        }
    }
}
