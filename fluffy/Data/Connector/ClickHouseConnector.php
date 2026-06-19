<?php

namespace Fluffy\Data\Connector;

use DotDi\Interfaces\IDisposable;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IClickHousePool;
use RuntimeException;
use Swoole\Coroutine\Http\Client;

/**
 * Scoped (per-request) ClickHouse connector over the HTTP interface. Borrows a keep-alive
 * coroutine HTTP client from the pool on first use and returns it on dispose() — same lifecycle
 * as PostgreSqlClientConnector. A transport error discards the slot; a ClickHouse application
 * error (HTTP 4xx/5xx) keeps the socket and throws.
 */
class ClickHouseConnector implements IClickHouseConnector, IDisposable
{
    /** gzip level for request bodies when compression is enabled (1 = fast, ~7x on JSONEachRow). */
    const COMPRESS_LEVEL = 1;

    private ?Client $client = null;
    private bool $broken = false;

    public function __construct(private IClickHousePool $pool, private Config $config) {}

    private function get(): Client
    {
        return $this->client ??= $this->pool->get();
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
        if ($this->client !== null) {
            $this->pool->put($this->broken ? null : $this->client);
            $this->client = null;
            $this->broken = false;
        }
    }
}
