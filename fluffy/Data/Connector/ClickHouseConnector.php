<?php

namespace Fluffy\Data\Connector;

use DotDi\Interfaces\IDisposable;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IClickHousePool;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\WaitGroup;
use Throwable;

/**
 * Scoped (per-request) ClickHouse connector over the HTTP interface. Borrows a keep-alive
 * coroutine HTTP client from the pool on first use and returns it on dispose() - same lifecycle
 * as PostgreSqlClientConnector. A transport error discards the slot; a ClickHouse application
 * error (HTTP 4xx/5xx) keeps the socket and throws.
 */
class ClickHouseConnector implements IClickHouseConnector, IDisposable
{
    /** gzip level for request bodies when compression is enabled (1 = fast, ~7x on JSONEachRow). */
    const COMPRESS_LEVEL = 1;

    private ?Client $client = null;
    private bool $broken = false;
    /** Set between beginSession() and endSession(): every request carries it (see beginSession). */
    private ?string $sessionId = null;
    private int $sessionTimeout = 60;
    /** Default for parallel(): at most this many tasks at once, so one caller never takes the whole pool. */
    const PARALLEL_LIMIT = 4;

    public function __construct(private IClickHousePool $pool, private Config $config) {}

    private function get(): Client
    {
        return $this->client ??= $this->pool->get();
    }

