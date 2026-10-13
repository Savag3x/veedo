<?php
// api/notif-action.php — Acțiuni notificări (mark read, delete)
require_once __DIR__ . '/../includes/bootstrap.php';
if (!is_logged()) json_response(['error'=>'Neautorizat'], 401);

$uid    = (int)current_user()['id'];
$data   = request_json();
$action = $_POST['action'] ?? $data['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? $data['id'] ?? $_GET['id'] ?? 0);

// Acțiunile care modifică date necesită POST (CSRF verificat global în bootstrap).
// Excepție: read_one (apelat din service worker la click pe notificare) — efect minor, doar pe notificarea proprie.
if (!in_array($action, ['count', 'read_one'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}
if ($action !== 'count') invalidate_unread_cache($uid);

if ($action === 'read_one' && $id) {
    DB::query("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?", [$id, $uid]);
    json_response(['ok' => true]);
}

if ($action === 'delete_one' && $id) {
    DB::query("DELETE FROM notifications WHERE id=? AND user_id=?", [$id, $uid]);
    json_response(['ok' => true]);
}

if ($action === 'read_all') {
    DB::query("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid]);
    json_response(['ok' => true]);
}

if ($action === 'delete_all') {
    DB::query("DELETE FROM notifications WHERE user_id=?", [$uid]);
    json_response(['ok' => true]);
}

if ($action === 'count') {
    $count = (int)DB::fetch("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0", [$uid])['c'];
    json_response(['count' => $count]);
}

json_response(['error' => 'Acțiune invalidă'], 400);
