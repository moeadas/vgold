<?php
/**
 * Victory Genomics CRM - Lead Import Wizard
 * Fixed SQL column reference (full_name), CSRF on forms, Apple-style
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

startSecureSession();
requireLogin();
requireRole(['Admin', 'Sales Manager']);

$currentUser = getCurrentUser();
$db = Database::getInstance();

// Load all users for assigned_to lookup — FIXED: use full_name column
$userLookup = [];
try {
    $usersResult = $db->query("SELECT user_id, full_name, email FROM users");
    $users = $usersResult->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        $userLookup[strtolower(trim($user['full_name']))] = $user['user_id'];
        $userLookup[strtolower(trim($user['email']))] = $user['user_id'];
    }
} catch (Exception $e) { /* Continue without */ }

$crmFields = [
    '' => '-- Skip this column --',
    'company_name' => 'Company Name *',
    'contact_person' => 'Contact Person',
    'title_position' => 'Title / Position',
    'lead_type' => 'Lead Type',
    'email' => 'Email',
    'phone' => 'Phone',
    'mobile' => 'Mobile',
    'website' => 'Website',
    'region' => 'Region',
    'country' => 'Country',
    'city' => 'City',
    'address' => 'Address',
    'specialization' => 'Specialization',
    'facility_type' => 'Facility Type',
    'number_of_horses' => 'Number of Horses',
    'horse_breed' => 'Horse Breed',
    'horse_sex' => 'Horse Sex',
    'facebook_url' => 'Facebook URL',
    'instagram_url' => 'Instagram URL',
    'linkedin_url' => 'LinkedIn URL',
    'twitter_url' => 'Twitter URL',
    'youtube_url' => 'YouTube URL',
    'notes' => 'Notes',
    'lead_status' => 'Lead Status',
    'lead_source' => 'Lead Source',
    'priority' => 'Priority',
    'assigned_to_name' => 'Assigned To (Sales Rep Name)',
    'created_at' => 'Created Time'
];

$autoMappings = [
    'company' => 'company_name', 'company name' => 'company_name', 'organization' => 'company_name', 'account name' => 'company_name',
    'contact' => 'contact_person', 'contact name' => 'contact_person', 'name' => 'contact_person', 'full name' => 'contact_person', 'first name' => 'contact_person', 'lead name' => 'contact_person',
    'title' => 'title_position', 'position' => 'title_position', 'job title' => 'title_position',
    'email' => 'email', 'email address' => 'email', 'phone' => 'phone', 'phone number' => 'phone', 'mobile' => 'mobile', 'mobile phone' => 'mobile', 'cell' => 'mobile',
    'website' => 'website', 'web' => 'website', 'url' => 'website',
    'linkedin' => 'linkedin_url', 'linkedin url' => 'linkedin_url', 'facebook' => 'facebook_url', 'instagram' => 'instagram_url', 'twitter' => 'twitter_url',
    'region' => 'region', 'state' => 'region', 'country' => 'country', 'city' => 'city', 'address' => 'address', 'street' => 'address',
    'notes' => 'notes', 'description' => 'notes', 'comments' => 'notes', 'contact notes' => 'notes',
    'status' => 'lead_status', 'lead status' => 'lead_status', 'source' => 'lead_source', 'lead source' => 'lead_source', 'priority' => 'priority',
    'sales type' => 'lead_type', 'lead type' => 'lead_type', 'type' => 'lead_type',
    'breed' => 'horse_breed', 'horse breed' => 'horse_breed', 'sex' => 'horse_sex', 'horse sex' => 'horse_sex', 'gender' => 'horse_sex', 'number of horses' => 'number_of_horses', 'horses' => 'number_of_horses',
    'assigned to' => 'assigned_to_name', 'lead owner' => 'assigned_to_name', 'owner' => 'assigned_to_name', 'sales rep' => 'assigned_to_name', 'rep' => 'assigned_to_name',
    'created time' => 'created_at', 'created date' => 'created_at', 'created' => 'created_at', 'date created' => 'created_at'
];

