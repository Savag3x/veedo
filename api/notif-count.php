<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');
if (!is_logged()) { echo '{"count":0}'; exit; }
$count = (int)DB::fetch("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0", [current_user()['id']])['c'];
echo json_encode(['count' => $count]);
