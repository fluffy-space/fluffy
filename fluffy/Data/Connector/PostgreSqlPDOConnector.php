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
    /** How many connections this worker refused to pool because the session was not idle. */
    private static int $notIdleOnRelease = 0;

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
            // A connection that still holds an unconsumed result answers every new statement with
            // "another command is already in progress". Our statement did NOT reach the server, so
            // replaying it is safe whatever it is, including a write, and the connection is dropped
            // rather than handed back. Seen at roughly one request in 400 under sustained parallel
            // load; the retry clears most of them, and the ~40% that still fail land on a second
            // connection in the same state, which is why this is a mitigation and not a cure - see
            // docs/verify-rework-plan.md section 8.
            if ($this->isBusyConnection($exception->getMessage())) {
                $this->discard();
                return $this->run($query, $fetchMode);
            }
            if (!$this->canRetry($exception, $query)) {
                throw $exception;
            }
            $this->discard();
            echo '[Server] PostgreSQL connection lost, retrying on a new connection.' . PHP_EOL;
            return $this->run($query, $fetchMode);
        }
    }

    /**
     * True when the connection still has an unconsumed result from an EARLIER statement.
     *
     * Distinct from a lost connection: the socket is fine, the session is just mid-command, so
     * DetectsLostConnections does not recognise it and the built-in retry never fires.
     */
    private function isBusyConnection(string $message): bool
    {
        return str_contains($message, 'another command is already in progress');
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
        try {
            return $this->lastStatement->fetchAll($fetchMode);
        } finally {
            // Release the RESULT here, not on the garbage collector's schedule — otherwise the
            // connection can go back into the pool with rows still pending and the next caller gets
            // "another command is already in progress". In a finally because a throwing fetch leaves
            // exactly that state behind. The statement OBJECT stays: affectedRows() reads its
            // rowCount, and dispose() is what finally lets it go.
            $this->releaseResult();
        }
    }

    /**
     * Finish with the current statement: drain what is left of its result and let it go.
     *
     * `closeCursor()` releases the result on the SERVER, which is what keeps a pooled connection
     * usable by the next caller.
     */
    private function releaseResult(): void
    {
        if ($this->lastStatement instanceof PDOStatement) {
            try {
                $this->lastStatement->closeCursor();
            } catch (PDOException $e) {
                // Already closed, or the connection is gone — either way there is nothing to release
                // and dispose() decides what happens to the connection.
            }
        }
    }

    /** releaseResult(), and let the statement object go too — only safe once nothing reads it. */
    private function closeStatement(): void
    {
        $this->releaseResult();
        $this->lastStatement = null;
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
            // FIRST, while this connector still owns the connection: drop the statement. Letting it
            // die with the connector means its destructor runs at the garbage collector's
            // convenience — by which time the connection is serving somebody else, and closing a
            // cursor on it makes THEIR next statement fail.
            $this->closeStatement();

            $errorInfo = $this->pg->errorInfo();
            $broken = $errorInfo[1] !== null
                && $this->isLostConnection($errorInfo[0] ?? null, (string) ($errorInfo[2] ?? ''));
            // A connection mid-command must never go back into the pool. Nothing in this codebase
            // opens a transaction, so inTransaction() true means the session is not idle — either a
            // result was left pending or the handle is in the unknown state pdo_pgsql reports for a
            // dead socket. Both are reasons to drop it rather than pass the problem on.
            if (!$broken && $this->pg->inTransaction()) {
                $broken = true;
                self::$notIdleOnRelease++;
                echo '[Server] PostgreSQL connection was not idle at release (' . self::$notIdleOnRelease
                    . ' so far) — dropped instead of pooled.' . PHP_EOL;
            }
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
