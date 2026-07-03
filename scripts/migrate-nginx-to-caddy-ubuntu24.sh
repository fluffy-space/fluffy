#!/usr/bin/env bash
#
# migrate-nginx-to-caddy-ubuntu24.sh
#
# Migrate an existing nginx setup to Caddy on Ubuntu 24.04, in four steps:
#   1. SAVE    — back up nginx configs and translate each proxied site into a
#                Caddy site file (staged, not yet installed).
#   2. UNINSTALL — stop + disable + purge nginx and free ports 80/443.
#   3. INSTALL — run install-caddy-ubuntu24.sh (official repo, managed Caddyfile).
#   4. SETUP   — drop the staged site files into /etc/caddy/sites, validate, reload.
#
# Only sites that PROXY to an app (have an upstream / proxy_pass) are translated;
# static-only sites (e.g. nginx's `default` welcome page) are skipped with a note.
# The translation mirrors the fluffy nginx template: repo root -> external static
# folder -> app proxy, with long-cache headers on static assets.
#
# DESTRUCTIVE (removes nginx). Everything is backed up first; prompts unless -y.
#
# Usage:
#   sudo bash scripts/migrate-nginx-to-caddy-ubuntu24.sh --email you@example.com
#   sudo bash scripts/migrate-nginx-to-caddy-ubuntu24.sh --tls internal -y   # dev/self-signed
#   sudo bash scripts/migrate-nginx-to-caddy-ubuntu24.sh --keep-nginx        # stop+disable, don't purge
#
# Configuration (env vars or flags; flags win):
#   MIG_EMAIL    --email       ACME email, passed to the installer      (recommended)
#   MIG_TLS      --tls         internal | auto                          (default: auto)
#   MIG_VERSION  --version     pin a caddy version                      (default: latest)
#   MIG_YES      -y|--yes      skip the confirmation prompt             (default: prompt)
#                --keep-nginx  stop+disable nginx instead of purging    (default: purge)
#
set -euo pipefail

# ----------------------------------------------------------------------------- defaults
MIG_EMAIL="${MIG_EMAIL:-}"
MIG_TLS="${MIG_TLS:-auto}"
MIG_VERSION="${MIG_VERSION:-}"
MIG_YES="${MIG_YES:-0}"
KEEP_NGINX=0

# ----------------------------------------------------------------------------- flag parsing
while [ $# -gt 0 ]; do
    case "$1" in
        --email)      MIG_EMAIL="$2"; shift 2 ;;
        --tls)        MIG_TLS="$2"; shift 2 ;;
        --version)    MIG_VERSION="$2"; shift 2 ;;
        -y|--yes)     MIG_YES=1; shift ;;
        --keep-nginx) KEEP_NGINX=1; shift ;;
        -h|--help)    grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;36m[migrate]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[migrate] WARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[migrate] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo"; command -v sudo >/dev/null || die "need root or sudo"; fi
case "$MIG_TLS" in internal|auto) : ;; *) die "--tls must be 'internal' or 'auto'";; esac

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
INSTALLER="$SCRIPT_DIR/install-caddy-ubuntu24.sh"
[ -f "$INSTALLER" ] || die "installer not found next to this script: $INSTALLER"

NGINX_AVAIL=/etc/nginx/sites-available
NGINX_ENABLED=/etc/nginx/sites-enabled
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/root/nginx-to-caddy-$STAMP"
STAGE="$BACKUP/caddy-sites"

# TLS directive per generated site.
if [ "$MIG_TLS" = "auto" ]; then
    TLS_LINE="# automatic HTTPS via Let's Encrypt (email in /etc/caddy/Caddyfile)"
else
    TLS_LINE="tls internal   # self-signed via Caddy's internal CA (dev)"
fi

