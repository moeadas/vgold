<?php
/**
 * VGold Phase 1 — User reconciliation & CRM linkage.
 *
 * Run AFTER crm_data_import.sql has populated the crm_* tables.
 *
 *   php migration/reconcile_users.php [--prune-demo] [--dry-run]
 *
 * What it does (idempotent):
 *   1. For each row in crm_users, upsert a unified `users` row:
 *        - match by email (case-insensitive); if none, create.
 *        - link users.crm_user_id = crm_users.user_id
 *        - copy full_name, crm_role, crm_username
 *        - role: map via crm_role_map (Admin->admin, else member)
 *        - auth_provider: 'microsoft' for @victorygenomics.com, else 'password'
 *        - password: carry over the CRM bcrypt hash (external users log in with
 *          their existing CRM credentials); MS users get an unusable random hash.
 *        - is_active from CRM status.
 *   2. Ensure each user is a workspace_member of the primary workspace.
 *   3. Ensure a default user_settings row exists.
 *   4. Ensure a "CRM" root category (projects.parent_id IS NULL) exists.
 *   5. With --prune-demo, deactivate/remove seed demo users that have no
 *      crm_user_id and belong to the northwind.studio demo domain.
 *
 * Reconciliation is by EMAIL, exactly as confirmed with the user.
 */

define('VGOLD_CLI', true);
$root = dirname(__DIR__);
require $root . '/config/app.php';
require $root . '/app/lib/DB.php';

$DRY  = in_array('--dry-run', $argv, true);
$PRUNE= in_array('--prune-demo', $argv, true);

function say($m){ echo $m . "\n"; }

// The Microsoft-login domain confirmed by the user.
const MS_DOMAIN = 'victorygenomics.com';

