<?php
// api/like.php
require_once __DIR__ . '/../includes/bootstrap.php';

// Acceptăm fie X-Requested-With (AJAX) fie Content-Type: application/json
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
           str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
if (!$is_ajax) {
    json_response(['error' => 'Bad request'], 400);
}

$data = json_decode(file_get_contents('php://input'), true);
$ad_id = (int)($data['ad_id'] ?? 0);

rate_limit_or_die("like", 30, 60);

// Verifică CSRF
$_csrf = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_csrf)) {
    json_response(['error' => 'CSRF invalid'], 403);
}
if (!is_logged()) {
    json_response(['success' => false, 'redirect' => '/login']);
}
if (!$ad_id || !DB::fetch("SELECT id FROM ads WHERE id=?", [$ad_id])) {
    json_response(['success' => false, 'error' => 'Invalid ad'], 400);
}

$uid = current_user()['id'];
$existing = DB::fetch("SELECT id FROM likes WHERE user_id=? AND ad_id=?", [$uid, $ad_id]);

if ($existing) {
    DB::query("DELETE FROM likes WHERE user_id=? AND ad_id=?", [$uid, $ad_id]);
    DB::query("UPDATE ads SET likes_count = GREATEST(0, likes_count-1) WHERE id=?", [$ad_id]);
    $liked = false;
} else {
    DB::query("INSERT IGNORE INTO likes (user_id, ad_id) VALUES (?,?)", [$uid, $ad_id]);
    DB::query("UPDATE ads SET likes_count = likes_count+1 WHERE id=?", [$ad_id]);
    $liked = true;
}

$count = (int)DB::fetch("SELECT likes_count FROM ads WHERE id=?", [$ad_id])['likes_count'];
json_response(['success' => true, 'liked' => $liked, 'count' => $count]);
