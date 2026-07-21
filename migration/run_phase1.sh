#!/usr/bin/env bash
# VGold Phase 1 migration runner.
#
# Applies, in order, against the target database:
#   1. app/migrations/011_crm_integration.sql   (users.crm_* cols, crm_role_map, crm_* tables, bridge)
#   2. migration/sql/crm_data_import.sql         (all CRM data, verbatim, into crm_* tables)
#   3. migration/reconcile_users.php             (seed/link unified users, workspace, CRM category)
#
# Usage:
#   # Local dev (uses config/database.php -> vgold_dev):
#   bash migration/run_phase1.sh local
#
#   # Production (SiteGround) — run from the app root on the server:
#   bash migration/run_phase1.sh prod
#
# The data import (step 2) is regenerated from the source dump by
# build_crm_import.py; regenerate it whenever you receive a fresher CRM export:
#   python3 migration/build_crm_import.py /path/to/dbg8w2f3gdgcjw.sql migration/sql/crm_data_import.sql
set -euo pipefail
cd "$(dirname "$0")/.."

MODE="${1:-local}"

if [[ "$MODE" == "local" ]]; then
  DBN="$(php -r '$c=require "config/database.php"; echo $c["name"];')"
  DBU="$(php -r '$c=require "config/database.php"; echo $c["user"];')"
  DBP="$(php -r '$c=require "config/database.php"; echo $c["pass"];')"
  DBH="$(php -r '$c=require "config/database.php"; echo $c["host"];')"
else
  DBN="$(php -r '$c=require "config/database.sg.php"; echo $c["name"];')"
  DBU="$(php -r '$c=require "config/database.sg.php"; echo $c["user"];')"
  DBP="$(php -r '$c=require "config/database.sg.php"; echo $c["pass"];')"
  DBH="$(php -r '$c=require "config/database.sg.php"; echo $c["host"];')"
fi

MYSQL=(mysql -h "$DBH" -u "$DBU" -p"$DBP" "$DBN")

echo "==> [$MODE] DB=$DBN host=$DBH"
echo "==> 1/3 schema (011_crm_integration.sql)"
"${MYSQL[@]}" < app/migrations/011_crm_integration.sql
echo "==> 2/3 CRM data import (crm_data_import.sql)"
"${MYSQL[@]}" < migration/sql/crm_data_import.sql
echo "==> 3/3 reconcile users + workspace + CRM category"
php migration/reconcile_users.php --prune-demo

echo "==> Verifying lead counts per user..."
"${MYSQL[@]}" -e "
SELECT u.name, u.email, u.auth_provider, u.crm_user_id,
       (SELECT COUNT(*) FROM crm_leads l WHERE l.assigned_to=u.crm_user_id) AS leads
FROM users u WHERE u.crm_user_id IS NOT NULL ORDER BY leads DESC;"
echo "==> Phase 1 migration complete."
