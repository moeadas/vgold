<?php
// JSON response helper
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError($message, $code = 400) {
    jsonResponse(['error' => $message], $code);
}

function input() {
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        $data = array_merge($data, $_POST);
    }
    return $data;
}

function requireFields($fields, $source = null) {
    $source = $source ?? input();
    $missing = [];
    foreach ($fields as $f) {
        if (!isset($source[$f]) || $source[$f] === '') $missing[] = $f;
    }
    if ($missing) jsonError('Missing fields: ' . implode(', ', $missing));
}

// ===== STATUS SYSTEM =====

// Task statuses: in_progress, completed
function statusLabel($status) {
    return match($status) {
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        default => 'In Progress',
    };
}

function statusColor($status) {
    return match($status) {
        'in_progress' => '#C99520',
        'completed' => '#6B8E5A',
        default => '#C99520',
    };
}

function statusBg($status) {
    return match($status) {
        'in_progress' => '#F8E6B8',
        'completed' => '#E8F0E4',
        default => '#F8E6B8',
    };
}

// Project health: on_track, at_risk, blocked, completed, cancelled
function healthLabel($health) {
    return match($health) {
        'on_track' => 'On Track',
        'at_risk' => 'At Risk',
        'blocked' => 'Blocked',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'On Track',
    };
}

function healthColor($health) {
    return match($health) {
        'on_track' => '#6B8E5A',
        'at_risk' => '#C99520',
        'blocked' => '#B0432B',
        'completed' => '#6B8E5A',
        'cancelled' => '#9A8A78',
        default => '#6B8E5A',
    };
}

function healthBg($health) {
    return match($health) {
        'on_track' => '#E8F0E4',
        'at_risk' => '#F8E6B8',
        'blocked' => '#F4D6CC',
        'completed' => '#E8F0E4',
        'cancelled' => '#F0E8DC',
        default => '#E8F0E4',
    };
}

// ===== PROGRESS ALGORITHM =====
// Progress = (completed tasks / total tasks) * 100
// If no tasks, progress = 0
function calculateProgress($projectId) {
    $stats = DB::fetch(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );
    
    $total = $stats['total'] ?? 0;
    $completed = $stats['completed'] ?? 0;
    
    if ($total <= 0) return 0;
    
    return (int) round(($completed / $total) * 100);
}

// ===== HEALTH ALGORITHM =====
// on_track: progress >= 50% OR (progress < 50% but due_date > 7 days away)
// at_risk: progress < 50% AND due_date within 7 days
// blocked: has blocked/in_progress tasks past their deadline
// completed: all tasks completed
// cancelled: project cancelled
function calculateHealth($projectId, $dueDate = null) {
    $stats = DB::fetch(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
         FROM tasks WHERE project_id = ?",
        [$projectId]
    );
    
    $total = $stats['total'] ?? 0;
    $completed = $stats['completed'] ?? 0;
    
    // No tasks = on track
    if ($total == 0) return 'on_track';
    
    // All completed = completed
    if ($completed == $total && $completed > 0) return 'completed';
    
    if ($total <= 0) return 'completed';
    
    $progress = ($completed / $total) * 100;
    
    // Check for overdue in-progress tasks
    $overdue = DB::fetch(
        "SELECT COUNT(*) as cnt FROM tasks 
         WHERE project_id = ? AND status = 'in_progress' 
         AND deadline_date IS NOT NULL AND deadline_date < CURDATE()",
        [$projectId]
    );
    
    if ($overdue['cnt'] > 0) return 'blocked';
    
    // Check due date proximity
    if ($dueDate) {
        $daysLeft = (strtotime($dueDate) - time()) / 86400;
        
        if ($daysLeft < 0) return 'blocked'; // Past due date
        if ($progress < 50 && $daysLeft <= 7) return 'at_risk';
    }
    
    return 'on_track';
}

// ===== TASK DEADLINE VALIDATION =====
// Task deadline cannot exceed project due date
function validateTaskDeadline($projectId, $deadlineDate) {
    if (!$deadlineDate) return true;
    
    $project = DB::fetch("SELECT due_date FROM projects WHERE id = ?", [$projectId]);
    if (!$project) return true;
    
    if ($project['due_date'] && $deadlineDate > $project['due_date']) {
        jsonError("Task deadline cannot exceed project due date ({$project['due_date']})");
    }
    
    return true;
}

// ===== UTILITIES =====

function initials($name) {
    $parts = explode(' ', $name);
    if (count($parts) >= 2) return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    return strtoupper(substr($name, 0, 2));
}

function formatDate($date) {
    if (!$date) return 'No date';
    $d = new DateTime($date);
    $now = new DateTime();
    $diff = $now->diff($d);
    if ($diff->days == 0) return 'Due today';
    if ($diff->days == 1 && $d > $now) return 'Due tomorrow';
    if ($d < $now) return 'Overdue ' . $diff->days . 'd';
    if ($diff->days < 7 && $d > $now) return 'Due in ' . $diff->days . 'd';
    return $d->format('M j');
}

function timeAgo($timestamp) {
    $d = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($d);
    if ($diff->days == 0) {
        if ($diff->h == 0) return $diff->i . 'm ago';
        return $diff->h . 'h ago';
    }
    if ($diff->days == 1) return 'yesterday';
    if ($diff->days < 7) return $diff->days . ' days ago';
    return $d->format('M j');
}