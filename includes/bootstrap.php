<?php
// display_errors e controlat din config.php bazat pe APP_ENV
// NU îl setăm hardcodat aici
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

session_start_safe();

// Maintenance mode check
// Incarca Web Push helper daca VAPID e configurat
if (defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY && file_exists(__DIR__ . '/push.php')) {
    require_once __DIR__ . '/push.php';
}

// Permite login si admin chiar in mentenanta
$_allow_in_maintenance = ['/login', '/admin', '/logout', '/reset'];
$_current_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$_is_allowed = false;
foreach ($_allow_in_maintenance as $_p) {
    if (str_starts_with($_current_path, $_p)) { $_is_allowed = true; break; }
}

if (setting_bool('maintenance_mode') && !is_admin() && !$_is_allowed) {
    http_response_code(503);
    echo '<html><head><title>Mentenanță</title><meta charset="utf-8"></head><body style="font-family:sans-serif;text-align:center;padding:80px">';
    echo '<h2>🔧 ' . h(setting('maintenance_message', 'Site în mentenanță')) . '</h2>';
    echo '</body></html>';
    exit;
}

// Track referral cookie
if (!empty($_GET['ref']) && !isset($_COOKIE['veedo_ref'])) {
    setcookie('veedo_ref', sanitize($_GET['ref']), time()+86400*30, '/', '', true, true);
}

// ─── Tracking cod afiliat ───────────────────────────────────────
if (!empty($_GET['ref'])) {
    $ref_code = sanitize($_GET['ref']);
    $aff_record = DB::fetch("SELECT id FROM affiliates WHERE affiliate_code=? AND status='active'", [$ref_code]);
    if ($aff_record) {
        $_SESSION['affiliate_id'] = $aff_record['id'];
        $_SESSION['affiliate_ref'] = $ref_code;
        // Logam click
        DB::insert('affiliate_clicks', [
            'affiliate_id' => $aff_record['id'],
            'ad_id'        => (int)($_GET['ad_id'] ?? 0) ?: null,
            'ip_hash'      => hash('sha256', ($_SERVER['REMOTE_ADDR']??'').(date('Y-m-d'))),
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT']??'',0,500),
        ]);
        DB::query("UPDATE affiliates SET clicks_count=clicks_count+1 WHERE id=?", [$aff_record['id']]);
    }
}

?>