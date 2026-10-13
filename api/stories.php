<?php
// api/stories.php — Stories CRUD + view tracking
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// GET — fetch stories pentru un utilizator
if ($method === 'GET') {
    $user_id = (int)($_GET['user_id'] ?? 0);
    if (!$user_id) json_response(['error'=>'Invalid'],400);

    $uid = current_user()['id'] ?? 0;
    // is_seen calculat în același query (fără N+1)
    $stories = DB::fetchAll("
        SELECT s.id, s.media_path, s.media_type, s.caption, s.link_url, s.link_text, s.views_count, s.created_at,
               u.name as user_name, u.avatar as user_avatar,
               (sv.id IS NOT NULL) AS is_seen
        FROM stories s
        JOIN users u ON u.id=s.user_id
        LEFT JOIN story_views sv ON sv.story_id=s.id AND sv.viewer_id=?
        WHERE s.user_id=? AND s.is_active=1 AND s.expires_at > NOW()
        ORDER BY s.created_at ASC
    ", [$uid, $user_id]);

    foreach ($stories as &$s) {
        $s['media_url'] = BASE_URL . '/uploads/stories/' . rawurlencode($s['media_path']);
        $s['time_ago']  = time_ago($s['created_at']);
        $s['is_seen']   = (bool)$s['is_seen'];
        $s['link_url']  = safe_url($s['link_url'] ?? '', true) ?: null;
    }
    unset($s);

    json_response(['stories' => $stories]);
}

// POST — add story sau mark viewed (CSRF verificat global în bootstrap)
if ($method === 'POST') {
    if (!is_logged()) json_response(['error'=>'Neautorizat'],401);
    $uid = (int)current_user()['id'];

    // JSON action (view tracking)
    $data = request_json();
    if (($data['action'] ?? '') === 'view' && !empty($data['story_id'])) {
        $sid = (int)$data['story_id'];
        // Contorul crește doar la prima vizualizare a fiecărui utilizator
        if (DB::query("INSERT IGNORE INTO story_views (story_id, viewer_id) VALUES (?,?)", [$sid, $uid])->rowCount()) {
            DB::query("UPDATE stories SET views_count=views_count+1 WHERE id=?", [$sid]);
        }
        json_response(['ok'=>true]);
    }

    // Multipart — add new story
    if (!setting_bool('feature_stories') && !setting_bool('stories_enabled')) json_response(['error'=>'Stories dezactivate'],400);
    rate_limit_or_die('story_upload', 10, 3600);

    $active = DB::fetch("SELECT COUNT(*) as c FROM stories WHERE user_id=? AND is_active=1 AND expires_at > NOW()", [$uid]);
    if ((int)$active['c'] >= 5) json_response(['error'=>'Ai deja 5 stories active. Șterge una pentru a adăuga alta.'],400);

    if (empty($_FILES['media']['name'])) json_response(['error'=>'Niciun fișier'],400);
    $file = $_FILES['media'];
    if (!empty($file['error'])) json_response(['error'=>'Eroare la încărcare (cod '.(int)$file['error'].')'],400);

    $maxMb = 30;
    if ($file['size'] > $maxMb * 1024 * 1024) json_response(['error'=>'Fișierul depășește '.$maxMb.'MB'],400);

    // MIME-ul REAL decide tipul și extensia (nu numele/tipul trimis de browser)
    $saved = upload_media_secure($file, BASE_PATH . '/uploads/stories/', true);
    if (!$saved) json_response(['error'=>'Format neacceptat. Folosește JPEG, PNG, WebP sau MP4.'],400);

    $link_url = safe_url($_POST['link_url'] ?? '', true);

    $story_id = DB::insert('stories', [
        'user_id'    => $uid,
        'media_path' => $saved['name'],
        'media_type' => $saved['type'],
        'caption'    => mb_substr(sanitize($_POST['caption'] ?? ''), 0, 500) ?: null,
        'link_url'   => $link_url ?: null,
        'link_text'  => mb_substr(sanitize($_POST['link_text'] ?? ''), 0, 50) ?: null,
        'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
    ]);

    // Notificare followers (insert în bloc, max 500)
    $followers = DB::fetchAll("SELECT follower_id FROM follows WHERE following_id=? LIMIT 500", [$uid]);
    if ($followers) {
        $title = current_user()['name'] . ' a postat o story nouă';
        $rows = []; $params = [];
        foreach ($followers as $f) {
            $rows[] = '(?,?,?,?)';
            array_push($params, (int)$f['follower_id'], 'new_story', $title, '/stories');
        }
        DB::query("INSERT INTO notifications (user_id,type,title,link) VALUES " . implode(',', $rows), $params);
    }

    json_response(['ok'=>true, 'story_id'=>$story_id, 'msg'=>'Story publicat! Va fi vizibil 24 de ore.']);
}

// DELETE — sterge story proprie
if ($method === 'DELETE') {
    if (!is_logged()) json_response(['error'=>'Neautorizat'],401);
    $uid = current_user()['id'];
    $sid = (int)($_GET['id'] ?? 0);
    $s = DB::fetch("SELECT * FROM stories WHERE id=? AND user_id=?", [$sid, $uid]);
    if (!$s) json_response(['error'=>'Story negăsit'],404);
    $f = BASE_PATH . '/uploads/stories/' . basename($s['media_path']);
    if (is_file($f)) @unlink($f);
    DB::query("DELETE FROM stories WHERE id=?", [$sid]);
    json_response(['ok'=>true]);
}

json_response(['error' => 'Method not allowed'], 405);
