<?php
/**
 * Victory Genomics CRM - Lead Form (Add/Edit)
 * CSRF protected, Apple-style, no FA icons
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/twilio.php';

startSecureSession();
requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$leadId = $_GET['id'] ?? null;
$isEdit = !empty($leadId);
$lead = null;
$errors = [];
$success = '';

// Flash message from redirect (POST-Redirect-GET)
if (!empty($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Load existing lead for editing
if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM leads WHERE lead_id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    
    if (!$lead) {
        header('Location: leads.php');
        exit;
    }
    
    if (!hasRole('Admin') && !hasRole('Sales Manager') && $lead['assigned_to'] != $currentUser['user_id']) {
        die('Access denied');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    requireCSRF();
    
    // Only contact_person (name) is truly required; all other fields are optional
    $fieldErrors = [];
    $contactPerson = trim($_POST['contact_person'] ?? '');
    if ($contactPerson === '') {
        $fieldErrors['contact_person'] = 'Cannot be empty';
    }
    $errors = !empty($fieldErrors) ? array_values($fieldErrors) : [];
    
    if (empty($errors)) {
        $data = [
            'lead_type' => sanitizeInput($_POST['lead_type']),
            'company_name' => sanitizeInput($_POST['company_name']),
            'contact_person' => sanitizeInput($_POST['contact_person']),
            'title_position' => sanitizeInput($_POST['title_position']),
            'country' => sanitizeInput($_POST['country']),
            'city' => sanitizeInput($_POST['city']),
            'address' => sanitizeInput($_POST['address']),
            'phone' => sanitizeInput($_POST['phone']),
            'mobile' => sanitizeInput($_POST['mobile']),
            'email' => sanitizeInput($_POST['email']),
            'website' => sanitizeInput($_POST['website']),
            'facebook_url' => sanitizeInput($_POST['facebook_url']),
            'instagram_url' => sanitizeInput($_POST['instagram_url']),
            'linkedin_url' => sanitizeInput($_POST['linkedin_url']),
            'twitter_url' => sanitizeInput($_POST['twitter_url']),
            'youtube_url' => sanitizeInput($_POST['youtube_url']),
            'specialization' => sanitizeInput($_POST['specialization']),
            'facility_type' => sanitizeInput($_POST['facility_type']),
            'number_of_horses' => !empty($_POST['number_of_horses']) ? (int)$_POST['number_of_horses'] : null,
            'horse_breed' => sanitizeInput($_POST['horse_breed'] ?? ''),
            'horse_sex' => sanitizeInput($_POST['horse_sex'] ?? ''),
            'notes' => sanitizeInput($_POST['notes']),
            'lead_status' => sanitizeInput($_POST['lead_status']),
            'lead_source' => sanitizeInput($_POST['lead_source']),
            'priority' => sanitizeInput($_POST['priority']),
            'assigned_to' => (hasRole('Admin') || hasRole('Sales Manager'))
                ? ($_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null)
                : ($isEdit ? $lead['assigned_to'] : $currentUser['user_id']),
        ];
        
        try {
            if ($isEdit) {
                // Get previous assigned_to before updating
                $prevAssigned = $lead['assigned_to'] ?? null;

                $sql = "UPDATE leads SET 
                    lead_type = ?, company_name = ?, contact_person = ?, title_position = ?,
                    country = ?, city = ?, address = ?,
                    phone = ?, mobile = ?, email = ?, website = ?,
                    facebook_url = ?, instagram_url = ?, linkedin_url = ?, twitter_url = ?, youtube_url = ?,
                    specialization = ?, facility_type = ?, number_of_horses = ?, horse_breed = ?, horse_sex = ?,
                    notes = ?, lead_status = ?, lead_source = ?, priority = ?, assigned_to = ?
                    WHERE lead_id = ?";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $data['lead_type'], $data['company_name'], $data['contact_person'], $data['title_position'],
                    $data['country'], $data['city'], $data['address'],
                    $data['phone'], $data['mobile'], $data['email'], $data['website'],
                    $data['facebook_url'], $data['instagram_url'], $data['linkedin_url'], $data['twitter_url'], $data['youtube_url'],
                    $data['specialization'], $data['facility_type'], $data['number_of_horses'], $data['horse_breed'], $data['horse_sex'],
                    $data['notes'], $data['lead_status'], $data['lead_source'], $data['priority'], $data['assigned_to'],
                    $leadId
                ]);
                
                logActivity($currentUser['user_id'], 'Update Lead', 'Lead', $leadId, "Updated lead: {$data['contact_person']}");

                // Send WhatsApp notification if assignment changed
                if ($data['assigned_to'] && $data['assigned_to'] != $prevAssigned) {
                    TwilioHelper::notifyLeadAssignment(
                        intval($data['assigned_to']),
                        $data['contact_person'] ?: $data['company_name'] ?: 'Lead #' . $leadId,
                        intval($leadId),
                        $currentUser['full_name'] ?? ''
                    );
                }
                
                // Redirect back to the same edit page (POST-Redirect-GET pattern)
                // This re-fetches the lead from DB so updated values are visible immediately
                $_SESSION['success'] = 'Lead updated successfully!';
                header("Location: lead-form.php?id=$leadId");
                exit;
            } else {
                $sql = "INSERT INTO leads (
                    lead_type, company_name, contact_person, title_position,
                    country, city, address,
                    phone, mobile, email, website,
                    facebook_url, instagram_url, linkedin_url, twitter_url, youtube_url,
                    specialization, facility_type, number_of_horses, horse_breed, horse_sex,
                    notes, lead_status, lead_source, priority, assigned_to, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $data['lead_type'], $data['company_name'], $data['contact_person'], $data['title_position'],
                    $data['country'], $data['city'], $data['address'],
                    $data['phone'], $data['mobile'], $data['email'], $data['website'],
                    $data['facebook_url'], $data['instagram_url'], $data['linkedin_url'], $data['twitter_url'], $data['youtube_url'],
                    $data['specialization'], $data['facility_type'], $data['number_of_horses'], $data['horse_breed'], $data['horse_sex'],
                    $data['notes'], $data['lead_status'], $data['lead_source'], $data['priority'], $data['assigned_to'],
                    $currentUser['user_id']
                ]);
                
                $leadId = $db->lastInsertId();
                logActivity($currentUser['user_id'], 'Create Lead', 'Lead', $leadId, "Created lead: {$data['contact_person']}");

                // Send WhatsApp notification if assigned to someone else
                if ($data['assigned_to'] && $data['assigned_to'] != $currentUser['user_id']) {
                    TwilioHelper::notifyLeadAssignment(
                        intval($data['assigned_to']),
                        $data['contact_person'] ?: $data['company_name'] ?: 'New Lead',
                        intval($leadId),
                        $currentUser['full_name'] ?? ''
                    );
                }
                
                header("Location: lead-detail.php?id=$leadId");
                exit;
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$users = getAllUsers();

$pageTitle = $isEdit ? 'Edit Lead' : 'Add New Lead';
include '../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">
        <a href="leads.php" class="text-muted back-link">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </a>
        <?php echo $pageTitle; ?>
    </h1>
</div>

<?php if (!empty($errors) && empty($fieldErrors)): ?>
    <div class="alert alert-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div>
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <span><?php echo htmlspecialchars($success); ?></span>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <?php echo csrfField(); ?>
    
    <div class="form-layout-2-1">
        <!-- Main Information -->
        <div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Contact Details</h2></div>
                <div class="card-body">
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Contact Person <span class="required">*</span></label>
                            <input type="text" name="contact_person" class="form-control <?php echo isset($fieldErrors['contact_person']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($lead['contact_person'] ?? ($_POST['contact_person'] ?? '')); ?>">
                            <?php if (isset($fieldErrors['contact_person'])): ?>
                                <div class="field-error" style="display:block;"><?php echo htmlspecialchars($fieldErrors['contact_person']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Title / Position</label>
                            <input type="text" name="title_position" class="form-control" value="<?php echo htmlspecialchars($lead['title_position'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Country</label>
                            <input type="text" name="country" class="form-control" value="<?php echo htmlspecialchars($lead['country'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Number of Horses</label>
                            <input type="number" name="number_of_horses" class="form-control" min="0" value="<?php echo htmlspecialchars($lead['number_of_horses'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($lead['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Mobile</label>
                            <input type="tel" name="mobile" class="form-control" value="<?php echo htmlspecialchars($lead['mobile'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="text" name="email" class="form-control" placeholder="email@example.com" value="<?php echo htmlspecialchars($lead['email'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Company Information</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($lead['company_name'] ?? ''); ?>">
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Lead Type</label>
                            <select name="lead_type" class="form-control">
                                <option value="">Select Type</option>
                                <?php foreach (['Stable','Owner','Breeder','Trainer','Veterinarian','Consultant','Other'] as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo ($lead['lead_type'] ?? '') === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Facility Type</label>
                            <select name="facility_type" class="form-control">
                                <option value="">Select Facility Type</option>
                                <?php foreach (['Breeding','Racing','Training','Multi-Purpose','Other'] as $ft): ?>
                                    <option value="<?php echo $ft; ?>" <?php echo ($lead['facility_type'] ?? '') === $ft ? 'selected' : ''; ?>><?php echo $ft; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Specialization</label>
                            <input type="text" name="specialization" class="form-control" placeholder="e.g., Thoroughbred Breeding" value="<?php echo htmlspecialchars($lead['specialization'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Horse Breed</label>
                            <input type="text" name="horse_breed" class="form-control" placeholder="e.g., Arabian, Thoroughbred" value="<?php echo htmlspecialchars($lead['horse_breed'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Horse Sex</label>
                            <select name="horse_sex" class="form-control">
                                <option value="">Select Sex</option>
                                <?php foreach (['Stallion','Mare','Gelding','Colt','Filly','Mixed'] as $sex): ?>
                                    <option value="<?php echo $sex; ?>" <?php echo ($lead['horse_sex'] ?? '') === $sex ? 'selected' : ''; ?>><?php echo $sex; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Location</h2></div>
                <div class="card-body">
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($lead['city'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($lead['address'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Social Media &amp; Website</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input type="text" name="website" class="form-control" placeholder="https://" value="<?php echo htmlspecialchars($lead['website'] ?? ''); ?>">
                    </div>
                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Facebook URL</label>
                            <input type="text" name="facebook_url" class="form-control" value="<?php echo htmlspecialchars($lead['facebook_url'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Instagram URL</label>
                            <input type="text" name="instagram_url" class="form-control" value="<?php echo htmlspecialchars($lead['instagram_url'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="grid grid-3">
                        <div class="form-group">
                            <label class="form-label">LinkedIn URL</label>
                            <input type="text" name="linkedin_url" class="form-control" value="<?php echo htmlspecialchars($lead['linkedin_url'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Twitter URL</label>
                            <input type="text" name="twitter_url" class="form-control" value="<?php echo htmlspecialchars($lead['twitter_url'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">YouTube URL</label>
                            <input type="text" name="youtube_url" class="form-control" value="<?php echo htmlspecialchars($lead['youtube_url'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Notes</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <textarea name="notes" class="form-control" rows="5" placeholder="Add any additional information about this lead..."><?php echo htmlspecialchars($lead['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Lead Details</h2></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Lead Status</label>
                        <select name="lead_status" class="form-control">
                            <?php foreach (['New Lead','Contacted','Interested','Not Interested','Schedule Call','Call Scheduled','Demo Scheduled','Proposal Sent','Negotiation','Won','Lost','On Hold'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo ($lead['lead_status'] ?? 'New Lead') === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <?php foreach (['Low','Medium','High','Urgent'] as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo ($lead['priority'] ?? 'Medium') === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Lead Source</label>
                        <select name="lead_source" class="form-control">
                            <?php foreach (['Website','Facebook','Instagram','Google Ads','LinkedIn','Referral','Cold Outreach','Event','Import','Other'] as $src): ?>
                                <option value="<?php echo $src; ?>" <?php echo ($lead['lead_source'] ?? 'Other') === $src ? 'selected' : ''; ?>><?php echo $src; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (hasRole('Admin') || hasRole('Sales Manager')): ?>
                        <div class="form-group">
                            <label class="form-label">Assign To</label>
                            <select name="assigned_to" class="form-control">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['user_id']; ?>" <?php echo ($lead['assigned_to'] ?? '') == $user['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['role']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <?php echo $isEdit ? 'Update Lead' : 'Create Lead'; ?>
                    </button>
                    <a href="leads.php" class="btn btn-outline btn-block" style="margin-top:0.75rem;">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include '../includes/footer.php'; ?>
