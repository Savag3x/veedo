<?php
// ============================================
// CRON: Trimite alerte de căutare
// Adaugă în cPanel Cron Jobs:
// php /home/veedo1/public_html/cron/send-alerts.php
// Frecvență: o dată pe zi (0 8 * * *)
// ============================================

define('CRON_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$start = microtime(true);
$sent  = 0;
$errors = 0;

// ── Mentenanță DB (rulează la fiecare execuție zilnică) ───────────────
// ad_views: păstrăm doar ultimele 90 de zile (statistici recente + indexul rămâne mic)
$del_views = DB::query(
    "DELETE FROM ad_views WHERE viewed_at < DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 10000"
)->rowCount();

// notifications: ștergem notificările citite mai vechi de 6 luni
$del_notifs = DB::query(
    "DELETE FROM notifications WHERE is_read=1 AND created_at < DATE_SUB(NOW(), INTERVAL 180 DAY) LIMIT 5000"
)->rowCount();

// rate_limits: ștergem intrările expirate (backup la 1% random din rata_limit())
DB::query("DELETE FROM rate_limits WHERE expires_at < NOW() LIMIT 2000");

echo "[CLEANUP] ad_views șterse: {$del_views} | notificări șterse: {$del_notifs}\n";
// ─────────────────────────────────────────────────────────────────────

$alerts = DB::fetchAll("
    SELECT sa.*, u.name AS user_name, u.email AS user_email, u.id AS user_uid
    FROM search_alerts sa
    JOIN users u ON u.id = sa.user_id
    WHERE sa.is_active = 1
      AND u.is_active = 1
      AND (sa.last_sent IS NULL OR sa.last_sent < DATE_SUB(NOW(), INTERVAL 23 HOUR))
    ORDER BY sa.id ASC
    LIMIT 100
");

foreach ($alerts as $alert) {
    try {
        $since  = $alert['last_sent'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
        $where  = ["a.status='active'", "a.created_at > ?"];
        $params = [$since];

        if (!empty($alert['keywords'])) {
            $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $alert['keywords']) . '%';
            $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
            $params[] = $like; $params[] = $like;
        }
        if (!empty($alert['category_id'])) { $where[] = "a.category_id=?"; $params[] = (int)$alert['category_id']; }
        if (!empty($alert['city']))         { $where[] = "a.city LIKE ?";    $params[] = '%'.$alert['city'].'%'; }
        if (!empty($alert['price_min']))    { $where[] = "a.price >= ?";     $params[] = (float)$alert['price_min']; }
        if (!empty($alert['price_max']))    { $where[] = "a.price <= ?";     $params[] = (float)$alert['price_max']; }

        $w = implode(' AND ', $where);
        $new_ads = DB::fetchAll("
            SELECT a.id, a.title, a.slug, a.price, a.currency, a.city, a.created_at,
                   ap.path AS main_photo
            FROM ads a
            LEFT JOIN ad_photos ap ON ap.ad_id = a.id AND ap.is_primary = 1
            WHERE {$w} ORDER BY a.created_at DESC LIMIT 20
        ", $params);

        DB::query("UPDATE search_alerts SET last_sent=NOW() WHERE id=?", [$alert['id']]);

        if (empty($new_ads)) { echo "[SKIP] Alert #{$alert['id']} — niciun anunț nou\n"; continue; }

        $user = ['id'=>$alert['user_uid'], 'name'=>$alert['user_name'], 'email'=>$alert['user_email']];
        notify_search_alert($user, $alert, $new_ads);
        $sent++;
        echo "[OK] Alert #{$alert['id']} → {$alert['user_email']}: ".count($new_ads)." anunțuri noi\n";

    } catch (Throwable $e) {
        $errors++;
        echo "[ERR] Alert #{$alert['id']}: ".$e->getMessage()."\n";
        error_log("send-alerts: ".$e->getMessage());
    }
}

echo "\nFinal: {$sent} trimise, {$errors} erori, ".round(microtime(true)-$start,2)."s\n";