<?php

namespace Fluffy\Swoole\Database;

use Fluffy\Domain\Configuration\Config;
use Swoole\Coroutine\Http\Client;

/**
 * Pool of persistent, keep-alive coroutine HTTP clients to the ClickHouse HTTP interface (8123).
 * Mirrors PostgreSQLPool: the factory builds a pre-configured connection per slot; the connector
 * borrows on first use and returns (or discards) on dispose.
 */
class ClickHouseHttpPool extends ClickHouseConnectionPool implements IClickHousePool
{
    const DEFAULT_SIZE = 8;

    public function __construct(private Config $config, int $size = self::DEFAULT_SIZE)
    {
        parent::__construct(function () {
            $c = $this->config->values['clickhouse'];
            $client = new Client($c['host'], (int)$c['port'], !empty($c['ssl']));
            $client->set([
                'timeout'    => $c['timeout'] ?? 5,
                'keep_alive' => true,
            ]);
            $headers = [
                'X-ClickHouse-User'     => $c['user'],
                'X-ClickHouse-Key'      => $c['password'],
                'X-ClickHouse-Database' => $c['database'],
                'Content-Type'          => 'text/plain; charset=utf-8',
                'Connection'            => 'keep-alive',
            ];
            if (!empty($c['compress'])) {
                // Ask ClickHouse to gzip responses, and declare that every request body is gzipped
                // (the connector compresses bodies to match). Worth it only over a network — on
                // localhost the CPU cost outweighs the loopback saving, so default 'compress' => false.
                $headers['Accept-Encoding']  = 'gzip';
                $headers['Content-Encoding'] = 'gzip';
            }
            $client->setHeaders($headers);
            return $client;
        }, $size);
    }
}
