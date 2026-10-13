<?php
// api/story-upload.php — Upload story nou
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');

if (!csrf_verify()) json_response(['error'=>'CSRF invalid'],403);
if (!setting('stories_enabled','1')) json_response(['error'=>'Stories dezactivate'],400);

$u = current_user();

// Max 5 stories active per utilizator
$active = DB::fetch("SELECT COUNT(*) as c FROM stories WHERE user_id=? AND expires_at > NOW()", [$u['id']]);
if ($active['c'] >= 5) json_response(['error'=>'Ai deja 5 stories active. Șterge una pentru a adăuga alta.'],400);

if (empty($_FILES['media']['name'])) json_response(['error'=>'Niciun fișier'],400);
$file = $_FILES['media'];
if ($file['error']) json_response(['error'=>'Eroare upload: '.$file['error']],400);

$max_size = 50 * 1024 * 1024; // 50MB
if ($file['size'] > $max_size) json_response(['error'=>'Fișierul depășește 50MB'],400);

$allowed_img = ['image/jpeg','image/png','image/webp'];
$allowed_vid = ['video/mp4','video/quicktime'];
$is_video = in_array($file['type'], $allowed_vid);
$is_image = in_array($file['type'], $allowed_img);
if (!$is_video && !$is_image) json_response(['error'=>'Format neacceptat. Folosește JPEG, PNG, WebP sau MP4.'],400);

$story_path = __DIR__.'/../uploads/stories/';
if (!is_dir($story_path)) mkdir($story_path, 0755, true);

$ext  = $is_video ? 'mp4' : pathinfo($file['name'], PATHINFO_EXTENSION);
$fname = bin2hex(random_bytes(12)) . '.' . strtolower($ext);

if (!move_uploaded_file($file['tmp_name'], $story_path.$fname)) {
    json_response(['error'=>'Eroare la salvarea fișierului'],500);
}

$caption  = mb_substr(sanitize($_POST['caption'] ?? ''), 0, 280);
$link_url = filter_var($_POST['link_url'] ?? '', FILTER_VALIDATE_URL) ? sanitize($_POST['link_url']) : null;

DB::insert('stories', [
    'user_id'    => $u['id'],
    'media_path' => $fname,
    'media_type' => $is_video ? 'video' : 'photo',
    'caption'    => $caption ?: null,
    'link_url'   => $link_url,
    'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
]);

json_response(['ok'=>true, 'msg'=>'Story publicat! Va fi vizibil 24 de ore.']);