# Emit a Caddy site file to stdout. Args: <domains> <root> <static_root> <port>
emit_caddy_site() {
    local domains="$1" root="$2" staticRoot="$3" port="$4"
    cat <<CADDY
${domains} {
  ${TLS_LINE}

  # Fingerprint hardening — strip stack-identifying response headers so nothing
  # reveals PHP / Swoole / the proxy. Applies site-wide (proxied + static).
  header {
    -Server         # Swoole emits "swoole-http-server"
    -X-Powered-By   # in case any PHP SAPI adds it
    -Via            # Caddy proxy marker
    -Server-Timing  # internal app timing hint
  }

  encode zstd gzip

  root * ${root}

  @static path *.ogg *.ogv *.svg *.svgz *.eot *.otf *.woff *.woff2 *.mp4 *.ttf *.css *.rss *.atom *.js *.json *.jpg *.jpeg *.gif *.png *.ico *.zip *.tgz *.gz *.rar *.bz2 *.doc *.xls *.ppt *.tar *.mid *.midi *.wav *.bmp *.rtf

  @repoFile file
  handle @repoFile {
    header @static Cache-Control "public, max-age=31536000, immutable"
    file_server
  }

  @extFile file {
    root ${staticRoot}
    try_files {path}
  }
  handle @extFile {
    root * ${staticRoot}
    header @static Cache-Control "public, max-age=31536000, immutable"
    file_server
  }

  handle {
    reverse_proxy 127.0.0.1:${port} {
      header_up Host {host}
    }
  }
}
CADDY
}

# ============================================================================= 0. preflight
[ -d "$NGINX_ENABLED" ] || [ -d "$NGINX_AVAIL" ] || die "no nginx sites dir found — is nginx installed?"
. /etc/os-release 2>/dev/null || true
case "${VERSION_ID:-}" in 24.*) : ;; *) warn "targeted at Ubuntu 24.04; detected '${VERSION_ID:-?}' — continuing" ;; esac
[ "$MIG_TLS" = "auto" ] && [ -z "$MIG_EMAIL" ] && warn "TLS=auto without --email: certs still issue, but no renewal notices."

# Prefer enabled sites; fall back to available.
SRC_DIR="$NGINX_ENABLED"; [ -d "$SRC_DIR" ] && [ -n "$(ls -A "$SRC_DIR" 2>/dev/null)" ] || SRC_DIR="$NGINX_AVAIL"
log "reading nginx sites from $SRC_DIR"

# ============================================================================= 1. SAVE + translate
$SUDO mkdir -p "$STAGE"
log "backing up nginx configs to $BACKUP …"
$SUDO cp -a /etc/nginx "$BACKUP/nginx" 2>/dev/null || warn "could not copy /etc/nginx wholesale"

