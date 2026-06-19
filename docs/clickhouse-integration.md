# ClickHouse Integration — Fluffy Framework

Design for first-class ClickHouse support in Fluffy core (`vendor/fluffy-space/fluffy/fluffy/`),
mirroring the existing PostgreSQL and Redis connection/pool/connector conventions so apps get a
coroutine-safe ClickHouse client the same way they already get `IConnector` / `RedisConnector`.

This is framework-generic: it provides **connection, pool, connector, a thin client, a light ORM
layer** (context + entity-map + repository), and **migrations** (the existing SQL migration runner,
reused — see §6) — but no app-specific schema. ClickHouse DDL is `command()`/`createTable()`/ALTER-
driven (or applied via the provision script's `--schema`). Fluffy's `php fluffy model` **codegen**
stays PostgreSQL-only; the migration runner is shared.

---

## 1. Transport decision: HTTP interface over a coroutine client

ClickHouse speaks two protocols: **native TCP (9000)** and **HTTP (8123)**.

**Use the HTTP interface via `Swoole\Coroutine\Http\Client`.** Rationale:

- **Non-blocking.** The HTTP client yields the Swoole coroutine while waiting on the socket, exactly
  like `Swoole\Coroutine\PostgreSQL` does for the PG pool. Every PHP native-protocol ClickHouse
  client (`smi2/phpclickhouse`, `the-tinderbox/clickhouse-builder`, ext-based ones) is **blocking
  curl/socket I/O** — it would stall the entire event loop on every query. That is disqualifying for
  a Swoole server.
- **Zero new dependencies.** Swoole's coroutine HTTP client is built in (confirmed available:
  `Swoole\Coroutine\Http\Client`). No composer package, no PHP extension, nothing to add to
  `composer.json`. Contrast with PG, which needs `ext-swoole` PG support.
- **Full feature coverage.** HTTP supports everything we need: `INSERT … FORMAT JSONEachRow` with the
  data in the request body, `SELECT … FORMAT JSONEachRow`, DDL, server-side parameter binding
  (`{name:Type}` + `param_name=…`), per-query settings, gzip, keep-alive, and async inserts.
- **Keep-alive pooling maps cleanly onto `Swoole\ConnectionPool`** — persistent clients reused across
  requests, same lifecycle as the PG/Redis pools.

Native TCP is marginally faster on the wire and supports binary `RowBinary`, but the blocking-client
problem outweighs that. If a binary fast-path is ever needed, it can be added behind the same
`IClickHouseConnector` interface without touching callers.

---

## 2. Core files (mirror of `Swoole/Database` + `Data/Connector` + ORM) — IMPLEMENTED

These files now exist in core (placement mirrors the existing PG layout exactly), and the pool +
connector + context + repository are gate-registered in `BaseStartUp` (§4):

```
fluffy/Swoole/Database/
  IClickHousePool.php              # interface: get() / put($conn)   (mirrors IPostgresqlPool)
  ClickHouseConnectionPool.php     # extends Swoole\ConnectionPool   (mirrors PostgreSqlConnectionPool)
  ClickHouseHttpPool.php           # factory builds Co\Http\Client   (mirrors PostgreSQLPool)

fluffy/Data/Connector/
  IClickHouseConnector.php         # query/insert/command/execute    (mirrors IConnector)
  ClickHouseConnector.php          # implements + IDisposable        (mirrors PostgreSqlClientConnector)

fluffy/Data/Context/
  ClickHouseContext.php            # fluent Query → CH SQL           (mirrors DbContext)

fluffy/Data/Entities/
  ClickHouseCommonMap.php          # CH column types                 (mirrors CommonMap)
  BaseClickHouseEntityMap.php      # Engine/OrderBy/PartitionBy/TTL  (mirrors BaseEntityMap)

fluffy/Data/Repositories/
  BaseClickHouseRepository.php     # CRUD + MergeTree DDL            (mirrors BasePostgresqlRepository)
```

The ORM layer (context, entity map, repository) is detailed in §6; connection/pool/connector below.

### 2.1 `IClickHousePool`

```php
namespace Fluffy\Swoole\Database;

use Swoole\Coroutine\Http\Client;

interface IClickHousePool
{
    /** @return Client */
    function get();
    /** @param Client|null $connection */
    function put($connection);
}
```

### 2.2 `ClickHouseConnectionPool`

