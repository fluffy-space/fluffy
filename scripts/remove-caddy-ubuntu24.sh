#!/usr/bin/env bash
#
# remove-caddy-ubuntu24.sh
#
# Remove the Caddy edge proxy from an Ubuntu box after switching to OpenResty
# (nginx + Lua). Caddy and nginx both bind :80/:443, so Caddy must be gone (or at
# least stopped) before OpenResty can take the ports. This backs up the Caddy
# config + its cert/data store, stops+disables the service, frees the ports, and
# (unless --keep-data) purges the package and apt repo.
#
# The Caddy-managed certs live in Caddy's data dir (/var/lib/caddy). We back them
# up but do NOT reuse them — OpenResty re-issues via certbot/acme (primary) or the
# control-plane Lua path (custom domains); see docs/custom-domains-plan.md.
#
# Idempotent: safe to run when Caddy is already gone.
#
# Usage:
#   sudo bash scripts/remove-caddy-ubuntu24.sh              # stop, back up, purge
#   sudo bash scripts/remove-caddy-ubuntu24.sh --keep-data  # stop + disable only; keep pkg+config for rollback
#   sudo bash scripts/remove-caddy-ubuntu24.sh -y           # no confirmation prompt
#
set -euo pipefail

KEEP_DATA=0
ASSUME_YES=0
while [ $# -gt 0 ]; do
    case "$1" in
        --keep-data) KEEP_DATA=1; shift ;;
        -y|--yes)    ASSUME_YES=1; shift ;;
        -h|--help)   grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;36m[remove-caddy]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[remove-caddy] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[remove-caddy] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo"; command -v sudo >/dev/null || die "need root or sudo"; fi

if ! command -v caddy >/dev/null 2>&1 && [ ! -d /etc/caddy ] && [ ! -d /var/lib/caddy ]; then
    log "no Caddy install found — nothing to remove."
    exit 0
fi

# ----------------------------------------------------------------------------- backup
BACKUP="/root/caddy-removed-$(date +%Y%m%d-%H%M%S 2>/dev/null || echo backup)"
log "backing up Caddy config + data -> ${BACKUP}"
$SUDO mkdir -p "$BACKUP"
[ -d /etc/caddy ]     && $SUDO cp -a /etc/caddy     "$BACKUP/etc-caddy"     2>/dev/null || true
[ -d /var/lib/caddy ] && $SUDO cp -a /var/lib/caddy "$BACKUP/var-lib-caddy" 2>/dev/null || true

if [ "$ASSUME_YES" != "1" ]; then
    if [ "$KEEP_DATA" = "1" ]; then
        printf 'Stop + disable Caddy (keep package/config for rollback)? [y/N] '
    else
        printf 'Stop, disable and PURGE Caddy (backup at %s)? [y/N] ' "$BACKUP"
    fi
    read -r ans
    case "$ans" in y|Y|yes|YES) : ;; *) die "aborted (backup kept at $BACKUP)";; esac
fi

# ----------------------------------------------------------------------------- stop + disable
if systemctl list-unit-files 2>/dev/null | grep -q '^caddy\.service'; then
    log "stopping + disabling caddy service…"
    $SUDO systemctl stop caddy 2>/dev/null || true
    $SUDO systemctl disable caddy >/dev/null 2>&1 || true
fi

if [ "$KEEP_DATA" = "1" ]; then
    log "left the caddy package + /etc/caddy in place (--keep-data)."
    log "rollback: sudo systemctl enable --now caddy   (stop OpenResty first to free 80/443)"
    printf '\n\033[1;32m✓ Caddy stopped + disabled. Ports 80/443 are free for OpenResty.\033[0m\n'
    printf '  Backup: %s\n' "$BACKUP"
    exit 0
fi

# ----------------------------------------------------------------------------- purge
log "purging the caddy package…"
export DEBIAN_FRONTEND=noninteractive
$SUDO apt-get purge -y -qq caddy >/dev/null 2>&1 || true
$SUDO apt-get autoremove -y -qq >/dev/null 2>&1 || true

log "removing Caddy apt repo + keyring…"
$SUDO rm -f /etc/apt/sources.list.d/caddy-stable.list
$SUDO rm -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg
$SUDO apt-get update -qq >/dev/null 2>&1 || true

log "removing leftover config/data dirs (backed up above)…"
$SUDO rm -rf /etc/caddy /var/lib/caddy

# ----------------------------------------------------------------------------- verify ports free
if command -v ss >/dev/null 2>&1 && $SUDO ss -ltnp 2>/dev/null | grep -qE ':(80|443)\b.*caddy'; then
    warn "something named 'caddy' still holds :80/:443 — check: sudo ss -ltnp | grep -E ':(80|443)'"
fi

printf '\n\033[1;32m✓ Caddy removed. Ports 80/443 are free.\033[0m\n'
printf '  Backup: %s\n' "$BACKUP"
printf '\nNext — bring up OpenResty and reload your sites:\n\n'
printf '  sudo bash scripts/install-openresty-ubuntu24.sh\n'
printf '  sudo php fluffy nginx your.domain.com\n'
