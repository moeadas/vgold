<?php
/**
 * Victory Genomics CRM - Helper Functions
 */

/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y') {
    if (!$date) return '-';
    return date($format, strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($datetime, $format = 'M d, Y h:i A') {
    if (!$datetime) return '-';
    return date($format, strtotime($datetime));
}

/**
 * Get time ago
 */
function timeAgo($datetime) {
    if (!$datetime) return '-';
    
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    
    return formatDate($datetime);
}

/**
 * Get lead status badge class
 */
function getStatusBadgeClass($status) {
    $classes = [
        'New Lead' => 'bg-blue-100 text-blue-800',
        'Contacted' => 'bg-indigo-100 text-indigo-800',
        'Interested' => 'bg-green-100 text-green-800',
        'Not Interested' => 'bg-red-100 text-red-800',
        'Schedule Call' => 'bg-yellow-100 text-yellow-800',
        'Call Scheduled' => 'bg-purple-100 text-purple-800',
        'Demo Scheduled' => 'bg-pink-100 text-pink-800',
        'Proposal Sent' => 'bg-orange-100 text-orange-800',
        'Negotiation' => 'bg-teal-100 text-teal-800',
        'Won' => 'bg-green-500 text-white',
        'Lost' => 'bg-gray-500 text-white',
        'On Hold' => 'bg-gray-300 text-gray-800'
    ];
    
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Get priority badge class
 */
function getPriorityBadgeClass($priority) {
    $classes = [
        'Low' => 'bg-gray-100 text-gray-800',
        'Medium' => 'bg-blue-100 text-blue-800',
        'High' => 'bg-orange-100 text-orange-800',
        'Urgent' => 'bg-red-500 text-white'
    ];
    
    return $classes[$priority] ?? 'bg-gray-100 text-gray-800';
}

/**
 * Truncate text
 */
function truncate($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

/**
 * Get file size formatted
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

/**
 * Generate random string
 */
function generateRandomString($length = 16) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Get initials from name
 */
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
        if (strlen($initials) >= 2) break;
    }
    
    return $initials ?: '??';
}

/**
 * Get avatar color based on name
 */
function getAvatarColor($name) {
    $colors = [
        'bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500',
        'bg-teal-500', 'bg-blue-500', 'bg-indigo-500', 'bg-purple-500', 'bg-pink-500'
    ];
    
    $hash = 0;
    for ($i = 0; $i < strlen($name); $i++) {
        $hash = ord($name[$i]) + (($hash << 5) - $hash);
    }
    
    return $colors[abs($hash) % count($colors)];
}

/**
 * Success response JSON
 */
function jsonSuccess($message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Error response JSON
 */
function jsonError($message, $code = 400) {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

/**
 * Validate required fields
 */
function validateRequired($data, $requiredFields) {
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    
    return $errors;
}

/**
 * Get all users for dropdown
 */
function getAllUsers() {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT user_id, full_name, role FROM users WHERE status = 'Active' ORDER BY full_name");
    return $stmt->fetchAll();
}

/**
 * Get user name by ID
 */
function getUserNameById($userId) {
    if (!$userId) return '-';
    
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    return $user ? $user['full_name'] : '-';
}

/**
 * Convert array to CSV
 */
function arrayToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($data)) {
        // Header row
        fputcsv($output, array_keys($data[0]));
        
        // Data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

/**
 * Format phone number
 */
function formatPhone($phone) {
    if (!$phone) return '-';
    
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Format based on length
    $length = strlen($phone);
    
    if ($length == 10) {
        return preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2-$3', $phone);
    } elseif ($length == 11) {
        return preg_replace('/(\d{1})(\d{3})(\d{3})(\d{4})/', '+$1 ($2) $3-$4', $phone);
    }
    
    return $phone;
}
?>