TRANSLATED=0; SKIPPED=""
for f in "$SRC_DIR"/*; do
    [ -e "$f" ] || continue
    rf="$(readlink -f "$f")"; [ -f "$rf" ] || continue
    base="$(basename "$f")"

    # Port from `upstream { server 127.0.0.1:PORT }` or a direct proxy_pass.
    port="$(grep -oP 'server\s+127\.0\.0\.1:\K[0-9]+' "$rf" | head -1 || true)"
    [ -z "$port" ] && port="$(grep -oP 'proxy_pass\s+https?://[^:/;]+:\K[0-9]+' "$rf" | head -1 || true)"
    if [ -z "$port" ]; then
        SKIPPED="$SKIPPED $base(no-upstream)"; continue
    fi

    # Domains (dedup, drop the `_` catch-all).
    domains="$(grep -oP '^\s*server_name\s+\K[^;]+' "$rf" | tr ' ' '\n' | sed '/^$/d' | sort -u | grep -v '^_$' | tr '\n' ' ' | sed 's/[[:space:]]*$//')"
    if [ -z "$domains" ]; then
        SKIPPED="$SKIPPED $base(no-server_name)"; continue
    fi

    # Roots: first `root` = repo root; second (if any) = external static folder.
    mapfile -t roots < <(grep -oP '^\s*root\s+\K[^;]+' "$rf" | sed 's/[[:space:]]*$//')
    root="${roots[0]:-}"
    staticRoot="${roots[1]:-${roots[0]:-}}"
    [ -n "$root" ] || { SKIPPED="$SKIPPED $base(no-root)"; continue; }

    # Name the Caddy file after the first domain.
    first_domain="${domains%% *}"
    out="$STAGE/$first_domain.caddy"
    emit_caddy_site "$domains" "$root" "$staticRoot" "$port" | $SUDO tee "$out" >/dev/null
    log "translated: $domains  (port $port, root $root, static $staticRoot)"
    TRANSLATED=$((TRANSLATED + 1))
done

[ "$TRANSLATED" -gt 0 ] || die "no proxied nginx sites found to translate (nothing staged) — aborting before touching nginx"
[ -n "$SKIPPED" ] && warn "skipped (not app-proxy sites):$SKIPPED"
log "staged $TRANSLATED Caddy site file(s) in $STAGE"

# ============================================================================= confirm
if [ "$MIG_YES" != "1" ]; then
    printf '\033[1;33m'
    printf 'About to %s nginx and switch %s domain(s) to Caddy (TLS=%s).\n' \
        "$([ "$KEEP_NGINX" = 1 ] && echo 'stop+disable' || echo 'PURGE')" "$TRANSLATED" "$MIG_TLS"
    printf 'Backup: %s\n\033[0m' "$BACKUP"
    read -r -p "Proceed? [y/N] " ans
    case "$ans" in y|Y|yes|YES) : ;; *) die "aborted by user (nothing changed; backup + staged files kept in $BACKUP)";; esac
fi

# ============================================================================= 2. UNINSTALL nginx / free ports
log "stopping nginx …"
$SUDO systemctl stop nginx 2>/dev/null || true
$SUDO systemctl disable nginx 2>/dev/null || true
if [ "$KEEP_NGINX" = "1" ]; then
    log "--keep-nginx: nginx stopped + disabled (not purged)."
else
    log "purging nginx packages …"
    export DEBIAN_FRONTEND=noninteractive
    $SUDO apt-get purge -y -qq 'nginx*' >/dev/null 2>&1 || $SUDO apt-get remove -y -qq nginx nginx-common nginx-core >/dev/null 2>&1 || warn "apt purge nginx reported an issue — continuing"
    $SUDO apt-get autoremove -y -qq >/dev/null 2>&1 || true
fi

# Confirm 80/443 are free before Caddy tries to bind them.
sleep 1
if command -v ss >/dev/null 2>&1; then
    if $SUDO ss -ltn '( sport = :80 or sport = :443 )' 2>/dev/null | grep -q LISTEN; then
        warn "something is still listening on 80/443:"; $SUDO ss -ltnp '( sport = :80 or sport = :443 )' 2>/dev/null || true
        warn "Caddy may fail to bind — free these ports, then: sudo caddy reload --config /etc/caddy/Caddyfile"
    else
        log "ports 80/443 are free."
    fi
fi

# ============================================================================= 3. INSTALL Caddy
log "installing Caddy via $INSTALLER …"
INSTALL_ARGS=""
[ -n "$MIG_EMAIL" ]   && INSTALL_ARGS="$INSTALL_ARGS --email $MIG_EMAIL"
[ -n "$MIG_VERSION" ] && INSTALL_ARGS="$INSTALL_ARGS --version $MIG_VERSION"
# shellcheck disable=SC2086
$SUDO bash "$INSTALLER" $INSTALL_ARGS

# ============================================================================= 4. SETUP sites in Caddy
$SUDO mkdir -p /etc/caddy/sites
log "installing staged site files into /etc/caddy/sites …"
$SUDO cp -f "$STAGE"/*.caddy /etc/caddy/sites/

log "validating combined Caddy config …"
if ! $SUDO caddy validate --config /etc/caddy/Caddyfile >/dev/null 2>&1; then
    warn "combined config failed validation — staged files are in $STAGE and /etc/caddy/sites."
    warn "Fix the offending site file, then: sudo caddy reload --config /etc/caddy/Caddyfile"
    die "validation failed"
fi

log "reloading Caddy (graceful, admin API) …"
$SUDO caddy reload --config /etc/caddy/Caddyfile 2>&1 || $SUDO systemctl restart caddy

# ============================================================================= summary
cat <<SUMMARY

$(printf '\033[1;32m✓ Migrated %s site(s) from nginx to Caddy.\033[0m' "$TRANSLATED")

  TLS mode:     ${MIG_TLS}$( [ "$MIG_TLS" = auto ] && echo '  (real Let'\''s Encrypt — DNS must point here + 80/443 reachable)' )
  Site files:   /etc/caddy/sites/*.caddy
  Backup:       ${BACKUP}   (original /etc/nginx + staged Caddy files)
  Service:      systemctl status caddy   |   journalctl -u caddy -e

Verify each domain, e.g.:  curl -kI https://<your-domain>/
Rollback (if kept):        sudo systemctl stop caddy && sudo systemctl enable --now nginx
$( [ "$KEEP_NGINX" = 1 ] || echo 'Rollback after purge: reinstall nginx and restore configs from the backup above.' )
SUMMARY
