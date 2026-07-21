# VGold Data Migration (Phase 1)

One-off scripts to migrate the existing Victory Genomics CRM data into the unified
VGold database, preserving all leads, interactions, documents, email data, and history.

## Inputs (provided out-of-band, NOT committed)
- `migration/data/victory-genomics-crm-v2-complete.sql` — full CRM dump (~1 GB, gitignored)
- A confirmed **crm_user_id → Microsoft 365 email** mapping (from the app owner)

## Steps (to be built in Phase 1)
1. Load the CRM dump into a temporary schema (`crm_import`).
2. Extend the VGold `users` table with the CRM columns (username, smtp_*, twilio_*, ms_* …).
3. Build the identity map: match each CRM `users.user_id` to a VGold `users.id`
   by Microsoft email (creating VGold users where needed).
4. Import CRM tables (leads, interactions, documents, email_*, settings, activity_log,
   voip/whatsapp/automation/webhook) rewriting `assigned_to` / `created_by` / `user_id`
   foreign keys to the unified VGold user IDs.
5. Create the "CRM" project category and backfill the task ↔ follow-up links.
6. Verify: row counts, per-user lead visibility, sample logins.

## Known CRM users (from dump — emails to be CONFIRMED by owner)
| crm user_id | username | email (in CRM) | name | CRM role |
|---:|---|---|---|---|
| 1 | admin   | m.abuadas@victorygenomics.com | Moe Adas    | Admin         |
| 5 | iram    | iram@victorygenomics.com      | Iram Weir   | Sales Manager |
| 6 | rachael | rachael@tasksexpert.team      | Rachael K   | Sales Manager |
| 7 | Hazem   | hazem@victorygenomics.com     | Hazem Joudeh| Sales Rep     |
| 8 | Omar    | Omar@victorygenomics.com      | Omar Adas   | Sales Manager |

> Nothing in this folder runs automatically. Phase 1 will add reviewed, idempotent scripts.