```php
namespace Fluffy\Swoole\Database;

use Swoole\ConnectionPool;

class ClickHouseConnectionPool extends ConnectionPool {}
```

(One-liner, identical pattern to `PostgreSqlConnectionPool` — exists so the pool type is nameable and
swappable.)

### 2.3 `ClickHouseHttpPool`

The factory creates a **pre-configured, keep-alive** coroutine HTTP client per slot. Auth travels in
`X-ClickHouse-User` / `X-ClickHouse-Key` headers (cleaner than basic-auth or query params, and never
logged in URLs).

```php
namespace Fluffy\Swoole\Database;

use Fluffy\Domain\Configuration\Config;
use Swoole\Coroutine\Http\Client;

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
            $client->setHeaders([
                'X-ClickHouse-User' => $c['user'],
                'X-ClickHouse-Key'  => $c['password'],
                'X-ClickHouse-Database' => $c['database'],
                'Content-Type'      => 'text/plain; charset=utf-8',
                'Connection'        => 'keep-alive',
            ]);
            return $client;
        }, $size);
    }
}
```

> The factory never opens the socket itself — `Co\Http\Client` connects lazily on the first request
> and transparently reconnects after a keep-alive drop, so a slot that errored is simply discarded
> (see connector `dispose()`), and the pool fills a fresh one from this callback. Exactly how
> `PostgreSQLPool` + `PostgreSqlClientConnector::dispose()` cooperate.

### 2.4 `IClickHouseConnector`

```php
namespace Fluffy\Data\Connector;

use Fluffy\Swoole\Database\IClickHousePool;

interface IClickHouseConnector
{
    /** SELECT → list of assoc rows. Params bind server-side via {name:Type}. */
    function query(string $sql, array $params = []): array;

    /** Bulk INSERT of assoc rows as JSONEachRow. Returns row count sent. */
    function insert(string $table, array $rows, array $columns = []): int;

    /** DDL / DML with no result set (CREATE, ALTER, OPTIMIZE, TRUNCATE, INSERT…SELECT). */
    function command(string $sql, array $params = []): void;

    /** Low-level: full statement (incl. inline data) in body; returns raw response body. */
    function execute(string $sql, array $params = [], array $settings = []): string;

    function getPool(): IClickHousePool;
}
```

### 2.5 `ClickHouseConnector`

Scoped (per-request) service. Borrows one client from the pool on first use and returns it on
`dispose()` — identical lifecycle to `PostgreSqlClientConnector`. **Broken-connection detection**:
a transport error (`errCode !== 0` / `statusCode < 0`) discards the slot (`put(null)`); a ClickHouse
*application* error (HTTP 4xx/5xx with `X-ClickHouse-Exception-Code`) keeps the keep-alive socket and
throws.

```php
namespace Fluffy\Data\Connector;

use DotDi\Interfaces\IDisposable;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Database\IClickHousePool;
use RuntimeException;
use Swoole\Coroutine\Http\Client;

class ClickHouseConnector implements IClickHouseConnector, IDisposable
{
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

        // Server-side bound params: {name:Type} in SQL  ->  ?param_name=value
        $qs = [];
        foreach ($params as $k => $v)   { $qs["param_$k"] = $v; }
        foreach ($settings as $k => $v) { $qs[$k] = $v; }
        $path = '/' . (empty($qs) ? '' : ('?' . http_build_query($qs)));

        $client->post($path, $sql);

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
            if ($line !== '') { $rows[] = json_decode($line, true); }
        }
        return $rows;
    }

    public function insert(string $table, array $rows, array $columns = []): int
    {
        if (!$rows) { return 0; }
        $cols = $columns ? ' (' . implode(',', $columns) . ')' : '';
        $data = [];
        foreach ($rows as $r) { $data[] = json_encode($r, JSON_UNESCAPED_UNICODE); }
        $sql = "INSERT INTO $table$cols FORMAT JSONEachRow\n" . implode("\n", $data);
        // async_insert batches small inserts server-side; configured per-pool (see config).
        $this->execute($sql, [], $this->config->values['clickhouse']['settings'] ?? []);
        return count($rows);
    }

    public function command(string $sql, array $params = []): void
    {
        $this->execute($sql, $params);
    }

    public function getPool(): IClickHousePool { return $this->pool; }

    public function dispose()
    {
        if ($this->client !== null) {
            $this->pool->put($this->broken ? null : $this->client);
            $this->client = null;
            $this->broken = false;
        }
    }
}
```

