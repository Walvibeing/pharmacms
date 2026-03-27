<?php

function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function generate_slug($string) {
    $slug = strtolower(trim($string));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}

function generate_screen_key() {
    return bin2hex(random_bytes(16));
}

function time_ago($datetime) {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    $title = date('M j, Y g:ia', strtotime($datetime));

    if ($diff->y > 0) $result = $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    elseif ($diff->m > 0) $result = $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    elseif ($diff->d > 0) $result = $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    elseif ($diff->h > 0) $result = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    elseif ($diff->i > 0) $result = $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    else $result = 'Just now';

    return '<time title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . $result . '</time>';
}

function format_bytes($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function format_duration($seconds) {
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', floor($seconds / 3600), floor(($seconds % 3600) / 60), $seconds % 60);
    }
    return sprintf('%d:%02d', floor($seconds / 60), $seconds % 60);
}

function screen_status_class($lastPing) {
    if (!$lastPing) return 'offline';
    $diff = time() - strtotime($lastPing);
    if ($diff < 300) return 'online';    // < 5 mins
    if ($diff < 1800) return 'idle';     // < 30 mins
    return 'offline';
}

function screen_status_label($lastPing) {
    $status = screen_status_class($lastPing);
    return ['online' => 'Online', 'idle' => 'Idle', 'offline' => 'Offline'][$status];
}

function status_badge($status) {
    $classes = [
        'active' => 'badge-success',
        'pending' => 'badge-warning',
        'suspended' => 'badge-danger',
        'inactive' => 'badge-secondary',
        'online' => 'badge-success',
        'idle' => 'badge-warning',
        'offline' => 'badge-danger',
    ];
    $class = $classes[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
}

function mode_badge($mode) {
    $classes = [
        'playlist' => 'badge-primary',
        'single' => 'badge-info',
        'scheduled' => 'badge-warning',
        'emergency' => 'badge-danger',
    ];
    $labels = [
        'playlist' => 'Content Rotation',
        'single' => 'Single Item',
        'scheduled' => 'Scheduled',
        'emergency' => 'Emergency',
    ];
    $class = $classes[$mode] ?? 'badge-secondary';
    $label = $labels[$mode] ?? ucfirst($mode);
    return '<span class="badge ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function flash($key, $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
    } else {
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function redirect($url) {
    // Prevent open redirect - only allow relative URLs or same-origin
    if (preg_match('#^https?://#i', $url) && strpos($url, BASE_URL) !== 0) {
        $url = BASE_URL . 'dashboard.php';
    }
    header('Location: ' . $url);
    exit;
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function media_url($filename) {
    return BASE_URL . 'uploads/media/' . $filename;
}

function thumb_url($filename) {
    if (!$filename) return BASE_URL . 'assets/video-placeholder.svg';
    return BASE_URL . 'uploads/thumbnails/' . $filename;
}

function activity_description($action, $details = null) {
    $labels = [
        'login' => 'Logged in',
        'logout' => 'Logged out',
        'media_uploaded' => 'Uploaded media',
        'media_deleted' => 'Deleted media',
        'playlist_created' => 'Created a content rotation',
        'playlist_updated' => 'Updated a content rotation',
        'playlist_deleted' => 'Deleted a content rotation',
        'screen_created' => 'Registered a new screen',
        'screen_updated' => 'Updated a screen',
        'screen_deleted' => 'Removed a screen',
        'screen_assigned' => 'Assigned content to a screen',
        'location_created' => 'Added a new location',
        'location_updated' => 'Updated a location',
        'location_deleted' => 'Removed a location',
        'user_invited' => 'Invited a team member',
        'user_updated' => 'Updated a team member',
        'emergency_activated' => 'Activated emergency broadcast',
        'emergency_ended' => 'Ended emergency broadcast',
        'schedule_created' => 'Created a schedule',
        'schedule_updated' => 'Updated a schedule',
        'schedule_deleted' => 'Removed a schedule',
    ];
    return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
}
