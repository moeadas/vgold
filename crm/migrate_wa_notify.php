<?php
/**
 * Migration: Add whatsapp_number + wa_notify_enabled columns to users table
 * and wa_lead_assignment_notify setting.
 * 
 * RUN ONCE, THEN DELETE THIS FILE.
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    $results = [];

    // 1. Add whatsapp_number column to users table if it doesn't exist
    $cols = $db->query("SHOW COLUMNS FROM users LIKE 'whatsapp_number'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN `whatsapp_number` VARCHAR(20) DEFAULT NULL AFTER `phone`");
        $results[] = "Added whatsapp_number column to users table";
    } else {
        $results[] = "whatsapp_number column already exists";
    }

    // 2. Add wa_notify_enabled column to users table if it doesn't exist
    $cols2 = $db->query("SHOW COLUMNS FROM users LIKE 'wa_notify_enabled'")->fetchAll();
    if (empty($cols2)) {
        $db->exec("ALTER TABLE users ADD COLUMN `wa_notify_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `whatsapp_number`");
        $results[] = "Added wa_notify_enabled column to users table";
    } else {
        $results[] = "wa_notify_enabled column already exists";
    }

    // 3. Add wa_lead_assignment_notify setting if it doesn't exist
    $dbI = Database::getInstance();
    $existing = $dbI->query("SELECT setting_id FROM settings WHERE setting_key = 'wa_lead_assignment_notify'")->fetch();
    if (!$existing) {
        $dbI->insert('settings', [
            'setting_key' => 'wa_lead_assignment_notify',
            'setting_value' => '0',
            'setting_type' => 'boolean',
        ]);
        $results[] = "Added wa_lead_assignment_notify setting (default: disabled)";
    } else {
        $results[] = "wa_lead_assignment_notify setting already exists";
    }

    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
