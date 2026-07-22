<?php
// API Router — handles /api/* requests
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/lib/DB.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Authz.php';
require_once __DIR__ . '/lib/Csrf.php';
require_once __DIR__ . '/lib/Schema.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ProjectController.php';
require_once __DIR__ . '/controllers/TaskController.php';
require_once __DIR__ . '/controllers/MessageController.php';
require_once __DIR__ . '/controllers/SettingsController.php';
require_once __DIR__ . '/controllers/AIController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/NotificationController.php';
require_once __DIR__ . '/controllers/CRMController.php';
require_once __DIR__ . '/controllers/CrmSyncController.php';
require_once __DIR__ . '/lib/CRMTaskBridge.php';
require_once __DIR__ . '/lib/Mail.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/Push.php';

// Get path after /api/
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$path = trim(preg_replace('#^/api#', '', $uri), '/');

// Auth init for all routes
Auth::init();

// Ensure Feature-batch-B schema exists (folders, card order, DM/comment reads,
// notification defaults). Idempotent and self-guarding; no-op once present.
Schema::ensureFeatureBatchB();
Schema::ensureCrm();
Schema::ensureUnifiedModules();

// Route table: pattern => [Controller::method, requiresAuth]
// Pattern format: "METHOD path/with/{params}"
$routes = [
    // Auth
    'POST auth/register' => ['AuthController::registerDisabled', false],
    'POST auth/login' => ['AuthController::login', false],
    'POST auth/logout' => ['AuthController::logout', true],
    'GET auth/microsoft' => ['AuthController::microsoftLogin', false],
    'GET auth/microsoft/callback' => ['AuthController::microsoftCallback', false],
    'GET auth/me' => ['AuthController::me', true],
    
    // Projects
    'GET projects' => ['ProjectController::index', true],
    'GET categories/mine' => ['ProjectController::myCategories', true],
    'GET categories/{id}' => ['ProjectController::category', true],
    'GET projects/unread-counts' => ['ProjectController::unreadChatCounts', true],
    'POST projects' => ['ProjectController::create', true],
    'GET projects/{id}' => ['ProjectController::show', true],
    'PUT projects/{id}' => ['ProjectController::update', true],
    'DELETE projects/{id}' => ['ProjectController::delete', true],
    'POST projects/{id}/members' => ['ProjectController::addMember', true],
    'DELETE projects/{id}/members' => ['ProjectController::removeMember', true],
    'GET projects/{id}/members' => ['ProjectController::listMembers', true],
    'POST projects/{projectId}/chat' => ['MessageController::sendProjectChat', true],
    'POST projects/{projectId}/chat/read' => ['ProjectController::markChatRead', true],
    'POST projects/{projectId}/upload' => ['MessageController::uploadFile', true],
    'POST projects/{projectId}/folders' => ['ProjectController::createFolder', true],
    'DELETE projects/{projectId}/folders/{folderId}' => ['ProjectController::deleteFolder', true],
    'POST projects/{projectId}/file-link' => ['ProjectController::addFileLink', true],
    // Real-time polling + per-user card order + comments feed (Feature batch B)
    'GET state-version' => ['ProjectController::stateVersion', true],
    'GET card-order' => ['ProjectController::cardOrder', true],
    'POST card-order' => ['ProjectController::saveCardOrder', true],
    'GET comments-feed' => ['ProjectController::commentsFeed', true],
    'POST comments-feed/read' => ['ProjectController::markCommentsFeedRead', true],
    'GET files/{id}/download' => ['MessageController::downloadFile', true],
    'GET files/{id}/preview' => ['MessageController::previewFile', true],
    'GET files/{id}/edit' => ['MessageController::editFile', true],
    'DELETE files/{id}' => ['MessageController::deleteFile', true],
    'GET msg-attachments/{id}/download' => ['MessageController::downloadMessageAttachment', true],
    
    // Files
    'GET files' => ['ProjectController::allFiles', true],
    'GET search' => ['ProjectController::search', true],
    
    // Tasks
    'GET tasks/today' => ['TaskController::today', true],
    'GET tasks/all' => ['TaskController::allTasks', true],
    'GET tasks/my-tasks' => ['TaskController::myTasks', true],
    'GET tasks/meeting-points' => ['TaskController::meetingPoints', true],
    'GET tasks/meeting-agenda' => ['TaskController::listAgenda', true],
    'POST tasks/meeting-agenda' => ['TaskController::createAgenda', true],
    'GET tasks/{id}' => ['TaskController::show', true],
    'POST tasks' => ['TaskController::create', true],
    'PUT tasks/{id}' => ['TaskController::update', true],
    'POST tasks/{id}/toggle' => ['TaskController::toggle', true],
    'POST tasks/{id}/upload' => ['TaskController::uploadFile', true],
    'DELETE tasks/{id}' => ['TaskController::delete', true],
    'POST tasks/{id}/comments' => ['TaskController::addComment', true],
    
    // Meeting Agenda (update/delete with id)
    'PUT tasks/meeting-agenda/{id}' => ['TaskController::updateAgenda', true],
    'DELETE tasks/meeting-agenda/{id}' => ['TaskController::deleteAgenda', true],
    
    // Messages
    'GET messages/channels' => ['MessageController::channels', true],
    'POST messages/create-channel' => ['MessageController::createChannel', true],
    'DELETE messages/channel/{channelId}' => ['MessageController::deleteChannel', true],
    'POST messages/start-dm' => ['MessageController::startDM', true],
    'GET messages/mentions' => ['MessageController::mentions', true],
    'GET messages/{channelId}' => ['MessageController::show', true],
    'POST messages/{channelId}' => ['MessageController::send', true],
    
    // Settings
    'GET settings/profile' => ['SettingsController::profile', true],
    'PUT settings/profile' => ['SettingsController::updateProfile', true],
    'PUT settings/password' => ['SettingsController::updatePassword', true],
    'GET settings/notifications' => ['SettingsController::notifications', true],
    'PUT settings/notifications' => ['SettingsController::updateNotifications', true],
    'GET settings/api-keys' => ['SettingsController::apiKeys', true],
    'PUT settings/api-keys' => ['SettingsController::updateApiKey', true],
    'DELETE settings/api-keys' => ['SettingsController::deleteApiKey', true],
    'GET settings/team' => ['SettingsController::team', true],
    'POST settings/invite' => ['SettingsController::invite', true],
    'GET settings/members' => ['SettingsController::workspaceMembers', true],
    'GET settings/crm-role-map' => ['SettingsController::crmRoleMap', true],
    'PUT settings/crm-role-map' => ['SettingsController::updateCrmRoleMap', true],
    'GET settings/smtp' => ['SettingsController::smtp', true],
    'PUT settings/smtp' => ['SettingsController::updateSmtp', true],
    'POST settings/smtp/test' => ['SettingsController::testSmtp', true],
    'POST settings/users' => ['SettingsController::createUser', true],
    'PATCH settings/users/{id}/role' => ['SettingsController::changeRole', true],
    'POST settings/users/{id}/toggle-active' => ['SettingsController::toggleUserActive', true],
    'DELETE settings/users' => ['SettingsController::deleteUser', true],
    'GET settings/module-access' => ['SettingsController::moduleAccess', true],
    'PUT settings/module-access' => ['SettingsController::updateModuleAccess', true],
    'GET settings/crm' => ['SettingsController::crmSettings', true],
    'PUT settings/crm' => ['SettingsController::updateCrmSettings', true],

    // CRM — native modules inside the VGold SPA and shared session
    'GET crm/dashboard' => ['CRMController::dashboard', true],
    'GET crm/leads' => ['CRMController::leads', true],
    'POST crm/leads' => ['CRMController::createLead', true],
    'GET crm/lead-options' => ['CRMController::leadOptions', true],
    'GET crm/interactions' => ['CRMController::interactions', true],
    'POST crm/interactions' => ['CRMController::createInteraction', true],
    'POST crm/sync-followups' => ['CrmSyncController::syncFollowUps', true],
    'GET tasks/{id}/crm-context' => ['CrmSyncController::taskCrmContext', true],
    
    // AI
    'GET ai/providers' => ['AIController::providers', true],
    'POST ai/ask' => ['AIController::ask', true],
    'POST ai/plan-my-day' => ['AIController::planMyDay', true],
    'POST ai/delete-plan' => ['AIController::deletePlan', true],
    'GET ai/day-plan' => ['AIController::getDayPlan', true],
    
    // Admin
    'POST admin/reset' => ['AdminController::reset', true],
    
    // Notifications
    'GET notifications' => ['NotificationController::list', true],
    'POST notifications/{id}/read' => ['NotificationController::markRead', true],
    'POST notifications/read-all' => ['NotificationController::markAllRead', true],
    'GET notifications/unread-count' => ['NotificationController::unreadCount', true],
    'POST notifications/subscribe' => ['NotificationController::subscribe', true],
];

