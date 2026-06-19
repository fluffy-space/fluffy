#!/usr/bin/env bash
#
# provision-clickhouse-user.sh
#
# STEP 2 of 2 — create a database + app user (+ password + network ACL) on an already-running
# ClickHouse server. Run install-clickhouse-ubuntu24.sh (STEP 1) first.
#
# Idempotent and re-runnable. Two user models:
#   - XML user (default): config-defined in users.d; full access; coarse but simple.
#   - SQL/RBAC user (--sql-user): CREATE USER + GRANT ALL ON <db>.* — scoped to ONE database.
#
# What it does:
#   1. Verifies the server is reachable (local HTTP ping).
#   2. Computes the user's allowed source networks (--allowed-network CIDR / --allow-remote).
#   3. Creates the user (users.d XML, or SQL CREATE USER) and the database, reloading config.
#   4. Verifies the user can authenticate, optionally applies --schema, prints the config block.
#
# Usage:
#   sudo CH_PASSWORD='secret' bash scripts/provision-clickhouse-user.sh --db fluffy --user fluffy
#   # db-scoped SQL user reachable only from a subnet:
#   sudo CH_PASSWORD='secret' bash scripts/provision-clickhouse-user.sh \
#         --db fluffy --user fluffy --sql-user --allowed-network 10.0.1.0/24
#
# Configuration (env vars or flags; flags win):
#   CH_DB              --db              database to create                 (default: fluffy)
#   CH_USER            --user           app user to create                 (default: fluffy)
#   CH_PASSWORD        --password       app user password                  (default: generated, printed)
#   CH_HTTP_PORT       --http-port      server HTTP port (for verify)      (default: 8123)
#   CH_TCP_PORT        --tcp-port       server native TCP port             (default: 9000)
#   CH_HOST            --host           host to print in the config block  (default: 127.0.0.1)
#   CH_SQL_USER        --sql-user       SQL/RBAC user scoped to CH_DB       (default: 0 = XML user)
#   CH_ALLOWED_NETWORK --allowed-network  extra source CIDR the user may    (default: none = localhost only)
#                                       connect from, e.g. 10.0.1.0/24
#   CH_ALLOW_REMOTE    --allow-remote   allow the user from ANY ip (::/0)   (default: 0)
#   CH_SCHEMA          --schema         SQL file to apply into CH_DB        (default: none)
#
set -euo pipefail

# ----------------------------------------------------------------------------- defaults
CH_DB="${CH_DB:-fluffy}"
CH_USER="${CH_USER:-fluffy}"
CH_PASSWORD="${CH_PASSWORD:-}"
CH_HTTP_PORT="${CH_HTTP_PORT:-8123}"
CH_TCP_PORT="${CH_TCP_PORT:-9000}"
CH_HOST="${CH_HOST:-127.0.0.1}"
CH_SQL_USER="${CH_SQL_USER:-0}"
CH_ALLOWED_NETWORK="${CH_ALLOWED_NETWORK:-}"
CH_ALLOW_REMOTE="${CH_ALLOW_REMOTE:-0}"
CH_SCHEMA="${CH_SCHEMA:-}"

# ----------------------------------------------------------------------------- flag parsing
while [ $# -gt 0 ]; do
    case "$1" in
        --db)              CH_DB="$2"; shift 2 ;;
        --user)            CH_USER="$2"; shift 2 ;;
        --password)        CH_PASSWORD="$2"; shift 2 ;;
        --http-port)       CH_HTTP_PORT="$2"; shift 2 ;;
        --tcp-port)        CH_TCP_PORT="$2"; shift 2 ;;
        --host)            CH_HOST="$2"; shift 2 ;;
        --sql-user)        CH_SQL_USER=1; shift ;;
        --allowed-network) CH_ALLOWED_NETWORK="$2"; shift 2 ;;
        --allow-remote)    CH_ALLOW_REMOTE=1; shift ;;
        --schema)          CH_SCHEMA="$2"; shift 2 ;;
        -h|--help)         grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;36m[clickhouse]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[clickhouse] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[clickhouse] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo"; command -v sudo >/dev/null || die "need root or sudo"; fi

