# VGold — Victory Genomics ERP

VGold is the unified Victory Genomics platform that merges **VGo** (workflow / project
management) and the **Victory Genomics CRM** (leads, interactions, proposals, email,
VoIP, WhatsApp) into a **single app, single database, single login** — a centralized
ecosystem that can grow into a full ERP.

- **Live (target):** https://vgold.victorygenomics.com
- **Backups (untouched):** `vgo.victorygenomics.com` (VGo) and `crm.victorygenomics.com` (CRM)

> VGold is built on the **VGo design system** (same CSS, components, and SPA shell). The
> CRM is being folded in as a module set behind the same chrome and the same session.

---

## Architecture

- **Backend:** PHP 8.x, no framework, lightweight custom MVC.
- **Database:** one MySQL/MariaDB database (`dbs9ygqiryh4yg`) holding both the workflow
  tables (VGo) and the CRM tables.
- **Frontend:** VGo vanilla-JS SPA (no build step) as the host shell, with a module
  switcher (Workflow · CRM · …).
- **Auth:** one login — **Microsoft 365 (OIDC, primary)** + **email/password (fallback)**
  for external users. Microsoft 365 also powers **SharePoint file storage** and Graph mail.
- **Roles/Access:** configurable. Workflow roles (`admin`/`member`) and CRM roles
  (`Admin`/`Sales Manager`/`Sales Rep`/`Viewer`) coexist, with an admin-editable
  role-mapping + per-module access matrix.

### Integration strategy

VGold is one application, not two applications mounted together. Workflow and CRM use
the same SPA shell, login, user directory, settings area, authorization layer, and task
model. The complete original CRM remains in `crm/` and is mounted below `/crm/*`
with the unified VGold session and database. Its duplicate navigation is removed
when embedded, so the full pages and APIs run inside the VGold CRM module rather
than as a separately signed-in product.

Native CRM screens are backed by `/api/crm/*`. Module permissions are stored per user
and enforced on the server as well as in the sidebar. Workflow remains available to
every workspace member.

### Task ↔ CRM bridge
CRM "actionable" items (follow-ups, calls, demos, proposals-to-send, certain lead
statuses) automatically become **VGo tasks** under a dedicated **"CRM" category**,
assigned to the same user and linked back to the lead. Task state is the single source
of truth and syncs both ways.

The native bridge names tasks with the customer identity (for example,
`Follow-up: Lead Name`) and carries the next action, interaction subject, notes,
company, and CRM record reference into the Workflow task description.

### Integrated foundation

- Collapsible **Workflow** and **CRM** sidebar groups in the shared SPA.
- Per-user access controls for nine CRM module areas in centralized admin settings.
- Native CRM overview, leads, interactions, and follow-up entry screens.
- Central CRM company/follow-up settings alongside Workflow, SMTP, AI, and team settings.
- Two-way CRM follow-up ↔ Workflow task status synchronization.
- Embedded full-function CRM modules for proposals, email campaigns/templates/lists,
  VoIP, WhatsApp, automations, reports/exports, knowledge guides, lead details/imports,
  and the related APIs.

---

## Repository layout

```
vgold/
├── config/
│   ├── app.php              # App config (APP_NAME=VGold, vgold URL, ASSET_VERSION)
│   ├── database.php         # Local dev DB (gitignored)
│   ├── database.sg.php      # Production DB — SiteGround (gitignored)
│   ├── graph.example.php    # Azure/Graph template (cert OR client-secret auth)
│   └── graph.php            # Real Azure/Graph config (gitignored)
├── app/                     # VGo workflow backend (controllers, lib, migrations, router)
├── public/                  # VGo SPA shell + assets (the VGold design system)
├── crm/                     # Full CRM source mounted inside the VGold shell
├── migration/               # One-off scripts for the CRM → VGold data migration (Phase 1)
└── storage/                 # uploads + logs (gitignored)
```

---

## Build phases

| Phase | Scope | Status |
|------:|-------|--------|
| 0 | Scaffold VGold from VGo, new subdomain/DB/Azure config, import CRM source | ✅ done |
| 1 | Unified schema + CRM data migration (leads/interactions/users, ID reconciliation) | ⏳ |
| 2 | Unified auth & session (365 + password) | ⏳ |
| 3 | Mount CRM under `/crm/*` inside the shell | ⏳ |
| 4 | Access control + role-mapping settings | ⏳ |
| 5 | Task ↔ CRM bridge (follow-ups → tasks, two-way sync) | ⏳ |
| 6 | Integrations: SharePoint files, email, VoIP/WhatsApp/Sheets re-point | ⏳ |
| 7 | UI unification (re-skin + port CRM screens) | ⏳ |
| 8 | QA, deploy to vgold, verify data + logins | ⏳ |

---

## Local dev

```bash
# 1. Create the dev database
mysql -u root -e "CREATE DATABASE vgold_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Run the VGo migrations
for f in app/migrations/*.sql; do mysql -u root vgold_dev < "$f"; done

# 3. Serve
cd public && php -S localhost:8080
```

Secrets (`config/graph.php`, `config/database.sg.php`, `config/app_key.php`, `certs/`)
are **gitignored** and must never be committed.

---

© 2026 Victory Genomics. Proprietary and confidential.