$step = $_GET['step'] ?? 1;
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'upload') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['csv_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, ['csv', 'txt'])) {
                $error = 'Please upload a CSV file.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = 'File size must be less than 5MB.';
            } else {
                $handle = fopen($file['tmp_name'], 'r');
                $headers = fgetcsv($handle);
                $sampleRows = [];
                $rowCount = 0;
                while (($row = fgetcsv($handle)) !== false && $rowCount < 100) { $sampleRows[] = $row; $rowCount++; }
                $totalRows = $rowCount;
                while (fgetcsv($handle) !== false) { $totalRows++; }
                fclose($handle);
                
                $_SESSION['import_headers'] = $headers;
                $_SESSION['import_sample'] = array_slice($sampleRows, 0, 5);
                $_SESSION['import_total'] = $totalRows;
                $_SESSION['import_filename'] = $file['name'];
                
                $tempPath = sys_get_temp_dir() . '/crm_import_' . session_id() . '.csv';
                move_uploaded_file($file['tmp_name'], $tempPath);
                $_SESSION['import_file'] = $tempPath;
                
                header('Location: import-leads.php?step=2');
                exit;
            }
        } else {
            $error = 'Please select a CSV file to upload.';
        }
    }
    
    if ($_POST['action'] === 'map') {
        $mappings = $_POST['mapping'] ?? [];
        if (!in_array('company_name', $mappings)) {
            $error = 'You must map a column to "Company Name".';
        } else {
            $_SESSION['import_mappings'] = $mappings;
            header('Location: import-leads.php?step=3');
            exit;
        }
    }
    
    if ($_POST['action'] === 'import') {
        requireCSRF();
        
        $mappings = $_SESSION['import_mappings'] ?? [];
        $filePath = $_SESSION['import_file'] ?? '';
        
        if (!file_exists($filePath)) {
            $error = 'Import file not found. Please start over.';
        } else {
            $handle = fopen($filePath, 'r');
            fgetcsv($handle);
            
            $imported = 0;
            $skipped = 0;
            
            while (($row = fgetcsv($handle)) !== false) {
                $data = ['lead_type' => 'Other', 'lead_status' => 'New Lead', 'lead_source' => 'Import', 'priority' => 'Medium', 'created_by' => $currentUser['user_id']];
                
                foreach ($mappings as $colIndex => $crmField) {
                    if (!empty($crmField) && isset($row[$colIndex])) {
                        $value = trim($row[$colIndex]);
                        if (!empty($value)) {
                            if ($crmField === 'assigned_to_name') {
                                $lookupKey = strtolower($value);
                                if (isset($userLookup[$lookupKey])) { $data['assigned_to'] = $userLookup[$lookupKey]; }
                            } elseif ($crmField === 'created_at') {
                                $timestamp = strtotime($value);
                                if ($timestamp !== false) { $data['created_at'] = date('Y-m-d H:i:s', $timestamp); }
                            } else {
                                $data[$crmField] = sanitizeInput($value);
                            }
                        }
                    }
                }
                
                if (empty($data['company_name'])) { $skipped++; continue; }
                
                try { $db->insert('leads', $data); $imported++; }
                catch (Exception $e) { $skipped++; }
            }
            
            fclose($handle);
            unlink($filePath);
            unset($_SESSION['import_file'], $_SESSION['import_headers'], $_SESSION['import_sample'], $_SESSION['import_mappings'], $_SESSION['import_total'], $_SESSION['import_filename']);
            
            logActivity($currentUser['user_id'], 'Import Leads', 'Lead', null, "Imported $imported leads from CSV");
            $_SESSION['import_success'] = $imported;
            $_SESSION['import_skipped'] = $skipped;
            
            header('Location: import-leads.php?step=4');
            exit;
        }
    }
}

if (isset($_GET['reset'])) {
    if (isset($_SESSION['import_file']) && file_exists($_SESSION['import_file'])) { unlink($_SESSION['import_file']); }
    unset($_SESSION['import_file'], $_SESSION['import_headers'], $_SESSION['import_sample'], $_SESSION['import_mappings'], $_SESSION['import_total'], $_SESSION['import_filename']);
    header('Location: import-leads.php');
    exit;
}

