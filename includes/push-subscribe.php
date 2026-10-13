<?php
// api/push-subscribe.php — Salveaza/sterge subscription Web Push
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_logged()) json_response(['error' => 'Neautorizat'], 401);
$u   = current_user();
$uid = $u['id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) json_response(['error' => 'Date invalide'], 400);

$action   = $data['action'] ?? 'subscribe';
$endpoint = $data['endpoint'] ?? '';
$p256dh   = $data['keys']['p256dh'] ?? '';
$auth     = $data['keys']['auth']   ?? '';

// Unsubscribe
if ($action === 'unsubscribe') {
    DB::query("DELETE FROM push_subscriptions WHERE user_id=? AND endpoint=?", [$uid, $endpoint]);
    json_response(['ok' => true, 'action' => 'unsubscribed']);
}

// Subscribe
if (!$endpoint || !$p256dh || !$auth) {
    json_response(['error' => 'Date incomplete'], 400);
}

// Detectam browser-ul
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$browser = 'Unknown';
if (str_contains($ua, 'Firefox'))      $browser = 'Firefox';
elseif (str_contains($ua, 'Chrome'))   $browser = 'Chrome';
elseif (str_contains($ua, 'Safari'))   $browser = 'Safari';
elseif (str_contains($ua, 'Edge'))     $browser = 'Edge';

try {
    // Auto-create tabela daca nu exista
    DB::query("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` int NOT NULL,
        `endpoint` varchar(1000) NOT NULL,
        `p256dh` varchar(500) NOT NULL,
        `auth` varchar(200) NOT NULL,
        `browser` varchar(50) DEFAULT NULL,
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        `last_used` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_ep` (user_id, endpoint(255)),
        INDEX `idx_user` (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    // Insert sau update subscription
    DB::query("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, browser)
               VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth), last_used=NOW()",
        [$uid, $endpoint, $p256dh, $auth, $browser]);

    json_response(['ok' => true, 'action' => 'subscribed', 'browser' => $browser]);
} catch (Exception $e) {
    json_response(['error' => 'DB error: ' . $e->getMessage()], 500);
}