**Injection safety.** `query()`/`command()` take `$params` and bind them through ClickHouse's
server-side parameter substitution (`{id:UInt64}` ⇄ `param_id=…`). This is the ClickHouse analog of
`IConnector::escapeLiteral()` — callers never string-concat user input into SQL. Values are
URL-encoded by `http_build_query`.

---

## 3. Configuration

Apps opt in by adding a `clickhouse` block to their config (template `configs/app.default.php`,
secrets via the vault — same layering as `postgresql` / `redis` / `redisDb`):

```php
'clickhouse' => [
    'host'     => '127.0.0.1',
    'port'     => CLICKHOUSE_HTTP_PORT,   // 8123
    'ssl'      => false,
    'database' => 'CLICKHOUSE_DB',        // e.g. 'fluffy'
    'user'     => 'CLICKHOUSE_USER',      // e.g. 'fluffy'
    'password' => 'CLICKHOUSE_PASSWORD',
    'timeout'  => 5,
    'poolSize' => 8,
    // gzip request bodies + responses. Worth it only over a network (CPU cost > loopback saving);
    // keep false on a localhost/same-box ClickHouse. See §7.
    'compress' => false,
    // INSERT batching backstop: let the server coalesce small inserts.
    'settings' => ['async_insert' => 1, 'wait_for_async_insert' => 0],
],
```

The block is **optional**. Framework registration is gated on its presence, so existing apps that
don't configure ClickHouse are unaffected.

**`compress`** — when `true`, the connector gzips every request body (`Content-Encoding: gzip`) and
asks ClickHouse to gzip responses (`enable_http_compression=1`, Swoole auto-inflates). Measured ~8×
smaller JSONEachRow at ~109 MB/s (gzip). **Rule of thumb: enable only when the network is slower than
the compressor** — a clear win on WAN/cross-AZ/metered links, a net loss on localhost (default
`false`).

---

## 4. DI registration (`BaseStartUp::configure`)

Insert directly after the Redis pool block (around the `RedisConnector` registration). Conditional,
so it's a no-op when unconfigured:

```php
// ClickHouse (optional) — registered only when configured.
if (isset($this->config->values['clickhouse'])) {
    $chConfig = $this->config->values['clickhouse'];
    $chPool = new ClickHouseHttpPool($this->config, $chConfig['poolSize'] ?? ClickHouseHttpPool::DEFAULT_SIZE);
    $serviceProvider->setSingleton(IClickHousePool::class, $chPool);
    $serviceProvider->addScoped(IClickHouseConnector::class, ClickHouseConnector::class);
}
```

Lifetime matches PG/Redis: **pool = singleton** (one per server, holds the keep-alive clients),
**connector = scoped** (one per request, auto-disposed → client returned to the pool).

New imports at the top of `BaseStartUp.php`:

```php
use Fluffy\Swoole\Database\IClickHousePool;
use Fluffy\Swoole\Database\ClickHouseHttpPool;
use Fluffy\Data\Connector\IClickHouseConnector;
use Fluffy\Data\Connector\ClickHouseConnector;
```

---

## 5. Usage

Inject `IClickHouseConnector` anywhere a scoped service/repository runs (controllers, services, and
crucially **task-worker cron jobs** — the connector works inside the Swoole task scope just like the
PG connector does):

```php
class EventStore
{
    public function __construct(private IClickHouseConnector $ch) {}

    public function record(array $events): void
    {
        // Bulk append — one HTTP round-trip, server-side async batching.
        $this->ch->insert('events', $events, ['Id', 'UserId', 'Type', 'CreatedOn']);
    }

    public function dailyByType(int $userId): array
    {
        return $this->ch->query(
            "SELECT toDate(CreatedOn) AS Day, Type, count() AS C
               FROM events
              WHERE UserId = {uid:UInt64}
              GROUP BY Day, Type ORDER BY Day",
            ['uid' => $userId]
        );
    }

    public function createSchema(): void
    {
        $this->ch->command(
            "CREATE TABLE IF NOT EXISTS events (
                Id UInt64, UserId UInt64, Type LowCardinality(String),
                CreatedOn DateTime64(6,'UTC')
             ) ENGINE = MergeTree
             PARTITION BY toYYYYMM(CreatedOn)
             ORDER BY (UserId, CreatedOn)"
        );
    }
}
```

**Schema management.** ClickHouse DDL is not handled by Fluffy migrations/codegen (those stay
PG-only). Options, in order of preference:

