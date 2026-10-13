<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_logged()) {
    json_response(['error' => 'Trebuie să fii conectat pentru a vedea numărul de telefon.'], 401);
}

// Anti-scraping: max 30 numere dezvăluite / oră per utilizator
rate_limit_or_die('reveal_phone', 30, 3600);

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

// Track contact (o singură dată per sesiune și anunț)
$_SESSION['phone_seen'] ??= [];
if (empty($_SESSION['phone_seen'][$ad_id])) {
    $_SESSION['phone_seen'][$ad_id] = 1;
    DB::query("UPDATE ads SET contact_count=contact_count+1 WHERE id=?", [$ad_id]);
    DB::query("INSERT INTO admin_log (user_id, action, target_type, target_id, ip) VALUES (?,?,?,?,?)",
        [current_user()['id'], 'phone_reveal', 'ads', $ad_id, $_SERVER['REMOTE_ADDR'] ?? '']);
}

$phone = clean_phone($ad['phone']);
header('Cache-Control: no-store');
json_response([
    'phone' => $phone,
    'phone_clean' => preg_replace('/[^0-9+]/', '', $phone),
    'name' => $ad['name']
]);
