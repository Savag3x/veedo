<?php
// display_errors e controlat din config.php bazat pe APP_ENV
// NU îl setăm hardcodat aici
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

send_security_headers();
session_start_safe();

// CSRF global: orice POST/PUT/PATCH/DELETE fără token valid este respins
// (înainte de orice logică din pagini/API-uri).
enforce_csrf();

// Incarca Web Push helper daca VAPID e configurat
if (defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY && file_exists(__DIR__ . '/push.php')) {
    require_once __DIR__ . '/push.php';
}

// Maintenance mode — permite login si admin chiar in mentenanta
if (PHP_SAPI !== 'cli' && setting_bool('maintenance_mode') && !is_admin()) {
    $_current_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $_is_allowed = false;
    foreach (['/login', '/admin', '/logout', '/reset'] as $_p) {
        if (str_starts_with($_current_path, $_p)) { $_is_allowed = true; break; }
    }
    if (!$_is_allowed) {
        http_response_code(503);
        header('Retry-After: 3600');
        echo '<!DOCTYPE html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mentenanță</title>'
           . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#0b0b10;color:#f4f4f6;text-align:center;padding:24px}h1{font-size:1.6rem;margin:12px 0 6px}p{opacity:.7}</style></head>'
           . '<body><div><div style="font-size:3rem">🔧</div><h1>' . h(setting('maintenance_message', 'Site în mentenanță')) . '</h1><p>Revenim în curând.</p></div></body></html>';
        exit;
    }
}

// ─── Tracking cod referral / afiliat ────────────────────────────
$_ref = $_GET['ref'] ?? '';
if (is_string($_ref) && $_ref !== '' && preg_match('/^[A-Za-z0-9_-]{4,32}$/', $_ref)) {
    if (!isset($_COOKIE['veedo_ref'])) {
        setcookie('veedo_ref', $_ref, [
            'expires' => time() + 86400 * 30, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    // Un singur click numărat per sesiune și cod (evită umflarea statisticilor prin refresh)
    if (($_SESSION['affiliate_ref'] ?? '') !== $_ref) {
        $aff_record = DB::fetch("SELECT id FROM affiliates WHERE affiliate_code=? AND status='active'", [$_ref]);
        if ($aff_record) {
            $_SESSION['affiliate_id']  = $aff_record['id'];
            $_SESSION['affiliate_ref'] = $_ref;
            DB::insert('affiliate_clicks', [
                'affiliate_id' => $aff_record['id'],
                'ad_id'        => (int)($_GET['ad_id'] ?? 0) ?: null,
                'ip_hash'      => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d')),
                'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
            DB::query("UPDATE affiliates SET clicks_count=clicks_count+1 WHERE id=?", [$aff_record['id']]);
        }
    }
}
unset($_ref);
