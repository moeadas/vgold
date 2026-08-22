# VGo — architecture reference

Living document. **Update it in the same commit as the change it describes.**
Working notes that are too volatile for this file (live row counts, open chase
lists, deploy secrets) live in the project memory instead.

Last updated: **2026-08-22 · v1.31.0 / build 2026.08.22.1** (Sales Dashboard).

---

## 1. What VGo is

One PHP 8 application, one MySQL database, one login, three apps behind the same
SPA shell:

| App | Nav group | What it holds |
|---|---|---|
| **Workflow** | W | Workspaces → Projects → Project Areas, tasks, priorities, project chat, files |
| **CRM** | C | Leads, customers, interactions, proposals, email marketing, VoIP & WhatsApp, automations, reports, knowledge hub, **Sales Dashboard** |
| **Accounting & Finance** | A | Invoices, bills, contacts (customer/vendor/investor), banking + Plaid feed, journal & chart of accounts, catalog, recurring, reports |

No framework, no build step. The frontend is a vanilla-JS SPA; the backend is a
small hand-rolled router plus static controller classes.

- Live: **https://vgo.victorygenomics.com** (SiteGround, LiteSpeed, PHP 8.2)
- Docroot: `public_html/public/`; the root `.htaccess` rewrites into it
- `crm.victorygenomics.com` 301/307-redirects into `/crm/*` here

---

## 2. Request paths

```
/api/*        → public/index.php → app/router.php   (native JSON API)
/crm/*        → public/index.php → crm/mount.php    (legacy CRM pages + APIs)
/plaid/oauth  → public/plaid-oauth.php              (virtual path, dispatched by hand)
*.css|js|…    → served from public/assets
anything else → the SPA shell, printed inline by public/index.php
```

### Three API patterns — know which one you are in

1. **Native VGo / CRM / Sales** — `app/controllers/*Controller.php` behind
   `/api/*`, using the `DB::` helper against **real table names**.
2. **Legacy CRM modules** — `crm/api/*.php` behind `/crm/api/*`, reached from the
   SPA via `crmApiGet` / `crmApiPost`. These use the old `Database` wrapper whose
   **CrmRewritingPDO bridge rewrites bare table names to `crm_*`** — a query
   against `settings` really hits `crm_settings`. `tasks` and `projects` are VGo
   tables and are *not* rewritten.
3. **Accounting** — fully native `/api/acc/*` via `AccountingController` and
   friends.

`app/router.php` matches the **first** pattern that matches, in declaration
order, so every literal route must be declared before a `{id}` route that could
swallow it.

---

## 3. Access control

`app/lib/Authz.php` is the single source of truth.

- **Workflow role** — `workspace_members.role` ∈ `admin | member`.
- **CRM role** — `users.crm_role` ∈ `Admin | Sales Manager | Sales Rep | Viewer`,
  preserved from the original CRM. It is *advisory*: the VGo role and the module
  grant are authoritative. `mount.php` downgrades a legacy `Admin` on a non-admin
  VGo user so an imported role can never grant CRM-admin powers.
- **Module grants** — `user_module_access`, edited in Settings → Team.
  - **CRM modules**: a workspace admin implicitly holds all of them.
  - **Accounting modules**: explicit grant only, plus the bootstrap owner
    (`users.is_acc_owner`). Finance stays invisible to other admins until it is
    deliberately shared.

`moduleDefinitions()` is read dynamically by the Settings screen and the module
chips, so **adding a key to `CRM_MODULES` / `ACC_MODULES` is all that is needed**
for it to appear as a grantable module.

---

## 4. Frontend

- `public/assets/js/app.js` — `State`, hash routing (`routeFromHash` /
  `updateHash`), and a `render()` that switches on `State.screen`. Views are
  `renderX()` async functions returning HTML strings.
- **A new view file must be added to the script list in `public/index.php`** or it
  is never loaded.
- Stylesheets, in load order: `app.css`, `crm-native.css`, `overrides.css`,
  `accounting.css`, `sales.css`. `crm-native.css` scopes an Apple-ish design
  system under `.crm-native`; `overrides.css` repaints it to the warm VGo palette.
- **Realtime**: no websockets. `GET /api/state-version` is polled every 12 s and a
  changed fingerprint calls `applyRealtimeRefresh()`. **A view with its own cache
  must be invalidated there**, or it shows stale data forever.