1. Ship a `schema.sql` and apply it via the provision script's `--schema` flag (deploy-time).
2. Call `command()` with `CREATE TABLE IF NOT EXISTS …` from an app bootstrap / `php fluffy install`
   hook (idempotent, runs on startup).

ClickHouse-native engines do the heavy lifting an app would otherwise hand-roll: `MergeTree` +
`PARTITION BY toYYYYMM(...)` + `TTL col + INTERVAL N DAY` for retention (no prune cron), and
`AggregatingMergeTree` + a `MATERIALIZED VIEW` for rollups computed automatically on insert (no
upsert job).

---

## 6. ORM layer — context, entity map, repository

A thin ClickHouse ORM mirrors the PostgreSQL one. It reuses the **dialect-agnostic** `Query` /
`Expression` / `Column` builder and `IMapper`/`StdMapper` unchanged; only the SQL emission and DDL
differ (backtick identifiers, `database`.`table`, `count()`, MergeTree DDL).

### Entity + map (MergeTree shape instead of PK/FK)

A ClickHouse entity is a plain `BaseEntity`; its map extends `BaseClickHouseEntityMap` and declares
the engine, sorting key, partitioning and TTL instead of a primary/foreign key. `Columns()` stays the
source of truth.

```php
class ClickEventEntity extends BaseEntity
{
    public int $ShortUrlId = 0;
    public string $Country = '';
    public string $Device = '';
    public string $IpHash = '';
    // Id + CreatedOn come from BaseEntity
}

class ClickEventEntityMap extends BaseClickHouseEntityMap
{
    public static string $Table = 'short_url_click_event';
    public static string $Engine = 'MergeTree';
    public static ?string $PartitionBy = 'toYYYYMM(toDateTime(CreatedOn / 1000000))';
    public static array  $OrderBy = ['ShortUrlId', 'CreatedOn'];
    public static ?string $Ttl = 'toDateTime(CreatedOn / 1000000) + INTERVAL 90 DAY';

    public static function Columns(): array
    {
        return [
            'Id'         => ClickHouseCommonMap::$Id,
            'ShortUrlId' => ClickHouseCommonMap::$UInt64,
            'Country'    => ClickHouseCommonMap::$LowCardinality,
            'Device'     => ClickHouseCommonMap::$LowCardinality,
            'IpHash'     => ClickHouseCommonMap::$String,
            'CreatedOn'  => ClickHouseCommonMap::$MicroDateTime, // Int64 micros — matches BaseEntity
        ];
    }
}
```

`createTable()` renders this to:

```sql
CREATE TABLE IF NOT EXISTS `short_url_click_event` (
    `Id` UInt64,
    `ShortUrlId` UInt64,
    `Country` LowCardinality(String),
    `Device` LowCardinality(String),
    `IpHash` String,
    `CreatedOn` Int64
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(toDateTime(CreatedOn / 1000000))
ORDER BY (`ShortUrlId`, `CreatedOn`)
TTL toDateTime(CreatedOn / 1000000) + INTERVAL 90 DAY
```

`$Indexes` entries become ClickHouse **data-skipping** indexes
(`INDEX name expr TYPE minmax GRANULARITY 1`) — not B-trees; the `ORDER BY` key is the primary access
path.

### Repository (CRUD + bulk + DDL)

`BaseClickHouseRepository` uses the same `#[Inject(['entityType'=>…, 'entityMap'=>…])]` pattern:

```php
#[Inject(['entityType' => ClickEventEntity::class, 'entityMap' => ClickEventEntityMap::class])]
class ClickEventRepository extends BaseClickHouseRepository {}

$repo->createTable();                        // emit the MergeTree DDL above
$repo->insertBatch($events);                 // one JSONEachRow round-trip (the ingest fast path)
$repo->search([['ShortUrlId','=',$id]], ['CreatedOn'=>-1], 1, 100);   // {list, total}
$repo->getById($id);
$repo->deleteWhere([['ShortUrlId','=',$id]]); // ALTER … DELETE mutation
```

CH-specific behaviour baked in: **no RETURNING** (`Id` is app-assigned — ClickHouse has no
auto-increment, so set it yourself, e.g. from the Redis `INCR` sequence), **bulk INSERT via
JSONEachRow**, and **update/delete as `ALTER … UPDATE/DELETE` mutations** (async + heavy — for
corrections, not hot paths; prefer TTL / `DROP PARTITION` for retention and `ReplacingMergeTree` for
upserts).

