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
    /** Inside parallel(): the coroutine that called it keeps $client; each task coroutine gets its own. */
    private ?int $ownerCid = null;
    /** @var array<int, Client> task coroutine id => its borrowed client */
    private array $taskClients = [];
    /** @var array<int, bool> task coroutine id => its client hit a transport error */
    private array $taskBroken = [];

    /** At most this many queries of one parallel() at once, so one report never takes the whole pool. */
    const PARALLEL_LIMIT = 4;

    public function __construct(private IClickHousePool $pool, private Config $config) {}

    private function get(): Client
    {
        $cid = $this->taskCid();
        if ($cid !== null) {
            return $this->taskClients[$cid] ??= $this->pool->get();
        }
        return $this->client ??= $this->pool->get();
    }

    /** The current coroutine id when it is a parallel() task, null otherwise. */
    private function taskCid(): ?int
    {
        if ($this->ownerCid === null) {
            return null;
        }
        $cid = Coroutine::getCid();
        return $cid !== $this->ownerCid ? $cid : null;
    }

    /**
     * Run independent read callables concurrently, each on its own pooled connection, at most
     * PARALLEL_LIMIT at a time; returns their results under the same keys. A report of many small
     * queries then takes about as long as its slowest query instead of the sum of all of them.
     *
     * Falls back to running them in order when there is nothing to gain or it would be wrong:
     * outside a coroutine, inside a session (ClickHouse runs one query at a time per session, and
     * a session's temporary tables live on it), or for a single task. The callables must only use
     * this connector for ClickHouse and must not call parallel() themselves. The first task error
     * is rethrown after every task has finished and returned its connection.
     *
     * @param array<string|int, callable(): mixed> $tasks
     * @return array<string|int, mixed>
     */
    public function parallel(array $tasks): array
    {
        if (count($tasks) < 2 || $this->sessionId !== null || $this->ownerCid !== null || Coroutine::getCid() < 0) {
            $results = [];
            foreach ($tasks as $key => $task) {
                $results[$key] = $task();
            }
            return $results;
        }
        $this->ownerCid = Coroutine::getCid();
        $results = [];
        $errors = [];
        $slots = new Channel(self::PARALLEL_LIMIT);
        $group = new WaitGroup();
        try {
            foreach ($tasks as $key => $task) {
                $slots->push(true); // waits while PARALLEL_LIMIT tasks are running
                $group->add();
                Coroutine::create(function () use ($key, $task, &$results, &$errors, $slots, $group) {
                    $cid = Coroutine::getCid();
                    try {
                        $results[$key] = $task();
                    } catch (Throwable $e) {
                        $errors[$key] = $e;
                    } finally {
                        if (isset($this->taskClients[$cid])) {
                            $this->pool->put(!empty($this->taskBroken[$cid]) ? null : $this->taskClients[$cid]);
                        }
                        unset($this->taskClients[$cid], $this->taskBroken[$cid]);
                        $slots->pop();
                        $group->done();
                    }
                });
            }
            $group->wait();
        } finally {
            $this->ownerCid = null;
        }
        if ($errors !== []) {
            throw reset($errors);
        }
        // Same order as the tasks, whatever order they finished in.
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
            $taskCid = $this->taskCid();
            if ($taskCid !== null) {
                $this->taskBroken[$taskCid] = true;
            } else {
                $this->broken = true;
            }
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
