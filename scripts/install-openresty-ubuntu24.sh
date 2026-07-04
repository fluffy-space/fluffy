#!/usr/bin/env bash
#
# install-openresty-ubuntu24.sh
#
# Install + start OpenResty (nginx + LuaJIT + the lua-resty-* bundle) on Ubuntu
# 24.04 (noble) as the edge reverse proxy in front of the Swoole app. OpenResty
# is plain nginx plus the Lua module and lua-resty-core / lua-resty-redis, which
# we need for per-SNI custom-domain TLS (ssl_certificate_by_lua_block reading a
# cert out of Redis — see docs/custom-domains-plan.md). For the primary domains
# it behaves exactly like stock nginx.
#
# Handles the OS/server side only: apt repo, package, the /etc/nginx layout
# (sites-available + sites-enabled + conf.d) wired into OpenResty's main config,
# the shared http-context tuning (worker/TLS-session/`$connection_upgrade` map)
# that the per-site template depends on, a dev self-signed cert, firewall, and
# the systemd service. Per-domain site files are generated afterwards by:
#     sudo php fluffy nginx <domain>
#
# Idempotent and re-runnable; safe on a fresh box or one that already ran stock
# nginx (it reuses /etc/nginx/sites-available|sites-enabled if present).
#
# Usage:
#   sudo bash scripts/install-openresty-ubuntu24.sh
#   sudo bash scripts/install-openresty-ubuntu24.sh --workers auto --connections 16384
#
# Configuration (env vars or flags; flags win):
#   OR_WORKERS      --workers      worker_processes            (default: auto)
#   OR_CONNECTIONS  --connections  worker_connections          (default: 16384)
#   OR_NOFILE       --nofile       worker_rlimit_nofile        (default: 65535)
#
set -euo pipefail

# ----------------------------------------------------------------------------- defaults
OR_WORKERS="${OR_WORKERS:-auto}"
OR_CONNECTIONS="${OR_CONNECTIONS:-16384}"
OR_NOFILE="${OR_NOFILE:-65535}"

# ----------------------------------------------------------------------------- flag parsing
while [ $# -gt 0 ]; do
    case "$1" in
        --workers)     OR_WORKERS="$2"; shift 2 ;;
        --connections) OR_CONNECTIONS="$2"; shift 2 ;;
        --nofile)      OR_NOFILE="$2"; shift 2 ;;
        -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;36m[openresty]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[openresty] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[openresty] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo"; command -v sudo >/dev/null || die "need root or sudo"; fi

# ----------------------------------------------------------------------------- preconditions
[ -f /etc/os-release ] || die "cannot detect OS (no /etc/os-release)"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || warn "designed for Ubuntu; detected '${ID:-?}' — continuing anyway"
CODENAME="${VERSION_CODENAME:-noble}"

# nginx and OpenResty both bind :80/:443 — they can't run together. If stock
# nginx holds the ports, stop it (OpenResty replaces it; configs are reused).
if systemctl is-active --quiet nginx 2>/dev/null; then
    warn "stock nginx is running — stopping+disabling it (OpenResty replaces it and reuses /etc/nginx sites)"
    $SUDO systemctl stop nginx || true
    $SUDO systemctl disable nginx >/dev/null 2>&1 || true
fi

# ----------------------------------------------------------------------------- 1. apt repo + install
log "installing prerequisites…"
export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get update -qq
$SUDO apt-get install -y -qq ca-certificates curl gnupg lsb-release openssl >/dev/null

KEYRING=/usr/share/keyrings/openresty.gpg
if [ ! -s "$KEYRING" ]; then
    log "adding OpenResty signing key…"
    curl -1fsSL 'https://openresty.org/package/pubkey.gpg' | $SUDO gpg --dearmor -o "$KEYRING"
    $SUDO chmod a+r "$KEYRING"
fi

REPO_FILE=/etc/apt/sources.list.d/openresty.list
if [ ! -s "$REPO_FILE" ]; then
    log "adding OpenResty apt repo (${CODENAME})…"
    echo "deb [arch=amd64 signed-by=${KEYRING}] http://openresty.org/package/ubuntu ${CODENAME} main" \
        | $SUDO tee "$REPO_FILE" >/dev/null
fi

$SUDO apt-get update -qq
log "installing openresty…"
$SUDO apt-get install -y -qq openresty >/dev/null
log "installed: $(/usr/local/openresty/bin/openresty -v 2>&1 || echo openresty)"

# ----------------------------------------------------------------------------- 2. /etc/nginx layout
# NginxBuilder writes /etc/nginx/sites-available/<domain> + a sites-enabled
# symlink (stock-nginx layout). OpenResty defaults to /usr/local/openresty/nginx
# — so we point its main config at /etc/nginx. Reuse the dirs if stock nginx made
# them (its site files stay valid — the template is plain nginx).
$SUDO mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d
# The per-site template logs to /var/log/nginx (OpenResty defaults to its own
# logs/ dir, so this doesn't exist on a fresh box → `openresty -t` fails to open
# the error_log). Create it, owned by the worker user.
$SUDO mkdir -p /var/log/nginx
$SUDO chown www-data:adm /var/log/nginx 2>/dev/null || true