### Context (fluent queries)

For richer reads, register the entity and use the fluent `Query` through `ClickHouseContext` (its own
registry, parallel to `DbContext::registerEntity`):

```php
ClickHouseContext::registerEntity(ClickEventEntity::class, ClickEventEntityMap::class);

$rows = $ctx->execute(
    Query::from(ClickEventEntity::class)
        ->where(x(c('ShortUrlId'), '=', $id)->and(c('Country'), '=', 'US'))
        ->orderByDescending('CreatedOn')
        ->take(50)
);   // ['list' => ClickEventEntity[], 'total' => int]
```

For aggregations beyond the builder's reach (GROUP BY, `uniqCombined`, materialized-view reads), call
`IClickHouseConnector::query()` directly with `{name:Type}` bound params.

### Migrations (reuses the SQL migration system)

ClickHouse migrations reuse Fluffy's migration machinery unchanged — a migration is a `BaseMigration`
subclass; only its body talks to ClickHouse via an injected repository. The migration **ledger stays
in PostgreSQL** (`MigrationHistoryEntity`), so CH and PG migrations share one ordered, idempotent
history and a single `php fluffy migrate`.

```php
#[Inject(['entityType' => ClickEventEntity::class, 'entityMap' => ClickEventEntityMap::class])]
class ClickEventRepository extends BaseClickHouseRepository {}

class ClickEventMigration extends BaseMigration
{
    public function __construct(
        MigrationRepository $history,        // PG ledger — tracks this migration like any other
        private ClickEventRepository $events // DI injects any CH repo / connector / service
    ) {
        parent::__construct($history);
    }

    public function up()   { $this->events->createTable(); }
    public function down() { $this->events->dropTable(); }   // idempotent (IF EXISTS)
}
```

Register the repo in `StartUp` DI (`addScoped(ClickEventRepository::class)`) and the migration in
`MigrationsContext::run()` (`$this->runMigration(ClickEventMigration::class)`) — exactly like a PG one.

**Table modifications** go in their own follow-up migration via the repo's ALTER helpers:

```php
public function up()
{
    $this->events->addColumns(['Os' => ClickHouseCommonMap::$LowCardinality]);
    $this->events->addIndex('IX_Country', 'Country', 'set(100)', 4);
    $this->events->modifyTtl('toDateTime(CreatedOn / 1000000) + INTERVAL 30 DAY');
}
public function down()
{
    $this->events->dropColumns(['Os']);
    $this->events->dropIndex('IX_Country');
}
```

Helpers: `createTable` / `dropTable` / `tableExists` / `addColumns` / `dropColumns` / `modifyColumn` /
`renameColumn` / `addIndex` / `dropIndex` / `modifyTtl` / `modifyOrderBy`, plus `alter($action)` as a
raw escape hatch. (All exercised live in `Tests/ClickHouseSmokeTest.php`.)

**Caveat — no transactions.** ClickHouse can't roll back a multi-statement migration atomically.
`BaseMigration` still calls `down()` best-effort on failure, but keep each migration to a single DDL
statement where you can and make `down()` idempotent (the helpers already emit `IF EXISTS` /
`IF NOT EXISTS`). `ADD/DROP/RENAME COLUMN` and index ops are instant metadata changes; a `MODIFY
COLUMN` that changes the stored type rewrites parts in the background.

---

## 7. Operational notes

- **Batch, don't drip.** One `INSERT` per HTTP request with many rows. ClickHouse hates
  many-small-inserts (each makes a part); `async_insert=1` in `settings` is the backstop that
  coalesces them server-side. The natural pattern is a queue + periodic drain (`CronTab`) that flushes
  N rows per round-trip.
- **At-least/at-most-once.** If a producer drains a Redis queue then inserts, decide ordering: trim
  *after* a confirmed insert = at-least-once (possible dup rows → use `ReplacingMergeTree` or accept
  approximate counts); trim *before* = at-most-once (a failed insert loses a batch). Pick per the
  data's tolerance.
- **Keep-alive health.** A transport error discards the slot; the pool rebuilds it from the factory.
  No manual reconnect logic needed.
- **Timeouts.** Pool-level `timeout` covers slow analytical scans; raise it for heavy `GROUP BY`
  endpoints, or pass a per-query `max_execution_time` via the settings arg of `execute()`.
