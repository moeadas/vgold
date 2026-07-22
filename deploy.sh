#!/bin/bash
# ============================================================================
# VGold — FTP(S) deploy to vgold.victorygenomics.com  (SiteGround)
# Mirrors the repo into public_html/ so the root .htaccess routes to public/.
# vendor/ and crm/vendor/ are NOT in git and ARE uploaded here because this is
# a direct FTP deploy, not a git checkout.
#
# CREDENTIALS ARE NEVER STORED IN THIS FILE. Put them in a gitignored
# deploy.env next to this script (copy deploy.env.example), or export
# DEPLOY_USER / DEPLOY_PASS in the environment before running.
#
# Server-side secret configs (config/database.sg.php, app_key.php, graph.php,
# push.php, crm/config/.env, config/setup_secret.php) are EXCLUDED from the
# mirror: they live on the server only and are managed there directly. This
# also protects them from `--delete` when deploying from a fresh checkout.
# ============================================================================
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Credentials ──────────────────────────────────────────────────────────────
if [ -f "$SCRIPT_DIR/deploy.env" ]; then
    # shellcheck disable=SC1091
    . "$SCRIPT_DIR/deploy.env"
fi
HOST="${DEPLOY_HOST:-ftp.victorygenomics.com}"
USER="${DEPLOY_USER:-}"
PASS="${DEPLOY_PASS:-}"
PORT="${DEPLOY_PORT:-21}"
LOCAL="${DEPLOY_LOCAL:-$SCRIPT_DIR}"
REMOTE="${DEPLOY_REMOTE:-/public_html}"
VERIFY_CERT="${DEPLOY_VERIFY_CERT:-yes}"

if [ -z "$USER" ] || [ -z "$PASS" ]; then
    echo "ERROR: DEPLOY_USER / DEPLOY_PASS are not set."
    echo "       Copy deploy.env.example to deploy.env (gitignored) and fill it in,"
    echo "       or export DEPLOY_USER and DEPLOY_PASS before running."
    exit 1
fi

# ── Safety guard ─────────────────────────────────────────────────────────────
# A fresh git checkout has no vendor/ dirs. Mirroring with --delete from such a
# checkout would DELETE vendor/ on the server and take the whole site down.
if [ ! -d "$LOCAL/vendor" ] || [ ! -d "$LOCAL/crm/vendor" ]; then
    echo "ERROR: $LOCAL/vendor and/or $LOCAL/crm/vendor are missing."
    echo "       Run 'composer install' (root and crm/) first — deploying without"
    echo "       them would delete them from the server (--delete mirror)."
    exit 1
fi

# Optional first arg: "sql" also uploads the big migration dump (default skips it
# so ordinary code deploys stay fast; the dump is uploaded once via deploy_sql.sh).
INCLUDE_SQL="${1:-nosql}"

SQL_EXCLUDE="--exclude migration/sql/"
if [ "$INCLUDE_SQL" = "sql" ]; then SQL_EXCLUDE=""; fi

lftp -u "$USER","$PASS" "ftp://$HOST:$PORT" <<LFTP
set ssl:verify-certificate $VERIFY_CERT
set ftp:ssl-force true
set ftp:ssl-protect-data true
set net:timeout 30
set net:max-retries 3
set net:reconnect-interval-base 5
set mirror:parallel-transfer-count 4
set mirror:use-pget-n 1
lcd $LOCAL
cd $REMOTE
mirror -R --verbose --delete --parallel=4 \
  --exclude ^\.git/ \
  --exclude ^\.gitignore\$ \
  --exclude ^Default\.html\$ \
  --exclude ^composer\.phar\$ \
  --exclude ^deploy\.sh\$ \
  --exclude ^deploy_sql\.sh\$ \
  --exclude ^deploy\.env \
  --exclude ^config/database\.php\$ \
  --exclude ^config/database\.sg\.php\$ \
  --exclude ^config/app_key\.php\$ \
  --exclude ^config/graph\.php\$ \
  --exclude ^config/push\.php\$ \
  --exclude ^config/setup_secret\.php\$ \
  --exclude ^crm/config/\.env\$ \
  --exclude ^crm/uploads/ \
  --exclude ^\.audit/ \
  --exclude ^storage/logs/ \
  --exclude ^storage/uploads/ \
  --exclude ^public/migrate \
  --exclude ^public/debug \
  --exclude ^public/test_ \
  --exclude ^public/setup_vgold\.php\$ \
  --exclude php_errorlog \
  $SQL_EXCLUDE \
  ./ ./
bye
LFTP
echo "=== deploy finished (exit=$?) ==="