$pdo = DB::conn();
$pdo->beginTransaction();
try {
    // ---- 0. Prune demo seed users FIRST --------------------------------------
    // IMPORTANT: this must run BEFORE we select/create the primary workspace.
    // The initial seed migration creates a demo workspace whose `created_by`
    // points at a demo user, and `workspaces.created_by` is
    // `ON DELETE CASCADE`. If we pruned demo users AFTER attaching our real
    // users/categories to that demo workspace, deleting the demo owner would
    // cascade-delete the whole workspace (and its members + CRM category).
    // Pruning first guarantees we build a fresh workspace owned by a real user.
    if ($PRUNE) {
        $demo = DB::fetchAll("SELECT id, email FROM users WHERE crm_user_id IS NULL AND email LIKE '%@northwind.studio'");
        foreach ($demo as $d) {
            if (!$DRY) DB::query("DELETE FROM users WHERE id=?", [$d['id']]);
            say("  - pruned demo user #{$d['id']} {$d['email']}");
        }
    }

    // ---- 1. Role map ----------------------------------------------------------
    $roleMap = [];
    foreach (DB::fetchAll("SELECT crm_role, vgold_role FROM crm_role_map") as $r) {
        $roleMap[$r['crm_role']] = $r['vgold_role'];
    }

    // ---- 2. Reconcile each CRM user ------------------------------------------
    $crmUsers = DB::fetchAll("SELECT * FROM crm_users ORDER BY user_id");
    say("CRM users to reconcile: " . count($crmUsers));

    $linked = 0; $created = 0; $updated = 0;
    $uids = [];              // unified user ids of reconciled CRM users
    $rolesByUid = [];        // uid => vgold role
    foreach ($crmUsers as $cu) {
        $email = trim($cu['email']);
        $emailLc = strtolower($email);
        $isMs = (bool)preg_match('/@' . preg_quote(MS_DOMAIN, '/') . '$/i', $email);
        $provider = $isMs ? 'microsoft' : 'password';
        $vgoRole  = $roleMap[$cu['role']] ?? 'member';
        $active   = ($cu['status'] === 'Active') ? 1 : 0;
        // For MS users, store an unusable password (they authenticate via OIDC).
        // For external users, carry over the CRM bcrypt hash verbatim.
        $password = $isMs ? ('!ms:' . bin2hex(random_bytes(16))) : $cu['password_hash'];

        // Find existing unified user by crm_user_id first, then by email (CI).
        $existing = DB::fetch("SELECT * FROM users WHERE crm_user_id = ? LIMIT 1", [$cu['user_id']]);
        if (!$existing) {
            $existing = DB::fetch("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1", [$emailLc]);
        }

        $fields = [
            'name'         => $cu['full_name'] ?: $cu['username'],
            'email'        => $email,
            'auth_provider'=> $provider,
            'role'         => $vgoRole,
            'is_active'    => $active,
            'crm_user_id'  => (int)$cu['user_id'],
            'crm_role'     => $cu['role'],
            'crm_username' => $cu['username'],
        ];

        if ($existing) {
            $uid = (int)$existing['id'];
            if (!$DRY) {
                // Only set password if the existing one is empty/placeholder, or
                // for external users always sync the CRM hash so login works.
                if (!$isMs) $fields['password'] = $password;
                DB::update('users', $fields, 'id = ?', [$uid]);
            }
            $updated++;
            say(sprintf("  ~ updated #%d  %-28s crm#%d  %-14s %s", $uid, $email, $cu['user_id'], $provider, $vgoRole));
        } else {
            $fields['password'] = $password;
            if (!$DRY) {
                $uid = DB::insert('users', $fields);
            } else { $uid = 0; }
            $created++;
            say(sprintf("  + created #%d  %-28s crm#%d  %-14s %s", $uid, $email, $cu['user_id'], $provider, $vgoRole));
        }

        if ($uid) { $uids[] = $uid; $rolesByUid[$uid] = $vgoRole; }
        $linked++;
    }

    // ---- 2b. Primary workspace (created_by requires an existing user) --------
    // Prefer an admin among the reconciled CRM users as the workspace owner so
    // the workspace never depends on a demo/placeholder user (which could be
    // removed later and cascade-delete the workspace).
    $adminU  = DB::fetch("SELECT id FROM users WHERE role='admin' AND crm_user_id IS NOT NULL ORDER BY id LIMIT 1");
    $creator = $adminU ? (int)$adminU['id'] : ($uids[0] ?? null);

    // Reuse an existing workspace ONLY if it is owned by a linked CRM user;
    // otherwise its owner may be a demo user with a cascading FK.
    $ws = DB::fetch(
        "SELECT w.id, w.created_by FROM workspaces w
         JOIN users u ON u.id = w.created_by
         WHERE u.crm_user_id IS NOT NULL
         ORDER BY w.id ASC LIMIT 1"
    );
    if ($ws) {
        $wsId = (int)$ws['id'];
    } else {
        if (!$DRY && $creator) {
            $wsId = DB::insert('workspaces', ['name'=>'Victory Genomics', 'created_by'=>$creator]);
            say("Created workspace #$wsId (Victory Genomics)");
        } else { $wsId = 0; say("[dry-run] would create workspace Victory Genomics"); }
    }

    // ---- 2c. Workspace membership + default settings for each user ----------
    if (!$DRY && $wsId) {
        foreach ($uids as $uid) {
            $wm = DB::fetch("SELECT id FROM workspace_members WHERE workspace_id=? AND user_id=?", [$wsId, $uid]);
            if (!$wm) {
                DB::insert('workspace_members', ['workspace_id'=>$wsId, 'user_id'=>$uid, 'role'=>$rolesByUid[$uid]]);
            }
            $us = DB::fetch("SELECT id FROM user_settings WHERE user_id=?", [$uid]);
            if (!$us) { DB::insert('user_settings', ['user_id'=>$uid]); }
        }
    }

    // ---- 3. CRM root category (project parent_id NULL) -----------------------
    $crmCat = DB::fetch("SELECT id FROM projects WHERE workspace_id=? AND parent_id IS NULL AND name='CRM' LIMIT 1", [$wsId]);
    if (!$crmCat) {
        // created_by: prefer an admin unified user
        $admin = DB::fetch("SELECT id FROM users WHERE role='admin' AND crm_user_id IS NOT NULL ORDER BY id LIMIT 1");
        $adminId = $admin ? (int)$admin['id'] : null;
        if (!$adminId) { $anyU = DB::fetch("SELECT id FROM users ORDER BY id LIMIT 1"); $adminId = $anyU ? (int)$anyU['id'] : 1; }
        if (!$DRY) {
            $catId = DB::insert('projects', [
                'workspace_id'=>$wsId, 'parent_id'=>null, 'name'=>'CRM',
                'description'=>'Leads, follow-ups and sales activity migrated from the Victory Genomics CRM.',
                'color'=>'#C99520', 'created_by'=>$adminId,
            ]);
            say("Created CRM root category (project #$catId)");
        } else { say("[dry-run] would create CRM root category"); }
    } else {
        say("CRM root category already exists (project #{$crmCat['id']})");
    }

    // ---- 4. (Demo seed users are pruned in step 0, before workspace setup) ---

    if ($DRY) { $pdo->rollBack(); say("\n[dry-run] rolled back — no changes written."); }
    else      { $pdo->commit();  say("\nDone. linked=$linked created=$created updated=$updated"); }

} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "RECONCILE FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