- **TLS / remote.** Set `'ssl' => true` and point `host`/`port` at the HTTPS interface (8443) for a
  remote managed ClickHouse; the same connector code works unchanged.

---

## 8. Install / provisioning

Two idempotent scripts provision a single-box ClickHouse on **Ubuntu 24.04 (noble)** — usable for both
this dev machine and the production server. The split separates the OS/server concern from the
logical (db/user/network) concern. See `scripts/README.md`.

```bash
# Step 1 — server: apt install, bind/ports, systemd service
sudo bash scripts/install-clickhouse-ubuntu24.sh --bind 0.0.0.0

# Step 2 — database + user + password + network ACL (+ optional schema)
sudo CH_PASSWORD='…' bash scripts/provision-clickhouse-user.sh --db fluffy --user fluffy
```

Step 1 adds the official ClickHouse apt repo, installs `clickhouse-server` + `clickhouse-client`,
binds the listener (localhost by default), enables the systemd service, and waits for readiness.
Step 2 creates a password-protected database + app user, optionally applies an app `--schema
file.sql`, and prints the ready-to-paste `clickhouse` config block.

### User model: XML config user vs SQL/RBAC user

ClickHouse has two parallel user systems; the provision script supports both:

- **XML config user (default).** Defined declaratively in `users.d/fluffy-user.xml`. Simple, but
  privileges are coarse (a `<profile>` + an `<allow_databases>` list) and **cannot receive SQL
  `GRANT`s**. Fine for a single-app box.
- **SQL/RBAC user (`--sql-user`).** Created at runtime with `CREATE USER … IDENTIFIED WITH
  sha256_hash …` and **`GRANT ALL ON <db>.*`** — privileges scoped to exactly one database, stored in
  ClickHouse's own access storage (not config files), and manageable with roles/row-policies later.
  Use this in prod for real per-database isolation. (The script first enables `access_management` on
  the local `default` user via a bootstrap drop-in so it can run the `CREATE USER`/`GRANT`, and locks
  `default` to localhost.)

### Network scope (`<networks>` / `HOST`) ≠ listener bind (`listen_host`)

Two independent controls — you need **both** for remote access:

- **`--bind` (`listen_host`, Step 1)** — which interface the *server* binds (`127.0.0.1` default;
  `0.0.0.0` or a private IP to expose).
- **`--allowed-network <CIDR>` (Step 2)** — which source addresses the *app user* may connect from.
  This is the "network group": localhost is always allowed, plus the CIDR you pass (e.g. the app
  fleet's subnet `10.0.1.0/24`). Maps to `<networks><ip>…</ip></networks>` for the XML user and `HOST
  LOCAL, IP '<CIDR>'` for the SQL user. `--allow-remote` widens this to any IP (`::/0` / `HOST ANY`) —
  firewall it.

```bash
# prod: server on the private IP, db-scoped SQL user reachable only from the app subnet
sudo bash scripts/install-clickhouse-ubuntu24.sh --bind 0.0.0.0
sudo CH_PASSWORD='…' bash scripts/provision-clickhouse-user.sh \
    --db fluffy --user fluffy --sql-user --allowed-network 10.0.1.0/24
```

---

## 9. Implementation checklist

1. Add the 5 core files in §2 (`Swoole/Database/*`, `Data/Connector/*`).
2. Gate-register pool (singleton) + connector (scoped) in `BaseStartUp::configure` (§4) with imports.
3. Document the `clickhouse` config block in the app template + vault keys (§3).
4. Run `scripts/install-clickhouse-ubuntu24.sh` then `scripts/provision-clickhouse-user.sh` on dev +
   prod; paste the printed config block.
5. (App-side) define DDL via `--schema` or `command('CREATE … IF NOT EXISTS')`; use
   `IClickHouseConnector` from services/cron.

## 10. Risks / call-outs

- **Blocking-client trap** — must use `Swoole\Coroutine\Http\Client`; a normal curl/PDO ClickHouse
  client silently destroys server throughput. Enforced by design (§1).
- **Small-insert amplification** — always batch; `async_insert` is a safety net, not a license to
  insert per-row.
- **No ORM** — ClickHouse tables are DDL/`command()`-managed; don't expect `model build`/migrations.
- **Eventual consistency** — `MATERIALIZED VIEW` rollups and `async_insert` mean reads can lag writes
  by background-merge time; use `FINAL` or read the raw table for read-your-writes needs.