- **Notifications**: `NotificationController::targetFor()` is the one routing
  table; the bell, the Mentions cards and web push all read it. Adding a
  notification type means adding a case there and nothing else.

### Escaping — the biggest bug class this codebase has had

| Use | Function |
|---|---|
| HTML text and ordinary attributes | `esc()` |
| anything inside a **JS string literal** (`onclick="fn('…')"`) | `escJs()` |
| a user-supplied URL before `window.open`/`href` | `safeUrl()` |

`esc()` emits `&#39;`, and the HTML parser decodes an attribute *before* the JS
parser reads it — so `esc()` inside an inline handler is an XSS sink. Escaping
`&` is the non-obvious part that makes `escJs()` correct.

### Layout rules that have each caused a real defect

- Any CSS grid: give cells `min-width: 0`. Grid items default to `min-width:auto`
  and long text paints over the next column.
- `.acc-truncate` only ellipsises on a **block** box.
- Modal bodies render outside the view wrapper (`#modal-root`), so a scoped
  stylesheet a modal depends on must be injected where the markup is built.
- `overrides.css` positions `.card-actions` absolutely for the hover cluster on a
  workspace card; **CRM card headers opt back out** via
  `.crm-native .card-header .card-actions { position: static; opacity: 1 }`.

---

## 5. Schema

One database, three prefixes: VGo tables unprefixed, CRM as `crm_*`, Accounting
as `acc_*`. The bridge between the VGo and CRM identities is
`users.crm_user_id`; between CRM and Accounting it is `acc_contacts.crm_lead_id`.

### Schema-on-demand

New tables and columns are created lazily by an `ensureX()` method rather than by
a migration step (`Schema`, `AccSchema`, `PlaidSchema`, `SalesSchema`).

> ⚠️ **This MySQL runs without `STRICT_TRANS_TABLES`.** A write against a column
> that failed to appear does not error — it silently coerces. So an `ensure()`
> must **return whether the thing is really there** and every write path must be
> gated on that answer. Twenty-one `crm_*` tables once came out of a migration
> with no PRIMARY KEY and no AUTO_INCREMENT; inserts "succeeded", every row
> landed as id 0, and the feature simply appeared to do nothing.

---

## 6. Sales Dashboard (CRM) — added v1.31.0

**Module key** `crm.sales`. Screens: `crm-sales` (dashboard), `crm-sale-new`
(record/edit a sale), `crm-sales-targets`, `crm-sales-settings`.

**Files** `app/lib/SalesSchema.php`, `app/controllers/SalesController.php`,
`public/assets/js/views/sales.js`, `public/assets/css/sales.css`.

### Where a sale comes from

`crm_sales` is a **hybrid ledger**:

- **manual** rows — a manager records a deal, optionally linked to a CRM lead;
- **mirrored** rows — every Accounting invoice whose contact carries a
  `crm_lead_id` is copied in by `syncFromAccounting()`, which runs on every
  dashboard load and on demand from the Sync button.

The mirror is idempotent on a **UNIQUE `acc_document_id`**. On an existing row it
refreshes only the money and the dates: it never overwrites a rep a manager
re-attributed, and never a rate pinned to that deal (`rate_override`). A mirrored
sale cannot be edited or deleted from the CRM — the invoice in Accounting is the
record.

### Commission

A **flat percentage per person** (`crm_sales_commission.rate`, admin-only), paid
on **cash collected**, not on the invoiced amount:

```
commission_amount = collected_amount × rate / 100     (only when status = 'won')
```

For a mirrored sale `collected_amount` *is* `acc_documents.paid_amount`, so
commission can never run ahead of the ledger. The rate is **snapshot onto each
sale** when it is written, so changing somebody's rate does not rewrite what was
already earned; saving new rates re-stamps only sales dated in the current month
or later, and never one with `rate_override = 1`.

### Targets

`crm_sales_targets` holds one row per `(user_id, period_type, period_start)`,
with `user_id = 0` meaning the whole team. Period types are `month`, `quarter`
and `year`.

A missing month falls back to its **quarter ÷ 3**, then its **year ÷ 12**; a
missing quarter to its **year ÷ 4**. The response carries `derived` naming which
happened, and the UI labels it — a split number must never read as one somebody
actually set. For the team view, an explicit `user_id = 0` row wins; otherwise the
team target is the **sum of the individuals'**, and `target_source` says which.

