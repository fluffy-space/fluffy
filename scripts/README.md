# scripts/

## ClickHouse on Ubuntu 24.04 — two-step setup

Two scripts provision a single-box **ClickHouse** for the Fluffy framework (dev or production).
Both are idempotent and re-runnable. The split keeps the OS/server concern (install, bind, service)
separate from the logical concern (database, user, password, network ACL) — so you can re-provision
users without reinstalling, or install once and add several users.

### Step 1 — `install-clickhouse-ubuntu24.sh` (server)

Adds the official ClickHouse apt repo, installs `clickhouse-server` + `clickhouse-client`, binds the
listener (`config.d` drop-in), enables the systemd service, and waits for readiness.

```bash
sudo bash scripts/install-clickhouse-ubuntu24.sh                  # localhost only
sudo bash scripts/install-clickhouse-ubuntu24.sh --bind 0.0.0.0   # all interfaces
sudo bash scripts/install-clickhouse-ubuntu24.sh --version 24.8.4.13
```

Flags: `--bind` (listen_host), `--http-port` (8123), `--tcp-port` (9000), `--version`.
`--bind` controls only which interface the **server** listens on.

### Step 2 — `provision-clickhouse-user.sh` (database + user)

Creates a database and an app user with a password and a source-network ACL on the already-running
server, optionally applies a schema, and prints the ready-to-paste Fluffy `clickhouse` config block.

```bash
# simple: db + full-access XML user (password auto-generated and printed if omitted)
sudo CH_PASSWORD='secret' bash scripts/provision-clickhouse-user.sh --db fluffy --user fluffy

# db-scoped SQL/RBAC user reachable only from the app subnet, then load schema
sudo CH_PASSWORD='secret' bash scripts/provision-clickhouse-user.sh \
    --db fluffy --user fluffy --sql-user --allowed-network 10.0.1.0/24 --schema ./schema.sql

# allow the user from ANY ip (firewall it!)
sudo CH_PASSWORD='secret' bash scripts/provision-clickhouse-user.sh \
    --db fluffy --user fluffy --allow-remote
```

Flags: `--db`, `--user`, `--password`, `--sql-user`, `--allowed-network <CIDR>`, `--allow-remote`,
`--schema <file.sql>`, `--host` (printed in the config block), `--http-port`, `--tcp-port`.

**User models:** default is an XML config user (full access, simple). `--sql-user` creates a SQL/RBAC
user with `GRANT ALL ON <db>.*` — scoped to one database, for real isolation in prod.

**Network scope vs bind:** Step 1's `--bind` sets which interface the *server* listens on; Step 2's
`--allowed-network <CIDR>` sets which source addresses the *user* may connect from (localhost always
allowed). You need **both** to reach ClickHouse from other machines.

Run either script with `--help` for the full flag/env list.

See **[../docs/clickhouse-integration.md](../docs/clickhouse-integration.md)** for the framework-side
design (pool, connector, DI wiring, usage).

### Requirements

Root or `sudo`, network access to `packages.clickhouse.com`, and `curl` (Step 1 installs the rest).
</content>
