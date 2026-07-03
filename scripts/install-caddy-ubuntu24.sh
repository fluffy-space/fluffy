#!/usr/bin/env bash
#
# install-caddy-ubuntu24.sh
#
# Install + start the Caddy web server on Ubuntu 24.04 (noble) for PRODUCTION.
# Caddy is the edge reverse proxy in front of the Swoole app (replaces nginx) and
# issues/renews TLS certs automatically via Let's Encrypt.
#
# Handles the OS/server side only: apt repo, package, a managed main Caddyfile
# (global options + `import sites/*.caddy`), firewall, systemd service. Per-domain
# site files are generated afterwards by:  sudo FLUFFY_CADDY_TLS=auto php fluffy caddy <domain>
#
# Idempotent and re-runnable; safe on both a fresh prod box and an existing one.
#
# What it does:
#   1. Adds the official Caddy apt repo (signed) and installs the caddy package
#      (its systemd unit already has CAP_NET_BIND_SERVICE for ports 80/443).
#   2. Writes a managed /etc/caddy/Caddyfile: email (ACME), grace_period, admin
#      endpoint, and `import /etc/caddy/sites/*.caddy`; creates /etc/caddy/sites.
#   3. Opens 80/443 if ufw is active.
#   4. Enables + (re)starts the service and waits for the admin API to answer.
#
# Usage:
#   sudo bash scripts/install-caddy-ubuntu24.sh --email you@example.com
#   sudo CADDY_EMAIL=you@example.com bash scripts/install-caddy-ubuntu24.sh
#   sudo bash scripts/install-caddy-ubuntu24.sh --email you@example.com --version 2.8.4
#
# Configuration (env vars or flags; flags win):
#   CADDY_EMAIL    --email    ACME account email (renewal notices)   (recommended)
#   CADDY_ADMIN    --admin    admin API endpoint                     (default: 127.0.0.1:2019)
#   CADDY_VERSION  --version  pin a caddy version                    (default: latest stable)
#
set -euo pipefail

# ----------------------------------------------------------------------------- defaults
CADDY_EMAIL="${CADDY_EMAIL:-}"
CADDY_ADMIN="${CADDY_ADMIN:-127.0.0.1:2019}"
CADDY_VERSION="${CADDY_VERSION:-}"

# ----------------------------------------------------------------------------- flag parsing
while [ $# -gt 0 ]; do
    case "$1" in
        --email)   CADDY_EMAIL="$2"; shift 2 ;;
        --admin)   CADDY_ADMIN="$2"; shift 2 ;;
        --version) CADDY_VERSION="$2"; shift 2 ;;
        -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;36m[caddy]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[caddy] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[caddy] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

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
[ -n "$CADDY_EMAIL" ] || warn "no --email given — Caddy will still issue certs, but you'll miss expiry/renewal notices. Recommended for prod."

# ----------------------------------------------------------------------------- 1. apt repo + install
log "installing prerequisites…"
export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get update -qq
$SUDO apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https ca-certificates curl gnupg >/dev/null

KEYRING=/usr/share/keyrings/caddy-stable-archive-keyring.gpg
if [ ! -s "$KEYRING" ]; then
    log "adding Caddy signing key…"
    curl -1fsSL 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | $SUDO gpg --dearmor -o "$KEYRING"
    $SUDO chmod a+r "$KEYRING"
fi

REPO_FILE=/etc/apt/sources.list.d/caddy-stable.list
if [ ! -s "$REPO_FILE" ]; then
    log "adding Caddy apt repo…"
    curl -1fsSL 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        | $SUDO tee "$REPO_FILE" >/dev/null
fi

$SUDO apt-get update -qq
if [ -n "$CADDY_VERSION" ]; then
    log "installing caddy version $CADDY_VERSION…"
    $SUDO apt-get install -y -qq "caddy=$CADDY_VERSION" >/dev/null
else
    log "installing caddy (latest stable)…"
    $SUDO apt-get install -y -qq caddy >/dev/null
fi
log "installed: $(caddy version 2>/dev/null || echo caddy)"

# ----------------------------------------------------------------------------- 2. managed main Caddyfile
$SUDO mkdir -p /etc/caddy/sites
MAIN=/etc/caddy/Caddyfile
IMPORT_LINE='import /etc/caddy/sites/*.caddy'

# Detect WSL: its localhost-forwarding relay only mirrors IPv4 (0.0.0.0) listeners
# onto the Windows host's 127.0.0.1. Caddy's default bind is a dual-stack ::/[::]
# socket, which the relay ignores — so Windows can't reach Caddy at all. The tcp4/
# network prefix forces a true AF_INET socket. (Bare `default_bind 0.0.0.0` is NOT
# enough: Caddy normalizes it back to a dual-stack socket.) Real Linux servers skip
# this so they keep serving IPv6.
IS_WSL=0
grep -qiE 'microsoft|wsl' /proc/sys/kernel/osrelease 2>/dev/null && IS_WSL=1

