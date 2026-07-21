#!/bin/bash
# ============================================================================
# VGold — FTP(S) deploy to vgold.victorygenomics.com  (SiteGround)
# Mirrors the repo into public_html/ so the root .htaccess routes to public/.
# Secrets (config/*.php, vendor/, crm/vendor/) are NOT in git and ARE uploaded
# here because this is a direct FTP deploy, not a git checkout.
# ============================================================================
set -u

HOST="ftp.victorygenomics.com"
USER="vgold@victorygenomics.com"
PASS='Tistis3aw80@'
PORT=21
LOCAL="/home/user/vgold"
REMOTE="/public_html"

# Optional first arg: "sql" also uploads the big migration dump (default skips it
# so ordinary code deploys stay fast; the dump is uploaded once via deploy_sql.sh).
INCLUDE_SQL="${1:-nosql}"

SQL_EXCLUDE="--exclude migration/sql/"
if [ "$INCLUDE_SQL" = "sql" ]; then SQL_EXCLUDE=""; fi

lftp -u "$USER","$PASS" "ftp://$HOST:$PORT" <<LFTP
set ssl:verify-certificate no
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
  --exclude ^config/database\.php\$ \
  --exclude ^\.audit/ \
  --exclude ^storage/logs/ \
  --exclude ^storage/uploads/ \
  --exclude ^public/migrate \
  --exclude ^public/debug \
  --exclude ^public/test_ \
  $SQL_EXCLUDE \
  ./ ./
bye
LFTP
echo "=== deploy finished (exit=$?) ==="