// Match routes
$matched = false;
foreach ($routes as $pattern => $handler) {
    // Split pattern into method and path
    list($routeMethod, $routePath) = explode(' ', $pattern, 2);
    
    // Check HTTP method
    if ($routeMethod !== $method) continue;
    
    // Convert {param} to regex capture groups
    $regex = '#^' . preg_replace('#\{[^}]+\}#', '([^/]+)', $routePath) . '$#';
    
    if (preg_match($regex, $path, $matches)) {
        $requiresAuth = $handler[1];
        if ($requiresAuth) Auth::requireAuth();

        // CSRF protection (H5): validate unsafe methods on authenticated routes.
        // Login/logout and the Microsoft OAuth callback are exempt (login establishes
        // the session; logout only destroys it; callback is an external redirect).
        $unsafe = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $csrfExempt = in_array($routePath, ['auth/login', 'auth/logout', 'auth/register'], true)
            || strpos($routePath, 'auth/microsoft') === 0;
        if ($requiresAuth && $unsafe && !$csrfExempt) {
            Csrf::validate();
        }
        
        $callback = $handler[0];
        $params = array_slice($matches, 1);
        
        if (strpos($callback, '::') !== false) {
            list($class, $methodName) = explode('::', $callback);
            $class::$methodName(...$params);
        } else {
            $callback(...$params);
        }
        $matched = true;
        break;
    }
}

if (!$matched) {
    jsonError('Not found', 404);
}
