<?php
// api/push-subscribe.php — Salveaza/sterge subscription Web Push
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_logged()) json_response(['error' => 'Neautorizat'], 401);
$u   = current_user();
$uid = $u['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);
rate_limit_or_die('push_sub', 20, 3600);
$data = request_json();
if (!$data) json_response(['error' => 'Date invalide'], 400);

$action   = $data['action'] ?? 'subscribe';
$endpoint = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
$p256dh   = (string)($data['keys']['p256dh'] ?? '');
$auth     = (string)($data['keys']['auth'] ?? '');

// Unsubscribe
if ($action === 'unsubscribe') {
    DB::query("DELETE FROM push_subscriptions WHERE user_id=? AND endpoint=?", [$uid, $endpoint]);
    json_response(['ok' => true, 'action' => 'unsubscribed']);
}

// Subscribe
if (!$endpoint || !$p256dh || !$auth) {
    json_response(['error' => 'Date incomplete'], 400);
}
// Anti-SSRF: serverul va trimite POST la acest endpoint, deci acceptăm doar servicii push reale
if (!is_valid_push_endpoint($endpoint)
    || !preg_match('/^[A-Za-z0-9_-]{20,200}$/', $p256dh)
    || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $auth)) {
    json_response(['error' => 'Subscription invalidă'], 400);
}
// Limităm numărul de dispozitive per utilizator
$cnt = (int)(DB::fetch("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id=?", [$uid])['c'] ?? 0);
if ($cnt >= 10) {
    DB::query("DELETE FROM push_subscriptions WHERE user_id=? ORDER BY last_used ASC LIMIT 1", [$uid]);
}

// Detectam browser-ul
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$browser = 'Unknown';
if (str_contains($ua, 'Firefox'))      $browser = 'Firefox';
elseif (str_contains($ua, 'Chrome'))   $browser = 'Chrome';
elseif (str_contains($ua, 'Safari'))   $browser = 'Safari';
elseif (str_contains($ua, 'Edge'))     $browser = 'Edge';

try {
    // Insert sau update subscription
    DB::query("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, browser)
               VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth), last_used=NOW()",
        [$uid, $endpoint, $p256dh, $auth, $browser]);

    json_response(['ok' => true, 'action' => 'subscribed', 'browser' => $browser]);
} catch (Exception $e) {
    error_log('[veedo] push-subscribe: ' . $e->getMessage());
    json_response(['error' => 'Eroare la salvare'], 500);
}