# ----------------------------------------------------------------------------- 3. shared http-context tuning
# The per-site template (bin/setup/nginx.conf) relies on this file: the
# `$connection_upgrade` map (keepalive + WebSocket in one proxy rule), the shared
# TLS session cache (handshakes are the CPU cost at scale), and a resolver for
# the Lua cert path. worker/connection tuning lives in the main conf (step 4).
TUNING=/etc/nginx/conf.d/00-fluffy-tuning.conf
log "writing $TUNING"
$SUDO tee "$TUNING" >/dev/null <<'TUNING_CONF'
# Managed by install-openresty-ubuntu24.sh — shared http-context tuning.

# keepalive-to-upstream OR WebSocket upgrade, chosen per request. The per-site
# template sets `Connection $connection_upgrade`: "" -> pooled keepalive to
# Swoole; "upgrade" -> transparent WS (the reload channel).
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      '';
}

# TLS session reuse — one cache shared by every site. Cuts full handshakes,
# which dominate CPU under the redirect firehose.
ssl_session_cache   shared:SSL:50m;
ssl_session_timeout 1d;
ssl_session_tickets off;
ssl_protocols       TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;

# DNS resolver for Lua (ACME / custom-domain cert issuance). systemd-resolved.
resolver 127.0.0.53 ipv6=off valid=30s;
resolver_timeout 5s;
TUNING_CONF

# ----------------------------------------------------------------------------- 4. managed main nginx.conf
# OpenResty's main config. Points http{} at /etc/nginx/{conf.d,sites-enabled}
# and applies worker tuning. Backed up if a non-managed one exists.
MAIN=/usr/local/openresty/nginx/conf/nginx.conf
if [ -s "$MAIN" ] && ! grep -q 'Managed by install-openresty' "$MAIN" 2>/dev/null; then
    $SUDO cp "$MAIN" "${MAIN}.bak.orig" 2>/dev/null || true
    warn "backed up stock $MAIN -> ${MAIN}.bak.orig"
fi
log "writing managed $MAIN (workers=${OR_WORKERS} connections=${OR_CONNECTIONS})"
$SUDO tee "$MAIN" >/dev/null <<MAIN_CONF
# Managed by install-openresty-ubuntu24.sh
user  www-data;
worker_processes  ${OR_WORKERS};
worker_rlimit_nofile ${OR_NOFILE};

events {
    worker_connections ${OR_CONNECTIONS};
    multi_accept on;
}

http {
    include       mime.types;
    default_type  application/octet-stream;

    sendfile      on;
    tcp_nopush    on;
    tcp_nodelay   on;
    keepalive_timeout 65;
    server_tokens off;

    # lua-resty-core (needed for ssl_certificate_by_lua_block); harmless otherwise.
    lua_shared_dict fluffy_tls 16m;

    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
MAIN_CONF

# ----------------------------------------------------------------------------- 5. dev self-signed cert
# The per-site template points at /etc/ssl/certs/nginx-selfsigned.crt. Generate a
# self-signed pair if absent so `openresty -t` passes and dev/WSL works before any
# real cert exists. Prod primary domains get a real cert via certbot/acme (see docs).
CRT=/etc/ssl/certs/nginx-selfsigned.crt
KEY=/etc/ssl/private/nginx-selfsigned.key
if [ ! -s "$CRT" ] || [ ! -s "$KEY" ]; then
    log "generating dev self-signed cert ($CRT)…"
    $SUDO mkdir -p /etc/ssl/private
    $SUDO openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout "$KEY" -out "$CRT" -subj "/CN=localhost" >/dev/null 2>&1
fi

# ----------------------------------------------------------------------------- 6. firewall
if command -v ufw >/dev/null 2>&1 && $SUDO ufw status 2>/dev/null | grep -q "Status: active"; then
    log "ufw active — allowing 80/tcp and 443/tcp…"
    $SUDO ufw allow 80/tcp  >/dev/null 2>&1 || true
    $SUDO ufw allow 443/tcp >/dev/null 2>&1 || true
fi

# nginx binds 0.0.0.0 (IPv4) by default, so WSL's localhost-forwarding relay
# reaches it — no Caddy-style tcp4/ workaround needed here.
grep -qiE 'microsoft|wsl' /proc/sys/kernel/osrelease 2>/dev/null \
    && log "WSL detected — nginx binds IPv4 0.0.0.0 by default, Windows localhost forwarding will work."

# ----------------------------------------------------------------------------- 7. validate + service up
log "validating config (openresty -t)…"
$SUDO /usr/local/openresty/bin/openresty -t || die "invalid config — fix and re-run"

log "enabling + (re)starting openresty…"
$SUDO systemctl enable openresty >/dev/null 2>&1 || true
$SUDO systemctl restart openresty

READY=0
for _ in $(seq 1 15); do
    if $SUDO systemctl is-active --quiet openresty; then READY=1; break; fi
    sleep 1
done
[ "$READY" = "1" ] || die "openresty did not come up — check: journalctl -u openresty -e"

# ----------------------------------------------------------------------------- summary
cat <<SUMMARY

$(printf '\033[1;32m✓ OpenResty (nginx + Lua) installed and running.\033[0m')

  Main config:  ${MAIN}
  Tuning:       ${TUNING}   (map + TLS session cache + resolver)
  Sites:        /etc/nginx/sites-available/*  ->  sites-enabled/*   (per-domain)
  Dev cert:     ${CRT}
  Service:      systemctl status openresty   |   journalctl -u openresty -e

Next — generate a per-domain site config and reload:

  sudo php fluffy nginx your.domain.com

For a PUBLIC primary domain, replace the dev self-signed cert with a real one
(certbot --nginx, or acme.sh) — see docs/custom-domains-plan.md for the
control-plane TLS model used for branded customer domains.
SUMMARY