# Build the global options block (email only if provided).
GLOBAL="{"$'\n'
[ -n "$CADDY_EMAIL" ] && GLOBAL+=$'\t'"email ${CADDY_EMAIL}"$'\n'
GLOBAL+=$'\t'"grace_period 30s"$'\n'
GLOBAL+=$'\t'"admin ${CADDY_ADMIN}"$'\n'
[ "$IS_WSL" = "1" ] && GLOBAL+=$'\t'"default_bind tcp4/0.0.0.0   # WSL: IPv4-only so Windows localhost-forwarding can reach Caddy"$'\n'
GLOBAL+="}"

if [ ! -s "$MAIN" ] || ! grep -q '^import /etc/caddy/sites/' "$MAIN" 2>/dev/null; then
    # Fresh box (or the stock package Caddyfile that just serves a welcome page):
    # replace it with our managed version. Back up anything non-trivial first.
    if [ -s "$MAIN" ] && ! grep -q 'welcome' "$MAIN" 2>/dev/null && ! grep -q '^import /etc/caddy/sites/' "$MAIN" 2>/dev/null; then
        $SUDO cp "$MAIN" "${MAIN}.bak.$(date +%s 2>/dev/null || echo pre)" 2>/dev/null || true
        warn "existing $MAIN backed up before replacing"
    fi
    log "writing managed $MAIN"
    printf '%s\n\n%s\n' "$GLOBAL" "$IMPORT_LINE" | $SUDO tee "$MAIN" >/dev/null
else
    log "$MAIN already managed (import present) — leaving global options untouched"
    [ -n "$CADDY_EMAIL" ] && grep -q "email ${CADDY_EMAIL}" "$MAIN" 2>/dev/null \
        || warn "set 'email ${CADDY_EMAIL:-you@example.com}' in the global block of $MAIN if not already there"
    # On WSL, ensure the IPv4-only bind is present even in a pre-existing global block
    # (see note above; the restart below rebinds the socket family).
    if [ "$IS_WSL" = "1" ] && ! grep -q 'default_bind' "$MAIN" 2>/dev/null; then
        log "WSL: adding 'default_bind tcp4/0.0.0.0' to the existing global block in $MAIN"
        $SUDO sed -i '/grace_period 30s/a\\tdefault_bind tcp4/0.0.0.0   # WSL: IPv4-only so Windows localhost-forwarding can reach Caddy' "$MAIN"
    fi
fi

# ----------------------------------------------------------------------------- 3. firewall
if command -v ufw >/dev/null 2>&1 && $SUDO ufw status 2>/dev/null | grep -q "Status: active"; then
    log "ufw active — allowing 80/tcp and 443/tcp…"
    $SUDO ufw allow 80/tcp   >/dev/null 2>&1 || true
    $SUDO ufw allow 443/tcp  >/dev/null 2>&1 || true
fi

# ----------------------------------------------------------------------------- 4. validate + service up + readiness
log "validating $MAIN…"
$SUDO caddy validate --config "$MAIN" >/dev/null 2>&1 || die "invalid Caddyfile — fix $MAIN and re-run"

log "enabling + (re)starting caddy…"
$SUDO systemctl enable caddy >/dev/null 2>&1 || true
$SUDO systemctl restart caddy

log "waiting for the admin API on ${CADDY_ADMIN}…"
READY=0
for _ in $(seq 1 20); do
    if curl -fsS "http://${CADDY_ADMIN}/config/" >/dev/null 2>&1; then READY=1; break; fi
    sleep 1
done
[ "$READY" = "1" ] || die "admin API did not answer — check: journalctl -u caddy -e"

# ----------------------------------------------------------------------------- summary
cat <<SUMMARY

$(printf '\033[1;32m✓ Caddy installed and running.\033[0m')

  Main config:  ${MAIN}
  Site files:   /etc/caddy/sites/*.caddy   (generated per-domain)
  Admin API:    http://${CADDY_ADMIN}
  Service:      systemctl status caddy   |   journalctl -u caddy -e

Next — generate a site config and go live (real Let's Encrypt certs):

  sudo FLUFFY_CADDY_TLS=auto php fluffy caddy your.domain.com

The domain's DNS A/AAAA record must already point at THIS server, and ports
80/443 must be reachable, or the ACME challenge can't complete.
SUMMARY
