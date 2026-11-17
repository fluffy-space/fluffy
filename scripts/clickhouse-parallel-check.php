<?php
// Standalone check of ClickHouseConnector::parallel() against a running ClickHouse.
// Run from an app root that has vendor/autoload.php and configs/app.php (clickhouse section):
//   php vendor/fluffy-space/fluffy/scripts/clickhouse-parallel-check.php
// Expected: 1 ~0.6s (two waves of 3), 2 ~1.8s (in order), 3 the error reaches the caller,
// 4 session runs in order on its own connection, 5 keys in task order, all connections idle at the end.
require 'vendor/autoload.php';
use Fluffy\Data\Connector\ClickHouseConnector;
use Fluffy\Data\Connector\IClickHouseConnector;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\ClickHouseHttpPool;

$app = include 'configs/app.php';
$config = new Config();
$config->addArray(['clickhouse' => $app['clickhouse']]);

function poolState($pool): string {
    $chan = (fn() => $this->pool)->call($pool);
    $num = (fn() => $this->num)->call($pool);
    return "opened=$num idle=" . $chan->length();
}

Swoole\Coroutine\run(function () use ($config) {
    $pool = new ClickHouseHttpPool($config, 3);
    $pool->fill();
    $ch = new ClickHouseConnector($pool, $config);
    $sleep = fn(int $i) => fn(IClickHouseConnector $c) => (int) $c->query("SELECT sleep(0.3), $i AS i")[0]['i'];
    $six = []; foreach (['a','b','c','d','e','f'] as $n => $k) { $six[$k] = $sleep($n); }

    $t = microtime(true); $r = $ch->parallel($six, 3);
    printf("1 limit=3, 6 x 0.3s: %.2fs, results %s\n", microtime(true) - $t, json_encode($r));
    $t = microtime(true); $r = $ch->parallel($six, 1);
    printf("2 limit=1 (in order): %.2fs, results %s\n", microtime(true) - $t, json_encode($r));

    try {
        $ch->parallel(['ok' => $sleep(1), 'bad' => fn(IClickHouseConnector $c) => $c->query('SELECT no_such_column FROM system.one'), 'ok2' => $sleep(2)], 3);
        echo "3 FAIL: no exception\n";
    } catch (Throwable $e) {
        echo "3 error reached the caller: ", get_class($e), ": ", substr($e->getMessage(), 0, 60), "\n";
    }
    echo "  pool after error: ", poolState($pool), " (the caller keeps the one it borrowed in test 2)\n";

    $ch->beginSession();
    $ch->temporaryTable('scope_ids', 'id UInt64', [7, 8, 9]);
    $t = microtime(true);
    $r = $ch->parallel(['n' => fn($c) => $c->query('SELECT count() AS n FROM scope_ids')[0]['n'], 's' => fn($c) => $c->query('SELECT sum(id) AS s FROM scope_ids')[0]['s']]);
    printf("4 in a session (in order, same connection): %s in %.2fs\n", json_encode($r), microtime(true) - $t);
    $ch->endSession(['scope_ids']);

    $r = $ch->parallel(['x' => $sleep(9), 'y' => fn($c) => 'no-query', 'z' => $sleep(3)], 2);
    echo "5 keys in task order: ", json_encode(array_keys($r)), " ", json_encode($r), "\n";
    $ch->dispose();
    echo "  pool at end: ", poolState($pool), "\n";
    echo "process still running: yes\n";
});