### Who sees what

| | own results | whole team + per-person filter | record / edit / confirm a sale | set targets | commission rates |
|---|:-:|:-:|:-:|:-:|:-:|
| Sales Rep | ✅ | — | logs their own as `pending` | — | — |
| Sales Manager *(or VGo admin)* | ✅ | ✅ | ✅ | ✅ | — |
| VGo workspace admin | ✅ | ✅ | ✅ | ✅ | ✅ |

"Manager" is `SalesController::canManage()` — a VGo admin, or `crm_role` of
`Admin` / `Sales Manager` — mirroring `CRMController::isCrmManager()`.

**A rep's scope is pinned server-side.** `scope()` ignores the `?rep=` parameter
for anyone who is not a manager, so the filter cannot be widened from the URL.

A sale a rep logs for themselves lands as `pending`: it counts toward nothing and
earns nothing until a manager confirms it, which notifies the rep.

### What it is wired to

| Module | Link |
|---|---|
| **Leads** | a sale links to `crm_leads.lead_id`; the lead page gets a **Sales** card (a rep only ever sees their own rows on it) and a "Record a sale" button that pre-fills the client |
| **Customers** | the client column links straight to the lead/customer record |
| **Accounting** | invoices mirror in through `acc_contacts.crm_lead_id`; `collected_amount` tracks `acc_documents.paid_amount`; the reporting currency follows `acc_settings.default_currency` |
| **CRM Overview** | a "this month vs target" tile with a progress bar |
| **Notifications** | `sales_target` (your number changed), `sales_won` (your sale was confirmed), `sales_pending` (a rep logged one) — all routed by `targetFor()` to `crm-sales` |
| **Realtime** | `invalidateSalesCache()` is called from `applyRealtimeRefresh()` |
| **Settings → Team** | `crm.sales` appears automatically as a grantable module |

### Performance notes

`allTargets()` and `actualsByRep()` load the whole (small) target table and one
grouped totals query per window, memoised per request. Resolving a target per
person per trend month with a query apiece was several hundred round-trips on a
single page load.

---

## 7. Deploy

`origin/main` is the source of truth and the server pulls **from a pinned commit**
— never hand-transcribed bytes.

```
clone from GitHub as the editing base → patch → lint → render-check in Chromium
→ commit + push → upload public/pull_deploy.php → curl it at the pinned sha
→ verify every md5 against the repo → cleanup_backups → self_delete
→ confirm removal with an SFTP listing (the URL returns 200 either way)
```

Bump **`ASSET_VERSION`, `APP_VERSION` and `APP_BUILD`** in `config/app.php` on
every deploy, and tell the user the new number — it is shown in Settings.

One-off DB work goes in a key-gated `public/_x.php`, **dry-run by default with
`&go=1` to execute**, with a unique cache-buster on the URL (LiteSpeed caches a
repeated identical URL), deleted over SFTP afterwards. Print the trial balance
before and after any write — it is the cheapest proof the ledger did not move.

---

## 8. Repository layout

```
vgold/
├── ARCHITECTURE.md          # this file
├── config/app.php           # APP_URL, ASSET_VERSION, APP_VERSION, APP_BUILD
├── app/
│   ├── router.php           # the /api/* route table (first match wins)
│   ├── controllers/         # Auth, Project, Task, Message, Settings, Notification,
│   │                        # CRM, Sales, Accounting, BankFeed, Plaid, ContractorInvoice…
│   ├── lib/                 # DB, Auth, Authz, Schema/AccSchema/SalesSchema, Mail,
│   │                        # Graph/MsMail/MsJwks, Plaid, StatementParser, Push…
│   └── migrations/          # the historic .sql baseline (new work is schema-on-demand)
├── crm/                     # legacy CRM pages + APIs, mounted at /crm/*
├── public/
│   ├── index.php            # front controller AND the SPA shell (script/style list)
│   └── assets/{css,js}      # the SPA: app.js + views/*.js
├── migration/               # one-off CRM → VGo data migration scripts
└── storage/                 # uploads + logs (gitignored)
```

Secrets (`config/graph.php`, `config/database.sg.php`, `config/app_key.php`,
`certs/`) are gitignored. **The GitHub repo is public** — never commit a security
write-up describing live vulnerabilities; use `.git/info/exclude`.
