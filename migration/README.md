# VGold Migration — CRM ➜ Unified ERP

This directory holds the data-migration tooling that folds the **Victory Genomics
CRM** into the unified **VGold** database (Option B — one app, one DB).

## Phase 1 — Unified schema + CRM data migration ✅

### What it produces
- **`app/migrations/011_crm_integration.sql`** — the canonical schema:
  - Extends `users` with `crm_user_id`, `crm_role`, `crm_username` (link to CRM).
  - `crm_role_map` — configurable CRM➜VGold role mapping (edited in Settings, Phase 4).
  - All CRM tables recreated under a **`crm_` prefix** (collation normalised to
    `utf8mb4_unicode_ci` to match VGold).
  - `crm_task_links` — bridge for Phase 5 (follow-up ➜ task).
- **`build_crm_import.py`** — transforms a live CRM phpMyAdmin dump into
  `migration/sql/crm_data_import.sql`: every CRM table imported **verbatim**
  under `crm_*`, FK constraints stripped (re-created internally), collation
  normalised. Full fidelity, zero data loss.
- **`reconcile_users.php`** — seeds/links the unified `users` from `crm_users`:
  - Matches by **email, case-insensitive**.
  - `auth_provider = microsoft` for `@victorygenomics.com`, else `password`
    (external users keep their existing CRM bcrypt hash → same login).
  - Role via `crm_role_map` (Admin→admin, others→member).
  - Creates the **primary workspace**, workspace memberships, default
    `user_settings`, and the **"CRM" root category** (a `projects` row with
    `parent_id = NULL`).
  - `--prune-demo` removes the `@northwind.studio` demo seed users.
  - `--dry-run` rolls back (no writes).

### How to run
```bash
# 1. (Re)generate the data import from the latest CRM dump:
python3 migration/build_crm_import.py /path/to/dbg8w2f3gdgcjw.sql migration/sql/crm_data_import.sql

# 2. Apply everything (schema → data → reconcile → verify):
bash migration/run_phase1.sh local     # dev  (config/database.php → vgold_dev)
bash migration/run_phase1.sh prod      # prod (config/database.sg.php)
```

> ⚠️ `migration/sql/*.sql` is **gitignored** — it contains real customer lead
> data (PII). It is never committed; regenerate it on the target server.

### Verified result (matches live CRM exactly)

| User | Email | Login | Leads |
|------|-------|-------|------:|
| Jo Walker | iram@victorygenomics.com | Microsoft 365 | 1,376 |
| Renad | Renad93.rk@gmail.com | password (external) | 326 |
| Moe Adas | m.abuadas@victorygenomics.com | Microsoft 365 | 265 |
| Hazem Joudeh | hazem@victorygenomics.com | Microsoft 365 | 193 |
| Zeina Attal | Zeina@victorygenomics.com | Microsoft 365 | 113 |
| Omar Adas | Omar@victorygenomics.com | Microsoft 365 | 65 |
| Marina Mamarian | Marina@victorygenomics.com | Microsoft 365 | 39 |
| Rachael K | rachael@tasksexpert.team | password (external) | 6 |
| Asif Husain | Asif@victorygenomics.com | Microsoft 365 | 1 |
| Hafsal | hafsal.me@newpage.io | password (external) | 0 |

**Totals:** 10 users · 2,388 leads (4 unassigned) · 1,410 interactions ·
7,839 activity-log rows · 2,233 WhatsApp messages · 86 VoIP calls ·
54 notifications · 1 proposal. **All lead counts match the source DB exactly.**

Runtime guard `Schema::ensureCrm()` (called from `app/router.php`) idempotently
ensures the `users.crm_*` columns and `crm_role_map` exist on SiteGround, which
deploys code without running SQL migrations.

## Next phases
- **Phase 2** — Unified auth & session (365 + password, one session across
  workflow + CRM screens).
- **Phase 3** — Mount CRM screens under `/crm/*` inside the VGold shell.
- **Phase 4** — Access control + Settings-driven role mapping UI.
- **Phase 5** — Task ↔ CRM follow-up bridge (via `crm_task_links`).
- **Phase 6** — Integrations (SharePoint, email, VoIP/WhatsApp/Sheets → vgold).
- **Phase 7** — UI unification (re-skin CRM to the VGO design system).
- **Phase 8** — QA + deploy to `vgold.victorygenomics.com` + verify all logins.
