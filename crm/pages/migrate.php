<?php
/**
 * Victory Genomics CRM - Database Migration Helper
 * Run this page once to create/update required tables and fix settings.
 * Access: /pages/migrate.php (requires admin login)
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
startSecureSession();
requireLogin();
requireRole(['Admin']);

$db = Database::getInstance();
$results = [];

// ─── 1. Create voip_calls table ───
try {
    $db->query("SELECT 1 FROM voip_calls LIMIT 1");
    $results[] = ['voip_calls table', 'Already exists', 'info'];
} catch (Exception $e) {
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS voip_calls (
                call_id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NULL,
                user_id INT NULL,
                twilio_call_sid VARCHAR(50) NULL,
                direction ENUM('Inbound','Outbound') DEFAULT 'Outbound',
                from_number VARCHAR(30) NULL,
                to_number VARCHAR(30) NULL,
                status VARCHAR(30) DEFAULT 'Initiated',
                duration_seconds INT DEFAULT 0,
                outcome VARCHAR(30) NULL,
                notes TEXT NULL,
                recording_url VARCHAR(512) NULL,
                recording_sid VARCHAR(50) NULL,
                started_at DATETIME NULL,
                ended_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_voip_user (user_id),
                INDEX idx_voip_lead (lead_id),
                INDEX idx_voip_sid (twilio_call_sid),
                INDEX idx_voip_created (created_at),
                INDEX idx_voip_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results[] = ['voip_calls table', 'Created successfully', 'success'];
    } catch (Exception $ex) {
        $results[] = ['voip_calls table', 'Failed: ' . $ex->getMessage(), 'error'];
    }
}

// ─── 2. Create whatsapp_messages table ───
try {
    $db->query("SELECT 1 FROM whatsapp_messages LIMIT 1");
    $results[] = ['whatsapp_messages table', 'Already exists', 'info'];
} catch (Exception $e) {
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS whatsapp_messages (
                message_id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NULL,
                user_id INT NULL,
                twilio_message_sid VARCHAR(50) NULL,
                direction ENUM('Inbound','Outbound') DEFAULT 'Outbound',
                from_number VARCHAR(30) NULL,
                to_number VARCHAR(30) NULL,
                message_body TEXT NULL,
                media_url VARCHAR(512) NULL,
                status VARCHAR(30) DEFAULT 'Queued',
                template_id INT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wa_user (user_id),
                INDEX idx_wa_lead (lead_id),
                INDEX idx_wa_sid (twilio_message_sid),
                INDEX idx_wa_created (created_at),
                INDEX idx_wa_direction (direction),
                INDEX idx_wa_from (from_number),
                INDEX idx_wa_to (to_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results[] = ['whatsapp_messages table', 'Created successfully', 'success'];
    } catch (Exception $ex) {
        $results[] = ['whatsapp_messages table', 'Failed: ' . $ex->getMessage(), 'error'];
    }
}

// ─── 3. Create whatsapp_templates table ───
try {
    $db->query("SELECT 1 FROM whatsapp_templates LIMIT 1");
    $results[] = ['whatsapp_templates table', 'Already exists', 'info'];
} catch (Exception $e) {
    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS whatsapp_templates (
                template_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                body TEXT NOT NULL,
                category VARCHAR(50) DEFAULT 'UTILITY',
                language VARCHAR(10) DEFAULT 'en',
                status VARCHAR(30) DEFAULT 'Active',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tpl_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results[] = ['whatsapp_templates table', 'Created successfully', 'success'];
    } catch (Exception $ex) {
        $results[] = ['whatsapp_templates table', 'Failed: ' . $ex->getMessage(), 'error'];
    }
}

// ─── 4. Add profile_name column to whatsapp_messages ───
try {
    $db->query("SELECT profile_name FROM whatsapp_messages LIMIT 1");
    $results[] = ['whatsapp_messages.profile_name', 'Column already exists', 'info'];
} catch (Exception $e) {
    try {
        $db->query("ALTER TABLE whatsapp_messages ADD COLUMN profile_name VARCHAR(200) DEFAULT NULL AFTER to_number");
        $results[] = ['whatsapp_messages.profile_name', 'Column added successfully', 'success'];
    } catch (Exception $ex) {
        $results[] = ['whatsapp_messages.profile_name', 'Failed: ' . $ex->getMessage(), 'error'];
    }
}

// ─── 5. Make company_name and country nullable in leads ───
try {
    // Check if company_name is already nullable
    $colInfo = $db->query("SHOW COLUMNS FROM leads WHERE Field = 'company_name'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo && $colInfo['Null'] === 'YES') {
        $results[] = ['leads.company_name nullable', 'Already nullable', 'info'];
    } else {
        $db->query("ALTER TABLE leads MODIFY COLUMN company_name VARCHAR(200) DEFAULT NULL");
        $results[] = ['leads.company_name nullable', 'Updated successfully', 'success'];
    }
} catch (Exception $e) {
    $results[] = ['leads.company_name nullable', 'Error: ' . $e->getMessage(), 'error'];
}

try {
    $colInfo = $db->query("SHOW COLUMNS FROM leads WHERE Field = 'country'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo && $colInfo['Null'] === 'YES') {
        $results[] = ['leads.country nullable', 'Already nullable', 'info'];
    } else {
        $db->query("ALTER TABLE leads MODIFY COLUMN country VARCHAR(100) DEFAULT NULL");
        $results[] = ['leads.country nullable', 'Updated successfully', 'success'];
    }
} catch (Exception $e) {
    $results[] = ['leads.country nullable', 'Error: ' . $e->getMessage(), 'error'];
}

// ─── 6. Verify/Fix Twilio settings ───
try {
    $settings = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('twilio_phone_number', 'whatsapp_from_number')")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $voipNumber = $settings['twilio_phone_number'] ?? '';
    $waNumber = $settings['whatsapp_from_number'] ?? '';
    
    $results[] = ['VoIP Phone (DB)', $voipNumber ?: '(not set - using .env)', 'info'];
    $results[] = ['WhatsApp Phone (DB)', $waNumber ?: '(not set - using .env)', 'info'];
    $results[] = ['VoIP Phone (.env)', getenv('TWILIO_PHONE_NUMBER') ?: '(not set)', 'info'];
    $results[] = ['WhatsApp Phone (.env)', getenv('WHATSAPP_FROM_NUMBER') ?: '(not set)', 'info'];
    
    // If VoIP and WhatsApp are the same in DB, fix it
    if ($voipNumber && $waNumber && $voipNumber === $waNumber) {
        $envVoip = getenv('TWILIO_PHONE_NUMBER');
        if ($envVoip && $envVoip !== $waNumber) {
            $db->update('settings', ['setting_value' => $envVoip], ['setting_key' => 'twilio_phone_number']);
            $results[] = ['Fix: VoIP number', "Updated from $voipNumber to $envVoip", 'success'];
        } else {
            $results[] = ['Warning', 'VoIP and WhatsApp numbers are the same. Update in Settings > VoIP & WhatsApp.', 'warning'];
        }
    }
    
    // If DB has wrong VoIP number, update from .env
    $envVoip = getenv('TWILIO_PHONE_NUMBER');
    if ($envVoip && $voipNumber && $voipNumber !== $envVoip && $voipNumber === $waNumber) {
        // VoIP number in DB matches WhatsApp number - likely incorrect
        $db->update('settings', ['setting_value' => $envVoip], ['setting_key' => 'twilio_phone_number']);
        $results[] = ['Fix: VoIP number corrected', "Changed from $voipNumber to $envVoip", 'success'];
    }
    
} catch (Exception $e) {
    $results[] = ['Settings check', 'Error: ' . $e->getMessage(), 'error'];
}

// ─── Display results ───
$pageTitle = 'Database Migration';
include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Database Migration Results</h1>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead>
                <tr><th>Item</th><th>Result</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                    <?php
                    $badgeClass = match($r[2]) {
                        'success' => 'bg-green-100 text-green-800',
                        'error' => 'bg-red-100 text-red-800',
                        'warning' => 'bg-yellow-100 text-yellow-800',
                        default => 'bg-gray-100 text-gray-800',
                    };
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r[0]); ?></strong></td>
                        <td><?php echo htmlspecialchars($r[1]); ?></td>
                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($r[2]); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top:20px;">
            <a href="voip-dashboard.php" class="btn btn-primary">Go to VoIP Dashboard</a>
            <a href="settings.php" class="btn btn-outline" style="margin-left:8px;">Go to Settings</a>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
