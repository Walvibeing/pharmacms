<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Not authenticated.'], 401);
}

// CSRF validation for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
}

$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];
$isSuperAdmin = ($_SESSION['role'] ?? '') === 'super_admin';

// Handle media update
if (isset($_GET['action']) && $_GET['action'] === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($isSuperAdmin) {
        $media = fetch_one("SELECT * FROM media WHERE id = ?", [$id]);
    } else {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }
    if (!$media) json_response(['success' => false, 'message' => 'Media not found.']);

    $name = trim($_POST['name'] ?? $media['name']);
    $tags = trim($_POST['tags'] ?? '');
    $duration = (int)($_POST['duration'] ?? $media['duration']);
    if ($duration < 1) $duration = 10;

    update('media', [
        'name' => $name,
        'tags' => $tags,
        'duration' => $duration
    ], 'id = ?', [$id]);

    json_response(['success' => true, 'message' => 'Media updated.']);
}

// Handle file uploads
if (empty($_FILES['files'])) {
    json_response(['success' => false, 'message' => 'No files uploaded.']);
}

$locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
$locationIds = $_POST['location_ids'] ?? [];

// If location_ids[] sent, treat as multi-location (legacy location_id stays null)
if (!empty($locationIds) && is_array($locationIds)) {
    $locationId = null; // company-wide in legacy column
    // Super admin: derive company_id from first selected location
    if ($isSuperAdmin) {
        $firstLid = (int)$locationIds[0];
        if ($firstLid > 0) {
            $loc = fetch_one("SELECT company_id FROM locations WHERE id = ?", [$firstLid]);
            if ($loc) $companyId = $loc['company_id'];
        }
    }
} elseif ($locationId) {
    // Super admin: derive company_id from the selected location
    if ($isSuperAdmin) {
        $loc = fetch_one("SELECT company_id FROM locations WHERE id = ?", [$locationId]);
        if ($loc) $companyId = $loc['company_id'];
    }
}

if ($isSuperAdmin && empty($companyId)) {
    json_response(['success' => false, 'message' => 'Please select a location before uploading.']);
}

$allowedTypes = ['image/jpeg', 'image/png', 'video/mp4'];
$uploaded = [];
$errors = [];

// Ensure upload directories exist
if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
if (!is_dir(THUMB_PATH)) mkdir(THUMB_PATH, 0755, true);

$files = $_FILES['files'];
$fileCount = count($files['name']);

for ($i = 0; $i < $fileCount; $i++) {
    $originalName = $files['name'][$i];
    $tmpPath = $files['tmp_name'][$i];
    $size = $files['size'][$i];
    $error = $files['error'][$i];

    if ($error !== UPLOAD_ERR_OK) {
        $errors[] = "{$originalName}: Upload error.";
        continue;
    }

    if ($size > MAX_FILE_SIZE) {
        $errors[] = "{$originalName}: File too large (max " . format_bytes(MAX_FILE_SIZE) . ").";
        continue;
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);

    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "{$originalName}: Unsupported file type ({$mimeType}).";
        continue;
    }

    $fileType = str_starts_with($mimeType, 'image/') ? 'image' : 'video';
    // Derive safe extension from validated MIME type — never trust user-supplied extension
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'video/mp4' => 'mp4',
    ];
    $ext = $mimeToExt[$mimeType] ?? null;
    if (!$ext) {
        $results[] = ['name' => $originalName, 'success' => false, 'error' => 'Unsupported file type.'];
        continue;
    }
    $newFilename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destPath = UPLOAD_PATH . $newFilename;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        $errors[] = "{$originalName}: Failed to save file.";
        continue;
    }

    // Generate thumbnail
    $thumbFilename = null;
    if ($fileType === 'image') {
        $thumbFilename = 'thumb_' . $newFilename;
        $thumbDest = THUMB_PATH . $thumbFilename;
        createImageThumbnail($destPath, $thumbDest, $mimeType);
    } else {
        // Try ffmpeg for video thumbnail
        $thumbFilename = 'thumb_' . pathinfo($newFilename, PATHINFO_FILENAME) . '.jpg';
        $thumbDest = THUMB_PATH . $thumbFilename;
        $ffmpegResult = @shell_exec("ffmpeg -i " . escapeshellarg($destPath) . " -ss 00:00:01 -vframes 1 -vf scale=400:225 " . escapeshellarg($thumbDest) . " 2>&1");
        if (!file_exists($thumbDest)) {
            $thumbFilename = null;
        }
    }

    // Determine duration
    $duration = 10;
    if ($fileType === 'video') {
        $durationOutput = @shell_exec("ffprobe -v error -show_entries format=duration -of csv=p=0 " . escapeshellarg($destPath) . " 2>&1");
        if ($durationOutput && is_numeric(trim($durationOutput))) {
            $duration = (int)round((float)trim($durationOutput));
        }
    }

    $mediaName = pathinfo($originalName, PATHINFO_FILENAME);

    $mediaId = insert('media', [
        'company_id' => $companyId,
        'location_id' => $locationId,
        'uploaded_by' => $userId,
        'name' => $mediaName,
        'filename' => $newFilename,
        'original_filename' => $originalName,
        'file_type' => $fileType,
        'mime_type' => $mimeType,
        'file_size' => $size,
        'duration' => $duration,
        'thumbnail' => $thumbFilename,
        'is_active' => 1
    ]);

    // Insert location assignments into junction table
    if (!empty($locationIds) && is_array($locationIds)) {
        foreach ($locationIds as $lid) {
            $lid = (int)$lid;
            if ($lid > 0) {
                insert('media_locations', ['media_id' => $mediaId, 'location_id' => $lid]);
            }
        }
    } elseif ($locationId) {
        insert('media_locations', ['media_id' => $mediaId, 'location_id' => $locationId]);
    }

    $uploaded[] = [
        'id' => $mediaId,
        'name' => $mediaName,
        'filename' => $newFilename,
        'thumbnail' => $thumbFilename ? thumb_url($thumbFilename) : null,
        'type' => $fileType,
        'file_size' => $size,
        'duration' => $duration
    ];
}

// Log activity
if (!empty($uploaded)) {
    require_once __DIR__ . '/../auth.php';
    log_activity('media_uploaded', 'Uploaded ' . count($uploaded) . ' file(s)');
}

if (!empty($errors) && empty($uploaded)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)]);
} else {
    $msg = count($uploaded) . ' file(s) uploaded successfully.';
    if (!empty($errors)) $msg .= ' ' . count($errors) . ' file(s) had errors.';
    json_response(['success' => true, 'message' => $msg, 'files' => $uploaded]);
}

function createImageThumbnail($source, $dest, $mimeType) {
    $width = 400;
    $height = 225;

    switch ($mimeType) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($source); break;
        case 'image/png': $img = @imagecreatefrompng($source); break;
        default: return false;
    }

    if (!$img) return false;

    $srcW = imagesx($img);
    $srcH = imagesy($img);
    $thumb = imagecreatetruecolor($width, $height);

    // Fill with white
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);

    // Scale to cover
    $scale = max($width / $srcW, $height / $srcH);
    $newW = (int)($srcW * $scale);
    $newH = (int)($srcH * $scale);
    $x = (int)(($width - $newW) / 2);
    $y = (int)(($height - $newH) / 2);

    imagecopyresampled($thumb, $img, $x, $y, 0, 0, $newW, $newH, $srcW, $srcH);
    imagejpeg($thumb, $dest, 85);
    imagedestroy($img);
    imagedestroy($thumb);
    return true;
}