# ----------------------------------------------------------------------------- 1. preconditions
command -v clickhouse-client >/dev/null || die "clickhouse-client not found — run STEP 1 (install-clickhouse-ubuntu24.sh) first."
[ -d /etc/clickhouse-server/users.d ] || die "/etc/clickhouse-server not found — run STEP 1 first."
[ "$(curl -fsS "http://127.0.0.1:${CH_HTTP_PORT}/ping" 2>/dev/null || true)" = "Ok." ] \
    || die "server not reachable on 127.0.0.1:${CH_HTTP_PORT} — is it running? (systemctl status clickhouse-server)"

if [ -z "$CH_PASSWORD" ]; then
    CH_PASSWORD="$(openssl rand -hex 16)"
    GENERATED_PW=1
    log "no CH_PASSWORD given — generated one (printed at the end)"
fi
PW_SHA256="$(printf '%s' "$CH_PASSWORD" | sha256sum | awk '{print $1}')"

# local admin client (default user, local socket — no password on a fresh install)
chq() { clickhouse-client --host 127.0.0.1 --port "${CH_TCP_PORT}" "$@"; }

# ----------------------------------------------------------------------------- 2. allowed networks
# The "network group": which source IPs the USER may connect from (independent of the server bind).
# XML_NETWORKS feeds the users.d form; SQL_HOST feeds the CREATE USER … HOST … form.
if [ -n "$CH_ALLOWED_NETWORK" ]; then
    XML_NETWORKS="<ip>127.0.0.1</ip><ip>::1</ip><ip>${CH_ALLOWED_NETWORK}</ip>"
    SQL_HOST="HOST LOCAL, IP '${CH_ALLOWED_NETWORK}'"
    log "user '$CH_USER' reachable from localhost + ${CH_ALLOWED_NETWORK}"
elif [ "$CH_ALLOW_REMOTE" = "1" ]; then
    XML_NETWORKS="<ip>::/0</ip>"
    SQL_HOST="HOST ANY"
    warn "user '$CH_USER' accepts connections from ANY ip — ensure a firewall."
else
    XML_NETWORKS="<ip>127.0.0.1</ip><ip>::1</ip>"
    SQL_HOST="HOST LOCAL"
fi

XML_USER_FILE=/etc/clickhouse-server/users.d/fluffy-user.xml
BOOTSTRAP_FILE=/etc/clickhouse-server/users.d/fluffy-bootstrap.xml

# ----------------------------------------------------------------------------- 3. user + database
if [ "$CH_SQL_USER" = "1" ]; then
    # SQL/RBAC path: enable access management on the local 'default' user (so it can CREATE USER /
    # GRANT) and lock 'default' to localhost. The app user itself is created via SQL below.
    log "enabling SQL access control (bootstrap drop-in for local 'default' user)…"
    $SUDO tee "$BOOTSTRAP_FILE" >/dev/null <<XML
<clickhouse>
    <users>
        <default>
            <networks><ip>127.0.0.1</ip><ip>::1</ip></networks>
            <access_management>1</access_management>
            <named_collection_control>1</named_collection_control>
        </default>
    </users>
</clickhouse>
XML
    $SUDO rm -f "$XML_USER_FILE"
    $SUDO chown clickhouse:clickhouse "$BOOTSTRAP_FILE" 2>/dev/null || true
    $SUDO chmod 640 "$BOOTSTRAP_FILE" 2>/dev/null || true
    chq --query "SYSTEM RELOAD CONFIG"
    sleep 1

    log "creating database '$CH_DB' (IF NOT EXISTS)…"
    chq --query "CREATE DATABASE IF NOT EXISTS \`${CH_DB}\`"
    log "creating SQL-managed user '$CH_USER' scoped to '$CH_DB' (${SQL_HOST})…"
    chq --multiquery --query "
        CREATE USER OR REPLACE \`${CH_USER}\`
            IDENTIFIED WITH sha256_hash BY '${PW_SHA256}'
            ${SQL_HOST}
            DEFAULT DATABASE \`${CH_DB}\`;
        GRANT ALL ON \`${CH_DB}\`.* TO \`${CH_USER}\`;
    "
else
    # XML path: a config-defined user with full access, restricted by network only.
    log "writing app user '$CH_USER' (users.d, config-defined)…"
    $SUDO tee "$XML_USER_FILE" >/dev/null <<XML
<clickhouse>
    <users>
        <${CH_USER}>
            <password_sha256_hex>${PW_SHA256}</password_sha256_hex>
            <networks>${XML_NETWORKS}</networks>
            <profile>default</profile>
            <quota>default</quota>
            <access_management>0</access_management>
        </${CH_USER}>
    </users>
</clickhouse>
XML
    $SUDO rm -f "$BOOTSTRAP_FILE"
    $SUDO chown clickhouse:clickhouse "$XML_USER_FILE" 2>/dev/null || true
    $SUDO chmod 640 "$XML_USER_FILE" 2>/dev/null || true
    chq --query "SYSTEM RELOAD CONFIG"
    sleep 1

    log "creating database '$CH_DB' (IF NOT EXISTS)…"
    chq --query "CREATE DATABASE IF NOT EXISTS \`${CH_DB}\`"
fi

# ----------------------------------------------------------------------------- 4. verify + schema
if [ "$(curl -fsS -u "${CH_USER}:${CH_PASSWORD}" \
        "http://127.0.0.1:${CH_HTTP_PORT}/?database=${CH_DB}" \
        --data-binary 'SELECT 1' 2>/dev/null || true)" = "1" ]; then
    log "app user '$CH_USER' authenticates OK against '$CH_DB'."
else
    warn "could not verify app-user login (check password / network ACL)."
fi

if [ -n "$CH_SCHEMA" ]; then
    [ -f "$CH_SCHEMA" ] || die "--schema file not found: $CH_SCHEMA"
    log "applying schema $CH_SCHEMA into '$CH_DB'…"
    chq --database "${CH_DB}" --multiquery < "$CH_SCHEMA"
    log "schema applied."
fi

# ----------------------------------------------------------------------------- summary
cat <<SUMMARY

$(printf '\033[1;32m✓ ClickHouse database + user ready.\033[0m')

  Database: ${CH_DB}
  User:     ${CH_USER}  ($( [ "$CH_SQL_USER" = "1" ] && echo "SQL/RBAC, GRANT ALL ON ${CH_DB}.* only" || echo "XML config user, full access" ))
  From:     $( [ -n "$CH_ALLOWED_NETWORK" ] && echo "localhost + ${CH_ALLOWED_NETWORK}" || { [ "$CH_ALLOW_REMOTE" = "1" ] && echo "ANY ip (firewall it!)" || echo "localhost only"; } )
$( [ "${GENERATED_PW:-0}" = "1" ] && printf '  Password: %s   (generated — save it now)\n' "$CH_PASSWORD" )

Paste into your app's configs (secrets to the vault):

    'clickhouse' => [
        'host'     => '${CH_HOST}',
        'port'     => ${CH_HTTP_PORT},
        'ssl'      => false,
        'database' => '${CH_DB}',
        'user'     => '${CH_USER}',
        'password' => '********',
        'timeout'  => 5,
        'poolSize' => 8,
        'settings' => ['async_insert' => 1, 'wait_for_async_insert' => 0],
    ],

Verify manually:
    curl -u '${CH_USER}:****' 'http://${CH_HOST}:${CH_HTTP_PORT}/?database=${CH_DB}' --data-binary 'SELECT version()'
    clickhouse-client --port ${CH_TCP_PORT} --user ${CH_USER} --password --query 'SHOW TABLES FROM ${CH_DB}'

SUMMARY