    /**
     * Run independent tasks concurrently, each on its OWN connector borrowed from the same pool, at
     * most $limit at a time. Each task is `fn(IClickHouseConnector $ch): mixed` and must use the
     * connector it is given, never this one. Returns the results under the task keys, in task order.
     *
     * Rules this relies on, checked on Swoole 6.2.2 (2026-11-17, scripts kept in the commit message):
     * - A Coroutine\Http\Client must never be used by two coroutines at once: Swoole raises
     *   "Socket#N has already been bound to another coroutine#M ... is not allowed". Hence one
     *   connector (one pooled client) per task, disposed in `finally` so the client always goes back
     *   to the pool (or is discarded after a transport error, as dispose() decides).
     * - An exception that leaves a coroutine is a PHP Fatal error that ends the process (a server
     *   worker, with every request on it). Swoole\Coroutine\batch() does not catch, so it is not
     *   used. Every task's Throwable is caught inside its coroutine and the first one, in task order,
     *   is rethrown here after all tasks have finished.
     * - WaitGroup::add() throws while another coroutine is in wait(), so every add() happens before
     *   wait(); done() runs in `finally` so a failed task cannot leave wait() blocked.
     * - The pool's get() waits at most WaitsForConnection::$waitSeconds and then throws; a drained
     *   pool becomes a task error, not a hang.
     *
     * Runs the tasks in order on THIS connector instead when concurrency is impossible or wrong:
     * outside a coroutine (Coroutine::getCid() is -1), or inside a session, whose temporary tables
     * live on this connection and which ClickHouse serves one query at a time.
     *
     * Concurrency only pays when the tasks wait on the network. ClickHouse already spreads one query
     * over every core (max_threads), so CPU-heavy queries side by side mostly compete: measure.
     *
     * @param array<string|int, callable(IClickHouseConnector): mixed> $tasks
     * @return array<string|int, mixed>
     */
    public function parallel(array $tasks, int $limit = self::PARALLEL_LIMIT): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('parallel(): $limit must be at least 1.');
        }
        if (count($tasks) < 2 || $limit === 1 || $this->sessionId !== null || Coroutine::getCid() < 0) {
            $results = [];
            foreach ($tasks as $key => $task) {
                $results[$key] = $task($this);
            }
            return $results;
        }

        $results = [];
        $errors = [];
        $slots = new Channel($limit);
        $group = new WaitGroup();
        foreach ($tasks as $key => $task) {
            $slots->push(true); // blocks this coroutine while $limit tasks are running
            $group->add();
            Coroutine::create(function () use ($key, $task, &$results, &$errors, $slots, $group): void {
                $connector = new self($this->pool, $this->config);
                try {
                    $results[$key] = $task($connector);
                } catch (Throwable $e) {
                    $errors[$key] = $e;
                } finally {
                    try {
                        $connector->dispose();
                    } finally {
                        $slots->pop();
                        $group->done();
                    }
                }
            });
        }
        $group->wait();

        foreach (array_keys($tasks) as $key) {
            if (isset($errors[$key])) {
                throw $errors[$key];
            }
        }
        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            $ordered[$key] = $results[$key] ?? null;
        }
        return $ordered;
    }

    public function execute(string $sql, array $params = [], array $settings = []): string
    {
        $client = $this->get();
        $compress = !empty($this->config->values['clickhouse']['compress']);

        // Server-side bound params: {name:Type} in SQL  ->  ?param_name=value
        $qs = [];
        foreach ($params as $k => $v) { $qs["param_$k"] = $v; }
        if ($compress) {
            $settings['enable_http_compression'] = 1; // make ClickHouse gzip the response too
        }
        foreach ($settings as $k => $v) { $qs[$k] = $v; }
        if ($this->sessionId !== null) {
            $qs['session_id'] = $this->sessionId;
            $qs['session_timeout'] = $this->sessionTimeout;
        }
        $path = '/' . (empty($qs) ? '' : ('?' . http_build_query($qs)));

        // With compression on, the pooled client carries a persistent Content-Encoding: gzip header,
        // so every request body must be gzipped; Swoole auto-inflates the gzip response.
        $body = $compress ? gzencode($sql, self::COMPRESS_LEVEL) : $sql;
        $client->post($path, $body);

        // Transport failure -> connection unusable, must not return to pool.
        if ($client->errCode !== 0 || $client->statusCode < 0) {
            $this->broken = true;
            throw new RuntimeException("ClickHouse transport error: {$client->errMsg} ({$client->errCode})");
        }
        // Application error -> socket still fine (keep-alive), surface the CH message.
        if ($client->statusCode !== 200) {
            $code = $client->headers['x-clickhouse-exception-code'] ?? '?';
            throw new RuntimeException("ClickHouse error [$code]: " . trim((string)$client->body));
        }
        return (string)$client->body;
    }

    public function query(string $sql, array $params = []): array
    {
        $body = $this->execute($sql, $params, ['default_format' => 'JSONEachRow']);
        $rows = [];
        foreach (explode("\n", trim($body)) as $line) {
            if ($line !== '') {
                $rows[] = json_decode($line, true);
            }
        }
        return $rows;
    }

    public function insert(string $table, array $rows, array $columns = []): int
    {
        if (!$rows) {
            return 0;
        }
        $cols = $columns ? ' (' . implode(', ', array_map(fn($c) => "`$c`", $columns)) . ')' : '';
        $data = [];
        foreach ($rows as $r) {
            $data[] = json_encode($r, JSON_UNESCAPED_UNICODE);
        }
        $sql = "INSERT INTO $table$cols FORMAT JSONEachRow\n" . implode("\n", $data);
        // async_insert (from config) coalesces small inserts server-side.
        $this->execute($sql, [], $this->config->values['clickhouse']['settings'] ?? []);
        return count($rows);
    }

    public function command(string $sql, array $params = []): void
    {
        $this->execute($sql, $params);
    }

    /**
     * Start a ClickHouse HTTP session: until endSession(), every request carries the same
     * session_id, so a temporary table created in it is visible to the queries that follow.
     *
     * Made for scoping many queries by one long list (a folder's link ids for a dashboard): upload
     * the list once with temporaryTable(), then `WHERE ShortUrlId IN scope_ids` in each query,
     * instead of sending the list with every query. ClickHouse runs one query at a time per
     * session, so the queries of a session must be sequential - as they are on this scoped
     * connector. The session (and its temporary tables) also expires on its own after
     * $timeoutSeconds of inactivity, so a request that dies midway leaves nothing behind for long.
     */
    public function beginSession(int $timeoutSeconds = 60): string
    {
        $this->sessionId = 'fluffy-' . bin2hex(random_bytes(12));
        $this->sessionTimeout = $timeoutSeconds;
        return $this->sessionId;
    }

    /** Leave the session; its temporary tables are dropped first, rather than left to expire. */
    public function endSession(array $temporaryTables = []): void
    {
        if ($this->sessionId === null) {
            return;
        }
        try {
            foreach ($temporaryTables as $table) {
                $this->execute('DROP TEMPORARY TABLE IF EXISTS ' . $this->identifier($table));
            }
        } finally {
            $this->sessionId = null;
        }
    }

    /**
     * Create an in-memory temporary table in the current session and fill it. `$structure` is a
     * column list ('id UInt64'); each row is a scalar (one column) or a list of values. Values go
     * as TSV in the request body, so the size is not limited like a URL parameter (a bound
     * Array(UInt64) fails past ~10k ids: ClickHouse caps a form field at 128KB).
     */
    public function temporaryTable(string $name, string $structure, array $rows): void
    {
        if ($this->sessionId === null) {
            throw new RuntimeException('ClickHouse temporary tables need a session: call beginSession() first.');
        }
        $table = $this->identifier($name);
        $this->execute("CREATE TEMPORARY TABLE IF NOT EXISTS $table ($structure) ENGINE = Memory");
        $this->execute("TRUNCATE TABLE $table");
        if (!$rows) {
            return;
        }
        $lines = [];
        foreach ($rows as $row) {
            $values = is_array($row) ? $row : [$row];
            $lines[] = implode("\t", array_map(fn($v) => str_replace(["\\", "\t", "\n"], ["\\\\", "\\t", "\\n"], (string)$v), $values));
        }
        $this->execute("INSERT INTO $table FORMAT TSV\n" . implode("\n", $lines));
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException("Not a valid ClickHouse table name: $name");
        }
        return "`$name`";
    }

    public function escapeLiteral($value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value) . "'";
    }

    public function getPool(): IClickHousePool
    {
        return $this->pool;
    }

    public function dispose()
    {
        $this->sessionId = null;
        if ($this->client !== null) {
            $this->pool->put($this->broken ? null : $this->client);
            $this->client = null;
            $this->broken = false;
        }
    }
}
