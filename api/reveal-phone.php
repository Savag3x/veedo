<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_logged()) {
    json_response(['error' => 'Trebuie să fii conectat pentru a vedea numărul de telefon.'], 401);
}

$ad_id = (int)($_GET['ad_id'] ?? 0);
if (!$ad_id) json_response(['error' => 'Invalid'], 400);

$ad = DB::fetch("SELECT a.user_id, u.phone, u.name FROM ads a JOIN users u ON u.id=a.user_id WHERE a.id=? AND a.status='active'", [$ad_id]);
if (!$ad) json_response(['error' => 'Anunț negăsit'], 404);

// Don't show owner their own phone
if ($ad['user_id'] == current_user()['id']) {
    json_response(['error' => 'Acesta este propriul tău anunț'], 400);
}

if (empty($ad['phone'])) {
    json_response(['error' => 'Vânzătorul nu a adăugat un număr de telefon'], 404);
}

// Track contact
DB::query("UPDATE ads SET contact_count=contact_count+1 WHERE id=?", [$ad_id]);
DB::query("INSERT INTO admin_log (user_id, action, target_type, target_id, ip) VALUES (?,?,?,?,?)",
    [current_user()['id'], 'phone_reveal', 'ads', $ad_id, $_SERVER['REMOTE_ADDR'] ?? '']);

json_response([
    'phone' => $ad['phone'],
    'phone_clean' => preg_replace('/\s+/', '', $ad['phone']),
    'name' => $ad['name']
]);
