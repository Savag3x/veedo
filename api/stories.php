<?php
// api/stories.php — Stories CRUD + view tracking
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// GET — fetch stories pentru un utilizator
if ($method === 'GET') {
    $user_id = (int)($_GET['user_id'] ?? 0);
    if (!$user_id) json_response(['error'=>'Invalid'],400);

    $stories = DB::fetchAll("
        SELECT s.*, u.name as user_name, u.avatar as user_avatar
        FROM stories s
        JOIN users u ON u.id=s.user_id
        WHERE s.user_id=? AND s.is_active=1 AND s.expires_at > NOW()
        ORDER BY s.created_at ASC
    ", [$user_id]);

    $uid = current_user()['id'] ?? null;
    foreach ($stories as &$s) {
        $s['media_url'] = BASE_URL . '/uploads/stories/' . $s['media_path'];
        $s['time_ago']  = time_ago($s['created_at']);
        $s['is_seen']   = $uid ? (bool)DB::fetch("SELECT id FROM story_views WHERE story_id=? AND viewer_id=?", [$s['id'], $uid]) : false;
        unset($s['user_id']); // don't expose
    }

    json_response(['stories' => $stories]);
}

// POST — add story sau mark viewed
if ($method === 'POST') {
    if (!is_logged()) json_response(['error'=>'Neautorizat'],401);
    $uid = current_user()['id'];

    // JSON action (view tracking)
    $raw = file_get_contents('php://input');
    if ($raw) {
        $data = json_decode($raw, true);
        if (($data['action'] ?? '') === 'view' && !empty($data['story_id'])) {
            $sid = (int)$data['story_id'];
            DB::query("INSERT IGNORE INTO story_views (story_id, viewer_id) VALUES (?,?)", [$sid, $uid]);
            DB::query("UPDATE stories SET views_count=views_count+1 WHERE id=?", [$sid]);
            json_response(['ok'=>true]);
        }
    }

    // Multipart — add new story
    if (!csrf_verify()) json_response(['error'=>'CSRF invalid'],403);

    if (empty($_FILES['media']['name'])) json_response(['error'=>'Niciun fișier'],400);

    $file = $_FILES['media'];
    $maxMb = 30;
    if ($file['size'] > $maxMb * 1024 * 1024) json_response(['error'=>'Fișierul depășește '.$maxMb.'MB'],400);

    $isVideo = str_starts_with($file['type'], 'video/');
    $isPhoto = in_array($file['type'], ['image/jpeg','image/png','image/webp']);
    if (!$isVideo && !$isPhoto) json_response(['error'=>'Format neacceptat'],400);

    $dir = __DIR__ . '/../uploads/stories/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ext  = $isVideo ? 'mp4' : pathinfo($file['name'], PATHINFO_EXTENSION);
    $fname = bin2hex(random_bytes(12)) . '.' . strtolower($ext);

    if (!move_uploaded_file($file['tmp_name'], $dir.$fname)) {
        json_response(['error'=>'Eroare la salvare fișier'],500);
    }

    $story_id = DB::insert('stories', [
        'user_id'    => $uid,
        'media_path' => $fname,
        'media_type' => $isVideo ? 'video' : 'photo',
        'caption'    => sanitize(substr($_POST['caption'] ?? '', 0, 500)),
        'link_url'   => sanitize($_POST['link_url'] ?? ''),
        'link_text'  => sanitize(substr($_POST['link_text'] ?? '', 0, 50)),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
    ]);

    // Notificare followers
    $followers = DB::fetchAll("SELECT follower_id FROM follows WHERE following_id=?", [$uid]);
    $me = current_user();
    foreach ($followers as $f) {
        DB::insert('notifications', [
            'user_id' => $f['follower_id'],
            'type'    => 'new_story',
            'title'   => $me['name'] . ' a postat o story nouă',
            'link'    => '/stories',
        ]);
    }

    json_response(['ok'=>true, 'story_id'=>$story_id]);
}

// DELETE — sterge story proprie
if ($method === 'DELETE') {
    if (!is_logged()) json_response(['error'=>'Neautorizat'],401);
    $uid = current_user()['id'];
    $sid = (int)($_GET['id'] ?? 0);
    $s = DB::fetch("SELECT * FROM stories WHERE id=? AND user_id=?", [$sid, $uid]);
    if (!$s) json_response(['error'=>'Story negăsit'],404);
    @unlink(__DIR__ . '/../uploads/stories/' . $s['media_path']);
    DB::query("DELETE FROM stories WHERE id=?", [$sid]);
    json_response(['ok'=>true]);
}
