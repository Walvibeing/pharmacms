<?php
/**
 * Playlists AJAX API
 * Handles: get playlist data, update details, toggle status
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;

// GET playlist details
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($isSuperAdmin) {
        $playlist = fetch_one(
            "SELECT p.*, c.name as company_name,
                (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
                (SELECT COALESCE(SUM(pi2.duration), 0) FROM playlist_items pi2 WHERE pi2.playlist_id = p.id) as total_duration,
                (SELECT COUNT(*) FROM screen_assignments sa WHERE sa.playlist_id = p.id) as screen_count
             FROM playlists p
             JOIN companies c ON p.company_id = c.id
             WHERE p.id = ?",
            [$id]
        );
    } else {
        $playlist = fetch_one(
            "SELECT p.*,
                (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
                (SELECT COALESCE(SUM(pi2.duration), 0) FROM playlist_items pi2 WHERE pi2.playlist_id = p.id) as total_duration,
                (SELECT COUNT(*) FROM screen_assignments sa WHERE sa.playlist_id = p.id) as screen_count
             FROM playlists p
             WHERE p.id = ? AND p.company_id = ?",
            [$id, $companyId]
        );
    }

    if (!$playlist) {
        echo json_encode(['success' => false, 'message' => 'Playlist not found.']);
        exit;
    }

    // Get items
    $items = fetch_all(
        "SELECT pi.*, m.name as media_name, m.file_type, m.filename, m.thumbnail
         FROM playlist_items pi
         JOIN media m ON pi.media_id = m.id
         WHERE pi.playlist_id = ?
         ORDER BY pi.sort_order",
        [$id]
    );

    // Get screens using this playlist
    $screens = fetch_all(
        "SELECT s.name FROM screen_assignments sa JOIN screens s ON sa.screen_id = s.id WHERE sa.playlist_id = ?",
        [$id]
    );

    echo json_encode([
        'success' => true,
        'playlist' => $playlist,
        'items' => $items,
        'screens' => $screens
    ]);
    exit;
}

// POST update playlist details
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($isSuperAdmin) {
        $playlist = fetch_one("SELECT * FROM playlists WHERE id = ?", [$id]);
    } else {
        $playlist = fetch_one("SELECT * FROM playlists WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }

    if (!$playlist) {
        echo json_encode(['success' => false, 'message' => 'Playlist not found.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $loopEnabled = ($_POST['loop_enabled'] ?? '0') === '1' ? 1 : 0;
    $isActive = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Playlist name is required.']);
        exit;
    }

    update('playlists', [
        'name' => $name,
        'description' => $description,
        'loop_enabled' => $loopEnabled,
        'is_active' => $isActive
    ], 'id = ?', [$id]);

    log_activity('playlist_updated', "Updated playlist: {$name}");
    echo json_encode(['success' => true, 'message' => 'Playlist updated.']);
    exit;
}

// GET media library for a playlist's company
if ($action === 'get_media' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($isSuperAdmin) {
        $playlist = fetch_one("SELECT company_id FROM playlists WHERE id = ?", [$id]);
    } else {
        $playlist = fetch_one("SELECT company_id FROM playlists WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }
    if (!$playlist) {
        echo json_encode(['success' => false, 'message' => 'Playlist not found.']);
        exit;
    }

    $media = fetch_all(
        "SELECT id, name, filename, thumbnail, file_type, duration FROM media WHERE company_id = ? AND is_active = 1 ORDER BY name",
        [$playlist['company_id']]
    );

    echo json_encode(['success' => true, 'media' => $media]);
    exit;
}

// POST save playlist items
if ($action === 'save_items' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($isSuperAdmin) {
        $playlist = fetch_one("SELECT * FROM playlists WHERE id = ?", [$id]);
    } else {
        $playlist = fetch_one("SELECT * FROM playlists WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }
    if (!$playlist) {
        echo json_encode(['success' => false, 'message' => 'Playlist not found.']);
        exit;
    }

    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];

    // Validate all media IDs belong to the same company as the playlist
    if (!$isSuperAdmin) {
        $playlistCompany = fetch_one("SELECT company_id FROM playlists WHERE id = ?", [$id]);
        if ($playlistCompany) {
            foreach ($items as $item) {
                $mid = (int)($item['media_id'] ?? 0);
                if ($mid) {
                    $mediaCheck = fetch_one("SELECT company_id FROM media WHERE id = ?", [$mid]);
                    if (!$mediaCheck || $mediaCheck['company_id'] != $playlistCompany['company_id']) {
                        json_response(['success' => false, 'message' => 'One or more media items do not belong to your company.'], 400);
                    }
                }
            }
        }
    }

    delete_row('playlist_items', 'playlist_id = ?', [$id]);

    foreach ($items as $order => $item) {
        insert('playlist_items', [
            'playlist_id' => $id,
            'media_id' => (int)$item['media_id'],
            'sort_order' => $order,
            'duration' => max(1, (int)($item['duration'] ?? 10))
        ]);
    }

    log_activity('playlist_updated', "Updated playlist items: {$playlist['name']}");
    echo json_encode(['success' => true, 'message' => 'Playlist items saved.']);
    exit;
}

// GET list all playlists (lightweight JSON for table refresh)
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($isSuperAdmin) {
        $playlists = fetch_all(
            "SELECT p.id, p.name, p.is_active, c.name as company_name,
                (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
                (SELECT COALESCE(SUM(pi2.duration), 0) FROM playlist_items pi2 WHERE pi2.playlist_id = p.id) as total_duration,
                (SELECT COUNT(*) FROM screen_assignments sa WHERE sa.playlist_id = p.id) as screen_count
             FROM playlists p
             JOIN companies c ON p.company_id = c.id
             ORDER BY c.name, p.created_at DESC"
        );
    } else {
        $playlists = fetch_all(
            "SELECT p.id, p.name, p.is_active,
                (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
                (SELECT COALESCE(SUM(pi2.duration), 0) FROM playlist_items pi2 WHERE pi2.playlist_id = p.id) as total_duration,
                (SELECT COUNT(*) FROM screen_assignments sa WHERE sa.playlist_id = p.id) as screen_count
             FROM playlists p
             WHERE p.company_id = ?
             ORDER BY p.created_at DESC",
            [$companyId]
        );
    }

    $totalPlaylists = count($playlists);
    $activePlaylists = 0;
    $totalItems = 0;
    $totalScreens = 0;
    foreach ($playlists as $pl) {
        $totalItems += (int)$pl['item_count'];
        $totalScreens += (int)$pl['screen_count'];
        if ($pl['is_active']) $activePlaylists++;
    }

    echo json_encode([
        'success' => true,
        'playlists' => $playlists,
        'is_super_admin' => $isSuperAdmin,
        'stats' => [
            'total' => $totalPlaylists,
            'active' => $activePlaylists,
            'items' => $totalItems,
            'screens' => $totalScreens
        ]
    ]);
    exit;
}

// POST duplicate a playlist
if ($action === 'duplicate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Missing playlist ID.']);
        exit;
    }

    if ($isSuperAdmin) {
        $original = fetch_one("SELECT * FROM playlists WHERE id = ?", [$id]);
    } else {
        $original = fetch_one("SELECT * FROM playlists WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }

    if (!$original) {
        echo json_encode(['success' => false, 'message' => 'Playlist not found.']);
        exit;
    }

    $newName = 'Copy of ' . $original['name'];

    $db = get_db();
    $db->exec("BEGIN");
    try {
        $stmt = $db->prepare("INSERT INTO playlists (company_id, location_id, name, description, loop_enabled, is_active) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $original['company_id'],
            $original['location_id'] ?? null,
            $newName,
            $original['description'],
            $original['loop_enabled'] ?? 1,
            $original['is_active']
        ]);
        $newId = $db->lastInsertId();

        // Copy playlist items
        $items = fetch_all("SELECT * FROM playlist_items WHERE playlist_id = ? ORDER BY sort_order", [$id]);
        if (!empty($items)) {
            $insertStmt = $db->prepare("INSERT INTO playlist_items (playlist_id, media_id, sort_order, duration) VALUES (?, ?, ?, ?)");
            foreach ($items as $item) {
                $insertStmt->execute([$newId, $item['media_id'], $item['sort_order'], $item['duration']]);
            }
        }

        $db->exec("COMMIT");
        log_activity('playlist_duplicated', "Duplicated playlist '{$original['name']}' as '{$newName}'");
        echo json_encode(['success' => true, 'message' => 'Playlist duplicated!', 'playlist_id' => $newId, 'name' => $newName]);
        exit;
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        echo json_encode(['success' => false, 'message' => 'Failed to duplicate playlist.']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
