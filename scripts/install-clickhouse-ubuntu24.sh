#!/usr/bin/env bash
#
# install-clickhouse-ubuntu24.sh
#
# STEP 1 of 2 — install + start the ClickHouse server on Ubuntu 24.04 (noble).
# Handles the OS/server side only: apt repo, packages, listener bind/ports, systemd service.
# To create a database + app user, run STEP 2 afterwards: provision-clickhouse-user.sh
#
# Idempotent and re-runnable; safe for both this dev machine and the production server.
#
# What it does:
#   1. Adds the official ClickHouse apt repo (signed) and installs clickhouse-server + client.
#   2. Binds the HTTP/TCP listener (config.d drop-in): listen_host + ports.
#   3. Enables + (re)starts the systemd service and waits for HTTP readiness.
#
# Usage:
#   sudo bash scripts/install-clickhouse-ubuntu24.sh                       # localhost only
#   sudo bash scripts/install-clickhouse-ubuntu24.sh --bind 0.0.0.0        # all interfaces
#   sudo bash scripts/install-clickhouse-ubuntu24.sh --version 24.8.4.13   # pin a version
#
# Configuration (env vars or flags; flags win):
#   CH_HTTP_PORT   --http-port   HTTP interface port                  (default: 8123)
#   CH_TCP_PORT    --tcp-port    native TCP port                      (default: 9000)
#   CH_BIND        --bind        listen_host (server bind interface)  (default: 127.0.0.1)
#   CH_VERSION     --version     pin a clickhouse version             (default: latest stable)
#
# NOTE: --bind controls only which interface the SERVER listens on. Which source IPs a USER may
#       connect from is set in STEP 2 (--allowed-network / --allow-remote).
#
set -euo pipefail

# ----------------------------------------------------------------------------- defaults
CH_HTTP_PORT="${CH_HTTP_PORT:-8123}"
CH_TCP_PORT="${CH_TCP_PORT:-9000}"
CH_BIND="${CH_BIND:-127.0.0.1}"
CH_VERSION="${CH_VERSION:-}"

# ----------------------------------------------------------------------------- flag parsing
while [ $# -gt 0 ]; do
    case "$1" in
        --http-port) CH_HTTP_PORT="$2"; shift 2 ;;
        --tcp-port)  CH_TCP_PORT="$2"; shift 2 ;;
        --bind)      CH_BIND="$2"; shift 2 ;;
        --version)   CH_VERSION="$2"; shift 2 ;;
        -h|--help)   grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;36m[clickhouse]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[clickhouse] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[clickhouse] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Run a command as root whether or not we were invoked with sudo.
if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo"; command -v sudo >/dev/null || die "need root or sudo"; fi

# ----------------------------------------------------------------------------- preconditions
[ -f /etc/os-release ] || die "cannot detect OS (no /etc/os-release)"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || warn "designed for Ubuntu; detected '${ID:-?}' — continuing anyway"
case "${VERSION_ID:-}" in
    24.*) : ;;
    *) warn "targeted at Ubuntu 24.04; detected '${VERSION_ID:-?}' — the apt repo is distro-agnostic, continuing" ;;
esac

# ----------------------------------------------------------------------------- 1. apt repo + install
log "installing prerequisites…"
export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get update -qq
$SUDO apt-get install -y -qq apt-transport-https ca-certificates curl gnupg openssl >/dev/null

KEYRING=/usr/share/keyrings/clickhouse-keyring.gpg
if [ ! -s "$KEYRING" ]; then
    log "adding ClickHouse signing key…"
    curl -fsSL 'https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key' \
        | $SUDO gpg --dearmor -o "$KEYRING"
    $SUDO chmod a+r "$KEYRING"
fi

ARCH="$(dpkg --print-architecture)"
REPO_LINE="deb [signed-by=${KEYRING} arch=${ARCH}] https://packages.clickhouse.com/deb stable main"
if ! grep -qsF "$REPO_LINE" /etc/apt/sources.list.d/clickhouse.list 2>/dev/null; then
    log "adding ClickHouse apt repo…"
    echo "$REPO_LINE" | $SUDO tee /etc/apt/sources.list.d/clickhouse.list >/dev/null
fi

$SUDO apt-get update -qq
if [ -n "$CH_VERSION" ]; then
    log "installing clickhouse-server/client version $CH_VERSION…"
    $SUDO apt-get install -y -qq "clickhouse-server=$CH_VERSION" "clickhouse-client=$CH_VERSION" \
        "clickhouse-common-static=$CH_VERSION" >/dev/null
else
    log "installing clickhouse-server + clickhouse-client (latest stable)…"
    $SUDO apt-get install -y -qq clickhouse-server clickhouse-client >/dev/null
fi
log "installed: $(clickhouse-client --version 2>/dev/null || echo 'clickhouse-client')"

# ----------------------------------------------------------------------------- 2. network config.d
log "writing network config (listen=$CH_BIND http=$CH_HTTP_PORT tcp=$CH_TCP_PORT)…"
$SUDO tee /etc/clickhouse-server/config.d/fluffy-network.xml >/dev/null <<XML
<clickhouse>
    <listen_host>${CH_BIND}</listen_host>
    <http_port>${CH_HTTP_PORT}</http_port>
    <tcp_port>${CH_TCP_PORT}</tcp_port>
</clickhouse>
XML
$SUDO chown clickhouse:clickhouse /etc/clickhouse-server/config.d/fluffy-network.xml 2>/dev/null || true
[ "$CH_BIND" = "0.0.0.0" ] && warn "server bound to 0.0.0.0 — exposed on every interface; firewall it and scope users in STEP 2."

# ----------------------------------------------------------------------------- 3. service up + readiness
log "enabling + (re)starting clickhouse-server…"
$SUDO systemctl enable clickhouse-server >/dev/null 2>&1 || true
$SUDO systemctl restart clickhouse-server

log "waiting for HTTP readiness on 127.0.0.1:${CH_HTTP_PORT}…"
READY=0
for _ in $(seq 1 30); do
    if [ "$(curl -fsS "http://127.0.0.1:${CH_HTTP_PORT}/ping" 2>/dev/null || true)" = "Ok." ]; then
        READY=1; break
    fi
    sleep 1
done
[ "$READY" = "1" ] || die "server did not become ready — check: journalctl -u clickhouse-server -e"

# ----------------------------------------------------------------------------- summary
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cat <<SUMMARY

$(printf '\033[1;32m✓ ClickHouse server installed and running.\033[0m')

  HTTP:  http://${CH_BIND}:${CH_HTTP_PORT}     (ping: curl http://127.0.0.1:${CH_HTTP_PORT}/ping)
  TCP:   ${CH_BIND}:${CH_TCP_PORT}
  Local admin (no password yet): clickhouse-client --port ${CH_TCP_PORT}

Next — STEP 2: create a database + app user:

  sudo CH_PASSWORD='secret' bash ${SCRIPT_DIR}/provision-clickhouse-user.sh \\
      --db fluffy --user fluffy
$( [ "$CH_BIND" != "127.0.0.1" ] && printf '      --allowed-network 10.0.1.0/24   # let your app subnet in\n' )

SUMMARY
