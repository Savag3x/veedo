<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Cache-Control: no-store');
if (!is_logged()) json_response(['count' => 0]);
// Folosește cache-ul din sesiune (TTL 60s) — polling-ul la 30s nu mai lovește DB-ul de fiecare dată
json_response(['count' => unread_notifications((int)current_user()['id'])]);
