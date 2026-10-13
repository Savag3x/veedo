<?php
// api/notif-action.php — Acțiuni notificări (mark read, delete)
require_once __DIR__ . '/../includes/bootstrap.php';
if (!is_logged()) json_response(['error'=>'Neautorizat'], 401);

header('Content-Type: application/json');
$uid    = current_user()['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id     = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

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
