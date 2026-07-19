#!/usr/bin/env bash
#
# Issue (or renew) a Let's Encrypt cert for ONE custom domain via HTTP-01 webroot, then push it
# into Redis for the OpenResty per-SNI Lua edge (tls:cert:<host> / tls:key:<host>).
#
# UNPRIVILEGED — runs as the APP USER (www-data). Called automatically by the app on domain Verify
# (CustomDomainCertIssuer, off the request path in a Swoole task); can also be run by hand:
#   sudo -u www-data bash issue-custom-domain-cert.sh <host> [redis-port=6379] [webroot=/var/www/acme]
#
# Idempotent + renewal-safe: acme.sh SAVES the --reloadcmd and re-runs it on every automatic renewal
# (its own cron, as the app user), so a renewed cert is re-pushed to Redis with no app involvement.
# First issuance replaces the self-signed placeholder; the edge L1 cache (1h TTL) picks it up within
# the hour, or restart openresty once for an instant cutover.
#
set -uo pipefail

HOST="${1:-}"
if [ -z "$HOST" ]; then
  echo "usage: issue-custom-domain-cert.sh <host> [redis-port] [webroot]" >&2
  exit 1
fi
REDIS_PORT="${2:-6378}"   # the DB pool (noeviction + persistent), NOT the 6379 cache (evicts!)
WEBROOT="${3:-/var/www/acme}"
ACME_HOME="${ACME_HOME:-/var/lib/acme}"
ACME="${ACME_HOME}/acme.sh"
CERT_DIR="${ACME_HOME}/certs"

if [ ! -f "$ACME" ]; then
  echo "acme.sh not found at ${ACME} — run install-acme-ubuntu24.sh first" >&2
  exit 1
fi
export HOME="$ACME_HOME"   # acme.sh keys its state off HOME
mkdir -p "$CERT_DIR"

# Serialize per-host issuance. Two acme.sh runs sharing $ACME_HOME race on the SAME order/account
# files and corrupt each other — the loser hits finalize after the order is already valid and dies
# with "orderNotReady" (403), which would otherwise clobber a perfectly-good issued cert. flock waits
# for any in-flight run for THIS host; the second caller then finds the cert already valid (acme rc=2)
# and just re-deploys it — idempotent, so both callers succeed instead of one wrecking the other.
LOCK="${ACME_HOME}/.issue.${HOST}.lock"
exec 9>"$LOCK" || { echo "[cert] cannot open lock ${LOCK}" >&2; exit 1; }
if ! flock -w 300 9; then
  echo "[cert] timed out (300s) waiting for another in-flight issuance of ${HOST}" >&2
  exit 1
fi

echo "[cert] issuing ${HOST} via HTTP-01 (webroot ${WEBROOT})..."
# rc 0 = issued; 2 = already valid / not yet due (skip). Both fine — install-cert (re)deploys whatever
# exists. Any other code is a real failure.
"$ACME" --home "$ACME_HOME" --issue -d "$HOST" -w "$WEBROOT" --server letsencrypt --keylength ec-256
rc=$?
if [ "$rc" -ne 0 ] && [ "$rc" -ne 2 ]; then
  echo "[cert] acme.sh --issue failed (rc=${rc})" >&2
  exit "$rc"
fi

# Deploy to stable paths + a reloadcmd that (re)pushes to Redis — runs now AND on every renewal.
"$ACME" --home "$ACME_HOME" --install-cert -d "$HOST" --ecc \
  --fullchain-file "${CERT_DIR}/${HOST}.pem" \
  --key-file "${CERT_DIR}/${HOST}.key" \
  --reloadcmd "redis-cli -p ${REDIS_PORT} -x set tls:cert:${HOST} < ${CERT_DIR}/${HOST}.pem && redis-cli -p ${REDIS_PORT} -x set tls:key:${HOST} < ${CERT_DIR}/${HOST}.key"

echo "[cert] ${HOST}: issued + pushed to Redis :${REDIS_PORT} (tls:cert:${HOST} / tls:key:${HOST})"
