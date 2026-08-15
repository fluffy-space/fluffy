#!/usr/bin/env bash
#
# Prep a box for UNPRIVILEGED, app-triggered custom-domain TLS issuance (Option A):
# acme.sh + the ACME webroot are owned by the APP USER (default www-data), so the running
# app (Swoole task) can issue/renew certs and push them to Redis WITHOUT root or sudo at
# runtime. Feeds the OpenResty per-SNI Lua edge (bin/setup/nginx-custom-domains.conf).
#
# Run ONCE as root (it only does root-level provisioning; issuance afterwards is unprivileged):
#   sudo bash install-acme-ubuntu24.sh <account-email> [app-user=www-data]
#
set -uo pipefail

EMAIL="${1:-}"
# CRITICAL: APP_USER must be the OS user the APP PROCESS runs as (the one that will exec acme.sh
# from the Swoole task) — NOT necessarily www-data. On a systemd/prod box that's usually www-data;
# on a dev/WSL box the server often runs as the login user (e.g. ivan). If this doesn't match, the
# app's acme.sh runs as a user that can't write ${ACME_HOME} -> "account.conf: Permission denied",
# "mkdir: cannot create directory '.../ca': Permission denied", issuance fails.
APP_USER="${2:-www-data}"
if [ -z "$EMAIL" ]; then
  echo "usage: sudo bash install-acme-ubuntu24.sh <account-email> [app-user=www-data]" >&2
  echo "  app-user MUST be the user the app process runs as (check: ps -o user= -p <swoole-pid>)." >&2
  exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
  echo "run as root (sudo) — this does the one-time root provisioning only" >&2
  exit 1
fi
if ! id -u "${APP_USER}" >/dev/null 2>&1; then
  echo "[acme] ERROR: app-user '${APP_USER}' does not exist. Pass the user the app runs as." >&2
  exit 1
fi

ACME_HOME="/var/lib/acme"      # acme.sh home + issued-cert store, owned by the app user
WEBROOT="/var/www/acme"        # HTTP-01 challenge webroot, served by nginx, written by the app user

echo "[acme] app will issue certs as OS user '${APP_USER}' — this MUST match the app's runtime user."

echo "[acme] deps..."
apt-get update -qq
apt-get install -y -qq curl socat cron redis-tools >/dev/null

echo "[acme] app-user-owned dirs (${APP_USER}): ${ACME_HOME}, ${WEBROOT} ..."
mkdir -p "${ACME_HOME}" "${WEBROOT}/.well-known/acme-challenge" "${ACME_HOME}/certs"
chown -R "${APP_USER}:${APP_USER}" "${ACME_HOME}" "${WEBROOT}"

if [ -e /etc/nginx/sites-enabled/default ]; then
  echo "[acme] retiring stock Debian default site (custom-domains conf owns the default_server)..."
  rm -f /etc/nginx/sites-enabled/default
fi

if [ ! -f "${ACME_HOME}/acme.sh" ]; then
  echo "[acme] installing acme.sh as ${APP_USER} (home ${ACME_HOME}, account ${EMAIL})..."
  # Install AS the app user so ~/.acme.sh + the renewal cron belong to it (no root at runtime).
  # The get.acme.sh installer downloads master.tar.gz into the CURRENT directory, so we run it
  # from a ${APP_USER}-writable workdir — otherwise root's CWD (e.g. /root) yields
  # "cannot create master.tar.gz: Permission denied" and the download aborts.
  #
  # Arg convention (get.acme.sh, NOT acme.sh): the FIRST positional is the account email as a
  # bare "email=<addr>" (it strips '=' and prepends '--' internally); everything AFTER it is
  # forwarded verbatim to `acme.sh --install`. Passing "--email"/"--home" as the first arg
  # mangles it (e.g. "--home" -> "----home", "Unknown parameter"). So: email= first, then --home.
  INSTALL_TMP="$(sudo -u "${APP_USER}" mktemp -d)"
  ( cd "${INSTALL_TMP}" && curl -s https://get.acme.sh | sudo -u "${APP_USER}" HOME="${ACME_HOME}" sh -s -- \
    "email=${EMAIL}" --home "${ACME_HOME}" )
  rm -rf "${INSTALL_TMP}"
  if [ ! -f "${ACME_HOME}/acme.sh" ]; then
    echo "[acme] ERROR: acme.sh install failed (no ${ACME_HOME}/acme.sh). Check the output above." >&2
    exit 1
  fi
else
  echo "[acme] acme.sh already present at ${ACME_HOME}."
fi
sudo -u "${APP_USER}" HOME="${ACME_HOME}" "${ACME_HOME}/acme.sh" --home "${ACME_HOME}" \
  --set-default-ca --server letsencrypt >/dev/null 2>&1 || true

echo
echo "[acme] done (root part). Issuance + renewal now run as ${APP_USER}, no root."
echo "  Next: install nginx-custom-domains.conf + reload, then the app issues on Verify"
echo "  (or manually: sudo -u ${APP_USER} bash scripts/issue-custom-domain-cert.sh <host>)."