$pageTitle = 'Import Leads';
include '../includes/header.php';
$csrf_token = generateCSRFToken();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <a href="settings.php" class="text-muted back-link">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            </a>
            Import Leads
        </h1>
        <p class="page-subtitle">Import leads from CSV file with custom field mapping</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="import-wizard">
    <ul class="wizard-steps">
        <li class="wizard-step <?php echo $step == 1 ? 'active' : ($step > 1 ? 'completed' : ''); ?>"><div class="wizard-step-number">1</div><div class="wizard-step-title">Upload</div></li>
        <li class="wizard-step <?php echo $step == 2 ? 'active' : ($step > 2 ? 'completed' : ''); ?>"><div class="wizard-step-number">2</div><div class="wizard-step-title">Map Fields</div></li>
        <li class="wizard-step <?php echo $step == 3 ? 'active' : ($step > 3 ? 'completed' : ''); ?>"><div class="wizard-step-number">3</div><div class="wizard-step-title">Preview</div></li>
        <li class="wizard-step <?php echo $step == 4 ? 'active' : ''; ?>"><div class="wizard-step-number">4</div><div class="wizard-step-title">Complete</div></li>
    </ul>

    <?php if ($step == 1): ?>
    <div class="card">
        <div class="card-header"><h3 class="card-title">Upload CSV File</h3></div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="upload">
                <div class="upload-zone" onclick="document.getElementById('csv_file').click()">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <h3>Drop your CSV file here or click to browse</h3>
                    <p class="text-muted">Supports CSV exports from Zoho, Salesforce, HubSpot, or any CRM. Max 5MB.</p>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,.txt" style="display:none;" onchange="document.getElementById('uploadForm').submit()">
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step == 2 && isset($_SESSION['import_headers'])): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Map CSV Columns</h3>
            <span class="badge bg-blue-100 text-blue-800"><?php echo $_SESSION['import_total']; ?> rows</span>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="map">
                <p class="text-muted" style="margin-bottom:1rem;">Match CSV columns to CRM fields. Only <strong>Company Name</strong> is required.</p>
                <div class="table-container">
                    <table class="table">
                        <thead><tr><th>CSV Column</th><th>Map to CRM Field</th><th>Sample</th></tr></thead>
                        <tbody>
                            <?php foreach ($_SESSION['import_headers'] as $index => $header):
                                $headerLower = strtolower(trim($header));
                                $suggested = $autoMappings[$headerLower] ?? ''; ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($header); ?></strong></td>
                                    <td>
                                        <select name="mapping[<?php echo $index; ?>]" class="form-control">
                                            <?php foreach ($crmFields as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $suggested === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="text-muted"><?php $s = $_SESSION['import_sample'][0][$index] ?? ''; echo htmlspecialchars(substr($s, 0, 30)) . (strlen($s) > 30 ? '...' : ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions" style="margin-top:1.5rem;">
                    <a href="import-leads.php?reset=1" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">Preview Import</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step == 3 && isset($_SESSION['import_mappings'])): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Preview Import Data</h3>
            <span class="badge bg-blue-100 text-blue-800"><?php echo $_SESSION['import_total']; ?> rows</span>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table class="table">
                    <thead><tr>
                        <?php $mappings = $_SESSION['import_mappings'];
                        foreach ($mappings as $index => $field):
                            if (!empty($field)): ?>
                                <th><?php echo htmlspecialchars($crmFields[$field]); ?></th>
                            <?php endif; endforeach; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($_SESSION['import_sample'] as $row): ?>
                        <tr>
                            <?php foreach ($mappings as $index => $field):
                                if (!empty($field)): ?>
                                    <td><?php echo htmlspecialchars($row[$index] ?? ''); ?></td>
                                <?php endif; endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="alert alert-warning" style="margin-top:1rem;">
                <strong>Ready to import <?php echo $_SESSION['import_total']; ?> leads.</strong> Rows missing Company Name will be skipped.
            </div>
            
            <form method="POST" style="margin-top:1.5rem;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="import">
                <div class="form-actions">
                    <a href="import-leads.php?step=2" class="btn btn-outline">Back</a>
                    <button type="submit" class="btn btn-success btn-lg">Import Now</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step == 4): ?>
    <div class="card">
        <div class="card-body">
            <div class="import-summary">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--color-success)" stroke-width="1.5" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h2>Import Complete!</h2>
                <p class="text-muted">Your leads have been imported into the CRM.</p>
                <div class="import-stats">
                    <div class="import-stat">
                        <div class="import-stat-value"><?php echo $_SESSION['import_success'] ?? 0; ?></div>
                        <div class="import-stat-label">Imported</div>
                    </div>
                    <?php if (($_SESSION['import_skipped'] ?? 0) > 0): ?>
                    <div class="import-stat">
                        <div class="import-stat-value" style="color:var(--color-warning);"><?php echo $_SESSION['import_skipped']; ?></div>
                        <div class="import-stat-label">Skipped</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-actions" style="justify-content:center;">
                    <a href="leads.php" class="btn btn-primary btn-lg">View All Leads</a>
                    <a href="import-leads.php" class="btn btn-outline">Import More</a>
                </div>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['import_success'], $_SESSION['import_skipped']); endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
