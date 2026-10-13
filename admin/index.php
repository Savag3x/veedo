<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

$section = sanitize($_GET['s'] ?? 'dashboard');
$seo_title = 'Admin — ' . setting('site_name');

// ============================================
// PROCESS POST ACTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    // SETTINGS
    // Complete order manual
    if (isset($_POST['action']) && $_POST['action'] === 'complete_order') {
        $oid = (int)($_POST['order_id'] ?? 0);
        require_once __DIR__ . '/../includes/payments.php';
        if (order_mark_paid($oid)) {
            admin_log('order_complete', 'orders', $oid, 'Comandă marcată completă manual');
            flash('success', 'Comanda #'.$oid.' marcată ca finalizată!');
        }
        redirect('/admin?s=orders');
    }

    // Test SMTP
    if ($section === 'settings' && isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
        $to = sanitize($_POST['test_email'] ?? current_user()['email']);
        require_once __DIR__ . '/../includes/mailer.php';
        $ok = veedo_mail($to, 'Test', 'Test Email Veedo ✅', '<h2>Email de test</h2><p>SMTP funcționează corect! Trimis la '.date('d.m.Y H:i').'</p>');
        header('Content-Type: application/json');
        echo json_encode(['ok'=>$ok, 'msg'=>$ok ? 'Email trimis cu succes la '.$to : 'Eroare trimitere. Verifică setările SMTP.']);
        exit;
    }

    if ($section === 'settings') {
        // Citește valorile curente ÎNAINTE de save (pentru diff)
        $before_rows = DB::fetchAll("SELECT setting_key, setting_value FROM settings");
        $before      = array_column($before_rows, 'setting_value', 'setting_key');
        $allowed     = array_keys($before);

        foreach ($_POST as $k => $v) {
            if ($k === 'csrf_token' || $k === 'active_tab') continue;
            if (in_array($k, $allowed)) {
                setting_update($k, is_array($v) ? implode(',', $v) : admin_setting_value($k, (string)$v));
            }
        }
        // Handle checkbox booleans (unchecked = 0)
        $bool_keys = ['registration_open','maintenance_mode','netopia_live',
                      'ad_auto_approve','require_email_verification','phone_visible_default','comment_auto_approve'];
        foreach ($bool_keys as $bk) {
            if (in_array($bk, $allowed) && !isset($_POST[$bk])) {
                setting_update($bk, '0');
            }
        }

        // Calculeaza diff dupa save
        $after_rows = DB::fetchAll("SELECT setting_key, setting_value FROM settings");
        $after      = array_column($after_rows, 'setting_value', 'setting_key');
        $changed    = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? '') !== $v) {
                $changed[$k] = [
                    'from' => mb_substr((string)($before[$k] ?? ''), 0, 200),
                    'to'   => mb_substr((string)$v, 0, 200),
                ];
            }
        }

        if ($changed) {
            admin_log('settings_update', 'settings', 0, json_encode($changed, JSON_UNESCAPED_UNICODE));
            flash('success', count($changed) . ' setări actualizate cu succes.');
        } else {
            flash('info', 'Nicio modificare detectată.');
        }
        $active_tab = sanitize($_POST['active_tab'] ?? '');
        redirect('/admin?s=settings' . ($active_tab ? '&tab=' . urlencode($active_tab) : ''));
    }

    // ADS
    if ($section === 'ads' && isset($_POST['action'])) {
        $ad_id = (int)($_POST['ad_id'] ?? 0);

        // ===== BULK ACTIONS =====
        if (str_starts_with($_POST['action'], 'bulk_')) {
            $bulk_ids = array_map('intval', (array)($_POST['ad_ids'] ?? []));
            $bulk_ids = array_filter($bulk_ids, fn($x) => $x > 0);
            if (!$bulk_ids) {
                flash('error', 'Niciun anunț selectat.');
                redirect('/admin?s=ads&status=' . ($_GET['status'] ?? 'all'));
            }
            $count = count($bulk_ids);
            $in_placeholders = implode(',', array_fill(0, $count, '?'));

            switch ($_POST['action']) {
                case 'bulk_approve':
                    $days = (int)setting('anunt_expiry_days', '60');
                    DB::query("UPDATE ads SET status='active', expires_at=DATE_ADD(NOW(), INTERVAL {$days} DAY) WHERE id IN ($in_placeholders)", $bulk_ids);
                    foreach ($bulk_ids as $id) {
                        admin_log('ad_approve', 'ads', $id, '[bulk]');
                        try {
                            $na = DB::fetch("SELECT * FROM ads WHERE id=?", [$id]);
                            $nu = DB::fetch("SELECT * FROM users WHERE id=?", [$na['user_id'] ?? 0]);
                            if ($na && $nu) notify_ad_approved($na, $nu);
                        } catch (Exception $ex) {}
                    }
                    flash('success', "✅ {$count} anunțuri aprobate.");
                    break;

                case 'bulk_reject':
                    $reason = sanitize($_POST['reason'] ?? '');
                    DB::query("UPDATE ads SET status='rejected', reject_reason=? WHERE id IN ($in_placeholders)", array_merge([$reason], $bulk_ids));
                    foreach ($bulk_ids as $id) {
                        admin_log('ad_reject', 'ads', $id, '[bulk] ' . $reason);
                        try {
                            $na = DB::fetch("SELECT * FROM ads WHERE id=?", [$id]);
                            $nu = DB::fetch("SELECT * FROM users WHERE id=?", [$na['user_id'] ?? 0]);
                            if ($na && $nu) {
                                notify_ad_rejected($na, $nu, $reason);
                                DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
                                    [$nu['id'],'ad_rejected','Anunțul tău a fost respins','Motiv: '.substr($reason,0,80),'/anunt/nou']);
                            }
                        } catch (Exception $ex) {}
                    }
                    flash('success', "✗ {$count} anunțuri respinse.");
                    break;

                case 'bulk_delete':
                    DB::query("DELETE FROM ads WHERE id IN ($in_placeholders)", $bulk_ids);
                    foreach ($bulk_ids as $id) admin_log('ad_delete', 'ads', $id, '[bulk]');
                    flash('success', "🗑️ {$count} anunțuri șterse permanent.");
                    break;

                default:
                    flash('error', 'Acțiune necunoscută.');
            }
            redirect('/admin?s=ads&status=' . ($_GET['status'] ?? 'all'));
        }

        // ===== SINGLE ACTIONS (existing) =====
        switch ($_POST['action']) {
            case 'approve':
                $days = (int)setting('anunt_expiry_days', '60');
                DB::query("UPDATE ads SET status='active', expires_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?", [$days, $ad_id]);
                admin_log('ad_approve', 'ads', $ad_id);
                // Email notification
                $notif_ad = DB::fetch("SELECT * FROM ads WHERE id=?", [$ad_id]);
                $notif_user = DB::fetch("SELECT * FROM users WHERE id=?", [$notif_ad['user_id'] ?? 0]);
                if ($notif_ad && $notif_user) notify_ad_approved($notif_ad, $notif_user);
                flash('success', 'Anunț aprobat!');
                break;
            case 'reject':
                $reason = sanitize($_POST['reason'] ?? '');
                DB::query("UPDATE ads SET status='rejected', reject_reason=? WHERE id=?", [$reason, $ad_id]);
                admin_log('ad_reject', 'ads', $ad_id, $reason);
                try {
                    $notif_ad = DB::fetch("SELECT * FROM ads WHERE id=?", [$ad_id]);
                    $notif_u  = DB::fetch("SELECT * FROM users WHERE id=?", [$notif_ad['user_id']??0]);
                    if ($notif_ad && $notif_u) {
                        notify_ad_rejected($notif_ad, $notif_u, $reason);
                        DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
                            [$notif_u['id'],'ad_rejected','Anunțul tău a fost respins','Motiv: '.substr($reason,0,80),'/anunt/nou']);
                    }
                } catch (Exception $ex) {}
                flash('success', 'Anunț respins!');
                break;
            case 'delete':
                DB::query("DELETE FROM ads WHERE id=?", [$ad_id]);
                admin_log('ad_delete', 'ads', $ad_id);
                flash('success', 'Anunț șters!');
                break;
            case 'boost':
                $days = (int)($_POST['boost_days'] ?? 7);
                DB::query("UPDATE ads SET is_boosted=1, boosted_until=DATE_ADD(NOW(), INTERVAL ? DAY), boost_type='standard' WHERE id=?", [$days, $ad_id]);
                admin_log('ad_boost', 'ads', $ad_id, "boost {$days} zile");
                flash('success', "Anunț promovat pentru {$days} zile!");
                break;
            case 'unboost':
                DB::query("UPDATE ads SET is_boosted=0, boosted_until=NULL WHERE id=?", [$ad_id]);
                admin_log('ad_unboost', 'ads', $ad_id);
                flash('success', 'Promovare eliminată!');
                break;
        }
        redirect('/admin?s=ads&status=' . ($_GET['status'] ?? 'all'));
    }

    // USERS
    if ($section === 'users' && isset($_POST['action'])) {
        $uid2 = (int)($_POST['user_id'] ?? 0);
        switch ($_POST['action']) {
            case 'ban':
                DB::query("UPDATE users SET is_active=0 WHERE id=?", [$uid2]);
                admin_log('user_ban', 'users', $uid2);
                flash('success', 'Utilizator blocat!');
                break;
            case 'activate':
                DB::query("UPDATE users SET is_active=1 WHERE id=?", [$uid2]);
                admin_log('user_activate', 'users', $uid2);
                flash('success', 'Utilizator activat!');
                break;
            case 'verify_business':
                DB::query("UPDATE users SET is_verified_business=1, role='business', business_verified_until=DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id=?", [$uid2]);
                admin_log('user_verify_business', 'users', $uid2);
                flash('success', 'Business verificat!');
                break;
            case 'unverify_business':
                DB::query("UPDATE users SET is_verified_business=0 WHERE id=?", [$uid2]);
                admin_log('user_unverify_business', 'users', $uid2);
                flash('success', 'Verificare business eliminată!');
                break;
            case 'make_moderator':
                DB::query("UPDATE users SET role='moderator' WHERE id=? AND role='user'", [$uid2]);
                admin_log('user_make_moderator', 'users', $uid2);
                flash('success', 'Utilizator promovat ca moderator!');
                break;
            case 'remove_moderator':
                DB::query("UPDATE users SET role='user' WHERE id=? AND role='moderator'", [$uid2]);
                admin_log('user_remove_moderator', 'users', $uid2);
                flash('success', 'Rol moderator eliminat!');
                break;
        }
        redirect('/admin?s=users');
    }

    // CATEGORIES
    if ($section === 'categories') {
        if ($_POST['action'] === 'save') {
            $data = [
                'name'        => sanitize($_POST['name'] ?? ''),
                'slug'        => slug(sanitize($_POST['name'] ?? '')),
                'icon'        => sanitize($_POST['icon'] ?? '📦'),
                'color'       => safe_color($_POST['color'] ?? '#888888'),
                'description' => sanitize($_POST['description'] ?? ''),
                'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                'is_active'   => (int)isset($_POST['is_active']),
                'meta_title'  => sanitize($_POST['meta_title'] ?? ''),
                'meta_description' => sanitize($_POST['meta_description'] ?? ''),
            ];
            $cat_id = (int)($_POST['cat_id'] ?? 0);
            if ($cat_id) {
                DB::update('categories', $data, 'id=?', [$cat_id]);
                flash('success', 'Categorie actualizată!');
            } else {
                DB::insert('categories', $data);
                flash('success', 'Categorie adăugată!');
            }
            admin_log('category_save', 'categories', $cat_id);
        } elseif ($_POST['action'] === 'delete') {
            $cat_id = (int)$_POST['cat_id'];
            $count = (int)DB::fetch("SELECT COUNT(*) c FROM ads WHERE category_id=?", [$cat_id])['c'];
            if ($count > 0) {
                flash('error', "Nu poți șterge categoria — are $count anunțuri active!");
            } else {
                DB::query("DELETE FROM categories WHERE id=?", [$cat_id]);
                flash('success', 'Categorie ștearsă!');
                admin_log('category_delete', 'categories', $cat_id);
            }
        }
        cache_forget('footer_cats');
        redirect('/admin?s=categories');
    }

    // PAGES - with full edit support
    if ($section === 'pages') {
        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            $page_id = (int)($_POST['page_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $slug_val = $_POST['slug'] ? slug(sanitize($_POST['slug'])) : slug($title);
            
            // Check slug uniqueness
            $existing = DB::fetch("SELECT id FROM pages WHERE slug=? AND id!=?", [$slug_val, $page_id]);
            if ($existing) $slug_val = $slug_val . '-' . time();
            
            $data = [
                'title'            => $title,
                'slug'             => $slug_val,
                'content'          => $_POST['content'] ?? '',
                'meta_title'       => sanitize($_POST['meta_title'] ?? ''),
                'meta_description' => sanitize($_POST['meta_description'] ?? ''),
                'is_active'        => (int)isset($_POST['is_active']),
                'in_footer'        => (int)isset($_POST['in_footer']),
                'sort_order'       => (int)($_POST['sort_order'] ?? 0),
            ];
            if ($page_id) {
                DB::update('pages', $data, 'id=?', [$page_id]);
                flash('success', 'Pagina a fost actualizată!');
            } else {
                DB::insert('pages', $data);
                flash('success', 'Pagina a fost creată!');
            }
            admin_log('page_save', 'pages', $page_id, $title);
            cache_forget('footer_pages');
        } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $page_id = (int)$_POST['page_id'];
            DB::query("DELETE FROM pages WHERE id=?", [$page_id]);
            admin_log('page_delete', 'pages', $page_id);
            flash('success', 'Pagina a fost ștearsă!');
        }
        redirect('/admin?s=pages');
    }

    // REPORTS
    if ($section === 'reports' && isset($_POST['action'])) {
        $report_id = (int)($_POST['report_id'] ?? 0);
        $report = DB::fetch("SELECT * FROM reports WHERE id=?", [$report_id]);
        if ($report) {
            if ($_POST['action'] === 'resolve') {
                DB::query("UPDATE reports SET status='resolved' WHERE id=?", [$report_id]);
                admin_log('report_resolve', 'reports', $report_id);
                flash('success', 'Raport marcat ca rezolvat!');
            } elseif ($_POST['action'] === 'dismiss') {
                DB::query("UPDATE reports SET status='reviewed' WHERE id=?", [$report_id]);
                admin_log('report_dismiss', 'reports', $report_id);
                flash('success', 'Raport ignorat!');
            } elseif ($_POST['action'] === 'delete_ad' && $report['ad_id']) {
                DB::query("UPDATE ads SET status='rejected', reject_reason='Eliminat urmare a raportului de abuz' WHERE id=?", [$report['ad_id']]);
                DB::query("UPDATE reports SET status='resolved' WHERE id=?", [$report_id]);
                admin_log('report_delete_ad', 'ads', $report['ad_id']);
                flash('success', 'Anunț eliminat și raport rezolvat!');
            } elseif ($_POST['action'] === 'ban_user' && $report['ad_id']) {
                $ad = DB::fetch("SELECT user_id FROM ads WHERE id=?", [$report['ad_id']]);
                if ($ad) {
                    DB::query("UPDATE users SET is_active=0 WHERE id=?", [$ad['user_id']]);
                    DB::query("UPDATE reports SET status='resolved' WHERE id=?", [$report_id]);
                    admin_log('report_ban_user', 'users', $ad['user_id']);
                    flash('success', 'Utilizator blocat și raport rezolvat!');
                }
            }
        }
        redirect('/admin?s=reports');
    }
}

// ============================================
// FETCH DATA
// ============================================
$data = [];

if ($section === 'dashboard') {
    $data['stats'] = DB::fetch("SELECT
        (SELECT COUNT(*) FROM ads WHERE status='active') as ads_active,
        (SELECT COUNT(*) FROM ads WHERE status='pending') as ads_pending,
        (SELECT COUNT(*) FROM users WHERE is_active=1) as users_active,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()) as users_today,
        (SELECT COUNT(*) FROM ads WHERE DATE(created_at)=CURDATE()) as ads_today,
        (SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='completed') as revenue,
        (SELECT COUNT(*) FROM orders WHERE status='completed') as orders_count,
        (SELECT COUNT(*) FROM reports WHERE status='pending') as reports_pending,
        (SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE()) as messages_today,
        (SELECT COALESCE(SUM(views_count),0) FROM ads) as total_views
    ");
    $data['pending_ads'] = DB::fetchAll("SELECT a.*, u.name as uname, c.name as cname FROM ads a JOIN users u ON u.id=a.user_id JOIN categories c ON c.id=a.category_id WHERE a.status='pending' ORDER BY a.created_at ASC LIMIT 5");
    $data['recent_ads'] = DB::fetchAll("SELECT a.*, u.name as uname, c.name as cname FROM ads a JOIN users u ON u.id=a.user_id JOIN categories c ON c.id=a.category_id ORDER BY a.created_at DESC LIMIT 8");
    $data['recent_users'] = DB::fetchAll("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
    // Date pentru grafic - ultimele 14 zile
    $data['chart_ads'] = DB::fetchAll("
        SELECT DATE(created_at) as day, COUNT(*) as count
        FROM ads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY day ASC
    ");
    $data['chart_views'] = DB::fetchAll("
        SELECT DATE(created_at) as day, COUNT(*) as count
        FROM admin_log WHERE action='ad_view' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY day ASC
    ");
    $data['chart_users'] = DB::fetchAll("
        SELECT DATE(created_at) as day, COUNT(*) as count
        FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY DATE(created_at) ORDER BY day ASC
    ");

    // === Dashboard 2.0 — date noi ===
    // Trenduri ieri vs azi
    $data['yesterday'] = DB::fetch("SELECT
        (SELECT COUNT(*) FROM ads   WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) AS ads_yesterday,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) AS users_yesterday,
        (SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='completed' AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) AS revenue_yesterday,
        (SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='completed' AND DATE(created_at) = CURDATE()) AS revenue_today
    ");

    // Top 5 categorii după număr de anunțuri active
    $data['top_categories'] = DB::fetchAll("
        SELECT c.id, c.name, c.icon, c.color,
               COUNT(a.id) AS ads_count,
               COALESCE(SUM(a.views_count),0) AS total_views
        FROM categories c
        LEFT JOIN ads a ON a.category_id=c.id AND a.status='active'
        GROUP BY c.id, c.name, c.icon, c.color
        HAVING ads_count > 0
        ORDER BY ads_count DESC
        LIMIT 5
    ");

    // Top 5 anunțuri active după vizualizări (lifetime)
    $data['top_ads'] = DB::fetchAll("
        SELECT a.id, a.title, a.slug, a.views_count, a.created_at,
               u.name AS uname, c.name AS cname, c.icon AS cicon
        FROM ads a
        JOIN users u ON u.id=a.user_id
        JOIN categories c ON c.id=a.category_id
        WHERE a.status='active'
        ORDER BY a.views_count DESC
        LIMIT 5
    ");

    // === ACTIVITY FEED (last 7 days, 30 events max) ===
    try {
        $data['activity'] = DB::fetchAll("
            SELECT * FROM (
                SELECT 'ad_posted' AS event_type, a.id AS ref_id,
                       LEFT(a.title, 70) AS title, u.name AS actor, a.created_at AS event_at,
                       CONCAT('/anunt/', a.slug) AS link, a.status AS extra
                FROM ads a JOIN users u ON u.id=a.user_id
                WHERE a.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                UNION ALL
                SELECT 'user_registered' AS event_type, u.id AS ref_id,
                       u.name AS title, COALESCE(u.email, '') AS actor, u.created_at AS event_at,
                       CONCAT('/admin?s=users&q=', COALESCE(u.email,'')) AS link, NULL AS extra
                FROM users u
                WHERE u.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                UNION ALL
                SELECT 'report' AS event_type, r.id AS ref_id,
                       COALESCE(r.reason, 'raport') AS title,
                       COALESCE(ur.name, 'anonim') AS actor, r.created_at AS event_at,
                       '/admin?s=reports' AS link, r.status AS extra
                FROM reports r LEFT JOIN users ur ON ur.id=r.reporter_id
                WHERE r.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                UNION ALL
                SELECT 'order_completed' AS event_type, o.id AS ref_id,
                       CONCAT(o.amount, ' ', COALESCE(o.currency,'RON'), ' · ', o.type) AS title,
                       COALESCE(uo.name, 'user #' ) AS actor, o.created_at AS event_at,
                       '/admin?s=orders' AS link, o.status AS extra
                FROM orders o LEFT JOIN users uo ON uo.id=o.user_id
                WHERE o.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND o.status='completed'
            ) feed
            ORDER BY event_at DESC
            LIMIT 30
        ");
    } catch (Throwable $e) {
        $data['activity'] = [];
    }
}
if ($section === 'ads') {
    $status_f = sanitize($_GET['status'] ?? 'all');
    $search_f = sanitize($_GET['q'] ?? '');
    $date_from = sanitize($_GET['date_from'] ?? '');
    $date_to   = sanitize($_GET['date_to'] ?? '');
    // Validare format date (YYYY-MM-DD) — anti-injection
    if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
    if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

    $w = "1=1";
    $params = [];
    if ($status_f !== 'all') {
        $w .= " AND a.status = ?";
        $params[] = $status_f;
    }
    if ($search_f) {
        $w .= " AND (a.title LIKE ? OR a.id = ?)";
        $params[] = '%' . $search_f . '%';
        $params[] = (int)$search_f;
    }
    if ($date_from) { $w .= " AND DATE(a.created_at) >= ?"; $params[] = $date_from; }
    if ($date_to)   { $w .= " AND DATE(a.created_at) <= ?"; $params[] = $date_to;   }

    // === CSV EXPORT — early return inainte de render HTML ===
    if (($_GET['export'] ?? '') === 'csv') {
        $rows = DB::fetchAll(
            "SELECT a.id, a.title, a.price, a.currency, a.status, a.is_boosted,
                    a.views_count, a.city, a.county, a.created_at,
                    u.name AS uname, u.email AS uemail, c.name AS cname
             FROM ads a
             JOIN users u ON u.id=a.user_id
             JOIN categories c ON c.id=a.category_id
             WHERE $w
             ORDER BY a.created_at DESC
             LIMIT 5000",
            $params
        );

        admin_log('ads_export_csv', 'ads', 0, "rows=" . count($rows) . ", filters: status={$status_f}, q={$search_f}, from={$date_from}, to={$date_to}");

        $filename = 'veedo-anunturi-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        // BOM UTF-8 pentru Excel
        fwrite($out, "\xEF\xBB\xBF");
        // Header
        fputcsv($out, ['ID','Titlu','Utilizator','Email','Categorie','Preț','Monedă','Status','Promovat','Vizualizări','Oraș','Județ','Dată creare'], ',');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['title'], $r['uname'], $r['uemail'], $r['cname'],
                $r['price'], $r['currency'], $r['status'],
                $r['is_boosted'] ? 'Da' : 'Nu',
                $r['views_count'], $r['city'], $r['county'], $r['created_at']
            ], ',');
        }
        fclose($out);
        exit;
    }

    $data['ads'] = DB::fetchAll(
        "SELECT a.*, u.name as uname, c.name as cname
         FROM ads a
         JOIN users u ON u.id=a.user_id
         JOIN categories c ON c.id=a.category_id
         WHERE $w
         ORDER BY a.created_at DESC LIMIT 80",
        $params
    );
    $data['counts'] = DB::fetch("SELECT
        COUNT(*) as all_count,
        SUM(status='pending') as pending,
        SUM(status='active') as active,
        SUM(status='rejected') as rejected,
        SUM(status='expired') as expired,
        SUM(status='sold') as sold
        FROM ads");
}
if ($section === 'users') {
    $search_f = sanitize($_GET['q'] ?? '');
    $role_f = sanitize($_GET['role'] ?? '');
    $w = "1=1";
    if ($search_f) $w .= " AND (name LIKE '%" . addslashes($search_f) . "%' OR email LIKE '%" . addslashes($search_f) . "%')";
    if ($role_f) $w .= " AND role='" . addslashes($role_f) . "'";
    $data['users'] = DB::fetchAll("SELECT u.*, (SELECT COUNT(*) FROM ads WHERE user_id=u.id) as ad_count FROM users u WHERE $w ORDER BY u.created_at DESC LIMIT 80");
}
if ($section === 'categories') {
    $data['cats'] = DB::fetchAll("SELECT c.*, (SELECT COUNT(*) FROM ads WHERE category_id=c.id AND status='active') as ad_count FROM categories c ORDER BY c.parent_id, c.sort_order");
    $edit_cat_id = (int)($_GET['edit'] ?? 0);
    $data['edit_cat'] = $edit_cat_id ? DB::fetch("SELECT * FROM categories WHERE id=?", [$edit_cat_id]) : null;
}
if ($section === 'pages') {
    $data['pages'] = DB::fetchAll("SELECT * FROM pages ORDER BY sort_order, id");
    $edit_page_id = (int)($_GET['edit'] ?? 0);
    $data['edit_page'] = $edit_page_id ? DB::fetch("SELECT * FROM pages WHERE id=?", [$edit_page_id]) : null;
}
if ($section === 'settings') {
    $data['settings'] = DB::fetchAll("SELECT * FROM settings ORDER BY setting_group, id");
    $data['groups'] = array_unique(array_column($data['settings'], 'setting_group'));
}
if ($section === 'orders') {
    $data['orders'] = DB::fetchAll("SELECT o.*, u.name as uname, u.email as uemail, a.title as atitle FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN ads a ON a.id=o.ad_id ORDER BY o.created_at DESC LIMIT 80");
    $data['revenue'] = DB::fetch("SELECT COALESCE(SUM(amount),0) as total, COUNT(*) as count FROM orders WHERE status='completed'");
}
if ($section === 'reports') {
    $status_f = sanitize($_GET['status'] ?? 'pending');
    $data['reports'] = DB::fetchAll("
        SELECT r.*, 
               ur.name as reporter_name, ur.email as reporter_email,
               a.title as ad_title, a.slug as ad_slug, a.user_id as ad_owner_id,
               uo.name as ad_owner_name
        FROM reports r 
        JOIN users ur ON ur.id=r.reporter_id 
        LEFT JOIN ads a ON a.id=r.ad_id
        LEFT JOIN users uo ON uo.id=a.user_id
        WHERE r.status=?
        ORDER BY r.created_at DESC
    ", [$status_f]);
}
if ($section === 'log') {
    // Filtre
    $f_action = sanitize($_GET['log_action'] ?? '');
    $f_target = sanitize($_GET['log_target'] ?? '');
    $f_admin  = (int)($_GET['log_admin'] ?? 0);
    $f_from   = sanitize($_GET['date_from'] ?? '');
    $f_to     = sanitize($_GET['date_to'] ?? '');
    if ($f_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = '';
    if ($f_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = '';

    $page = max(1, (int)($_GET['pagina'] ?? 1));
    $per_page = 50;

    $w = "1=1";
    $params = [];
    if ($f_action) { $w .= " AND l.action = ?";       $params[] = $f_action; }
    if ($f_target) { $w .= " AND l.target_type = ?";  $params[] = $f_target; }
    if ($f_admin)  { $w .= " AND l.user_id = ?";      $params[] = $f_admin; }
    if ($f_from)   { $w .= " AND DATE(l.created_at) >= ?"; $params[] = $f_from; }
    if ($f_to)     { $w .= " AND DATE(l.created_at) <= ?"; $params[] = $f_to; }

    // Total pentru paginare
    $data['log_total'] = (int)(DB::fetch("SELECT COUNT(*) AS c FROM admin_log l WHERE $w", $params)['c'] ?? 0);
    $offset = ($page - 1) * $per_page;

    $data['log'] = DB::fetchAll(
        "SELECT l.*, u.name AS uname
         FROM admin_log l LEFT JOIN users u ON u.id=l.user_id
         WHERE $w
         ORDER BY l.created_at DESC
         LIMIT $per_page OFFSET $offset",
        $params
    );

    $data['log_page']      = $page;
    $data['log_per_page']  = $per_page;
    $data['log_filters']   = compact('f_action','f_target','f_admin','f_from','f_to');

    // Dropdown-uri: acțiuni distincte, target types distincte, admini distincti
    $data['log_actions']   = array_column(DB::fetchAll("SELECT DISTINCT action FROM admin_log ORDER BY action ASC"), 'action');
    $data['log_targets']   = array_column(DB::fetchAll("SELECT DISTINCT target_type FROM admin_log WHERE target_type IS NOT NULL AND target_type <> '' ORDER BY target_type ASC"), 'target_type');
    $data['log_admins']    = DB::fetchAll("SELECT DISTINCT u.id, u.name FROM admin_log l JOIN users u ON u.id=l.user_id ORDER BY u.name ASC");
}

// ============== GLOBAL SEARCH ==============
if ($section === 'search') {
    $q = trim(sanitize($_GET['q'] ?? ''));
    $data['search_q']     = $q;
    $data['search_ads']   = [];
    $data['search_users'] = [];
    $data['search_orders']= [];

    if (mb_strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $qid  = ctype_digit($q) ? (int)$q : 0;

        // Ads — by ID, title, slug
        $data['search_ads'] = DB::fetchAll(
            "SELECT a.id, a.title, a.slug, a.status, a.created_at, u.name AS uname, c.name AS cname
             FROM ads a JOIN users u ON u.id=a.user_id JOIN categories c ON c.id=a.category_id
             WHERE a.id=? OR a.title LIKE ? OR a.slug LIKE ?
             ORDER BY a.created_at DESC LIMIT 25",
            [$qid, $like, $like]
        );

        // Users — by ID, email, name, phone
        $data['search_users'] = DB::fetchAll(
            "SELECT id, name, email, phone, role, is_active, created_at,
                    (SELECT COUNT(*) FROM ads WHERE user_id=u.id) AS ad_count
             FROM users u
             WHERE id=? OR email LIKE ? OR name LIKE ? OR phone LIKE ?
             ORDER BY created_at DESC LIMIT 25",
            [$qid, $like, $like, $like]
        );

        // Orders — by ID, payment_id, transaction_id
        try {
            $data['search_orders'] = DB::fetchAll(
                "SELECT o.*, u.name AS uname, u.email AS uemail
                 FROM orders o LEFT JOIN users u ON u.id=o.user_id
                 WHERE o.id=? OR o.payment_id LIKE ? OR o.transaction_id LIKE ?
                 ORDER BY o.created_at DESC LIMIT 25",
                [$qid, $like, $like]
            );
        } catch (Throwable $e) { /* orders or columns may differ */ }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.admin-layout { display: grid; grid-template-columns: 220px 1fr; min-height: calc(100vh - 57px); }
.admin-sidebar { background: var(--surface); border-right: 1px solid var(--border); padding: 0; position: sticky; top: 57px; height: calc(100vh - 57px); overflow-y: auto; }
.admin-logo { padding: 16px 20px; font-family: var(--font-display); font-size: 1rem; font-weight: 700; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; color: var(--text-3); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
.admin-main { padding: 24px; background: var(--bg-2); min-height: 100%; }
.admin-page-title { font-size: 1.4rem; font-weight: 700; margin-bottom: 24px; color: var(--text); }
@media (max-width: 1024px) { .admin-layout { grid-template-columns: 1fr; } .admin-sidebar { display: none; } }

/* ============== DASHBOARD 2.0 ============== */
/* Alerte (banner urgente) */
.dash-alerts { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
.dash-alert { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; text-decoration: none; font-weight: 600; transition: transform 0.15s; }
.dash-alert:hover { transform: translateX(2px); }
.dash-alert-danger  { background: rgba(239, 68, 68, 0.12); color: #b91c1c; border: 1px solid rgba(239, 68, 68, 0.3); }
.dash-alert-warning { background: rgba(245, 158, 11, 0.12); color: #92400e; border: 1px solid rgba(245, 158, 11, 0.3); }
.dash-alert-icon { font-size: 20px; }
.dash-alert-text { flex: 1; font-size: 14px; }
.dash-alert-arrow { font-size: 18px; opacity: 0.6; }

/* Trenduri în KPI cards */
.trend { font-size: 12px; font-weight: 600; }
.trend-up   { color: #16a34a; }
.trend-down { color: #dc2626; }
.trend-flat { color: var(--text-3); }

/* Grid 2 coloane pentru top categorii + top anunțuri */
.dash-side-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
@media (max-width: 900px) { .dash-side-grid { grid-template-columns: 1fr; } }

/* Liste compacte cu ranking */
.dash-list { display: flex; flex-direction: column; }
.dash-list-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; text-decoration: none; color: var(--text); font-size: 14px; transition: background 0.15s; }
.dash-list-row:hover { background: var(--bg-3); }
.dash-list-row + .dash-list-row { border-top: 1px solid var(--border); }
.dash-rank { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--bg-3); color: var(--text-3); font-weight: 800; font-size: 12px; flex-shrink: 0; }
.dash-list-name { flex: 1; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-list-meta { font-size: 12px; color: var(--text-3); white-space: nowrap; }
.dash-list-meta strong { color: var(--text); font-weight: 700; }

/* ============== ADS — Filter Bar + Bulk Actions ============== */
.ads-header-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.ads-filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; padding: 12px; background: var(--bg-2); border: 1px solid var(--border); border-radius: 12px; }
.ads-filter-bar input.form-control { padding: 8px 12px; font-size: 14px; }
.ads-filter-bar .date-range { display: flex; align-items: center; gap: 6px; }
.ads-filter-bar .date-range input[type="date"] { padding: 7px 10px; font-size: 13px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); }
.ads-filter-bar .date-presets { display: flex; gap: 4px; }
.btn-xs { padding: 4px 10px !important; font-size: 12px !important; }

.bulk-bar {
    position: fixed; bottom: 16px; left: 50%; transform: translateX(-50%);
    z-index: 1000;
    display: flex; align-items: center; gap: 16px;
    padding: 12px 20px;
    background: var(--text); color: var(--bg);
    border-radius: 14px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.3);
    max-width: 96vw;
    flex-wrap: wrap;
}
.bulk-bar-info { display: flex; align-items: center; gap: 8px; font-size: 14px; }
.bulk-bar-count { display: inline-flex; align-items: center; justify-content: center;
    background: var(--brand); color: #fff; font-weight: 800;
    min-width: 28px; height: 28px; padding: 0 10px; border-radius: 14px; font-size: 13px;
}
.bulk-bar-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.bulk-check { width: 16px; height: 16px; cursor: pointer; }
#selectAllChk { width: 16px; height: 16px; cursor: pointer; }
@media (max-width:600px) {
    .bulk-bar { left: 8px; right: 8px; bottom: 12px; transform: none; flex-direction: column; align-items: stretch; }
    .bulk-bar-actions { justify-content: center; }
}

/* ============== ADMIN SEARCH BAR (sidebar) ============== */
.admin-search-bar { padding: 12px 16px; border-bottom: 1px solid var(--border); }
.admin-search-bar input {
    width: 100%; padding: 8px 12px; font-size: 13px;
    background: var(--bg-2); border: 1px solid var(--border);
    border-radius: 8px; color: var(--text);
    transition: border-color 0.15s;
}
.admin-search-bar input:focus { outline: none; border-color: var(--brand); }
.admin-search-bar input::placeholder { color: var(--text-3); font-size: 12px; }

/* ============== ACTIVITY FEED ============== */
.activity-feed { max-height: 480px; overflow-y: auto; padding: 0 8px; }
.feed-row {
    display: flex; gap: 12px; padding: 12px 8px;
    text-decoration: none; color: var(--text);
    border-bottom: 1px solid var(--border);
    transition: background 0.15s;
}
.feed-row:hover { background: var(--bg-3); }
.feed-row:last-child { border-bottom: none; }
.feed-icon { font-size: 22px; line-height: 1; flex-shrink: 0; width: 32px; text-align: center; padding-top: 2px; }
.feed-body { flex: 1; min-width: 0; }
.feed-line { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
.feed-type { color: var(--text-3); font-weight: 700; }
.feed-extra { background: var(--bg-3); color: var(--text-2); padding: 1px 6px; border-radius: 6px; font-size: 10px; }
.feed-title { font-weight: 600; font-size: 14px; line-height: 1.3; margin-bottom: 4px; word-break: break-word; }
.feed-meta { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-3); gap: 8px; }
.feed-time { white-space: nowrap; }

/* ============== LOG / AUDIT TRAIL ============== */
.log-filter-bar { padding: 14px; margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.log-filter-bar select, .log-filter-bar input[type=date] { padding: 7px 10px; font-size: 13px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); min-width: 120px; }
.log-filter-bar .form-control { padding: 7px 10px; font-size: 13px; }
.log-total-badge { margin-left: auto; font-size: 13px; color: var(--text-3); padding: 4px 12px; background: var(--bg-3); border-radius: 8px; font-weight: 600; }

.log-action-code { font-size: 12px; background: var(--bg-3); padding: 3px 8px; border-radius: 6px; font-weight: 600; color: var(--text-2); }

.log-diff-details summary { cursor: pointer; color: var(--brand); font-weight: 600; user-select: none; outline: none; }
.log-diff-details summary:hover { text-decoration: underline; }
.log-diff-details[open] summary { margin-bottom: 8px; }

.log-diff { width: 100%; border-collapse: collapse; font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace; font-size: 12px; background: var(--bg-2); border-radius: 8px; overflow: hidden; }
.log-diff tr { border-bottom: 1px solid var(--border); }
.log-diff tr:last-child { border-bottom: none; }
.log-diff td { padding: 6px 10px; vertical-align: top; }
.log-diff .diff-key { font-weight: 700; color: var(--text); white-space: nowrap; background: var(--bg-3); }
.log-diff .diff-old { background: rgba(239, 68, 68, 0.10); color: #991b1b; text-decoration: line-through; max-width: 240px; word-break: break-all; }
.log-diff .diff-new { background: rgba(16, 185, 129, 0.10); color: #047857; font-weight: 600; max-width: 240px; word-break: break-all; }
.log-diff .diff-arrow { color: var(--text-3); padding: 6px 4px; }

.log-pagination { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 16px; border-top: 1px solid var(--border); }
</style>

<div class="admin-layout">
  <!-- SIDEBAR -->
  <aside class="admin-sidebar">
    <div class="admin-logo">⚡ Admin Panel</div>
    <form method="GET" action="/admin" class="admin-search-bar">
      <input type="hidden" name="s" value="search">
      <input type="search" name="q" placeholder="🔍 Caută anunț, user, email..." value="<?= h($_GET['q'] ?? '') ?>">
    </form>
    <div class="admin-nav-section">Principal</div>
    <a href="/admin?s=dashboard" class="admin-nav-item <?= $section==='dashboard'?'active':'' ?>">📊 Dashboard</a>
    <a href="/admin?s=ads" class="admin-nav-item <?= $section==='ads'?'active':'' ?>">
      📋 Anunțuri
      <?php $p=(int)DB::fetch("SELECT COUNT(*) c FROM ads WHERE status='pending'")['c']; if($p>0): ?>
        <span class="badge badge-danger" style="margin-left:auto;padding:1px 8px;"><?= $p ?></span>
      <?php endif; ?>
    </a>
    <a href="/admin?s=users" class="admin-nav-item <?= $section==='users'?'active':'' ?>">👤 Utilizatori</a>
    <a href="/admin?s=orders" class="admin-nav-item <?= $section==='orders'?'active':'' ?>">💳 Plăți</a>
    <a href="/admin?s=reports" class="admin-nav-item <?= $section==='reports'?'active':'' ?>">
      🚨 Rapoarte
      <?php $r=(int)DB::fetch("SELECT COUNT(*) c FROM reports WHERE status='pending'")['c']; if($r>0): ?>
        <span class="badge badge-danger" style="margin-left:auto;padding:1px 8px;"><?= $r ?></span>
      <?php endif; ?>
    </a>
    <div class="admin-nav-section">Conținut</div>
    <a href="/admin?s=categories" class="admin-nav-item <?= $section==='categories'?'active':'' ?>">🏷️ Categorii</a>
    <a href="/admin?s=pages" class="admin-nav-item <?= $section==='pages'?'active':'' ?>">📄 Pagini</a>
    <div class="admin-nav-section">Configurare</div>
    <a href="/admin?s=settings" class="admin-nav-item <?= $section==='settings'?'active':'' ?>">⚙️ Setări</a>
    <a href="/admin/curatare-uploads.php" class="admin-nav-item">🧹 Curățare uploads</a>
    <a href="/admin?s=log" class="admin-nav-item <?= $section==='log'?'active':'' ?>">🕐 Jurnal</a>
    <div style="padding:16px 20px;margin-top:auto;border-top:1px solid var(--border);margin-top:20px;">
      <a href="/" target="_blank" class="btn btn-secondary btn-sm" style="width:100%;justify-content:center;">👁 Vezi site</a>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="admin-main">
    <?php $f=get_flash('success'); if($f): ?><div class="alert alert-success" data-auto-dismiss><?= h($f) ?></div><?php endif; ?>
    <?php $f=get_flash('error');   if($f): ?><div class="alert alert-error"   data-auto-dismiss><?= h($f) ?></div><?php endif; ?>

<?php if ($section === 'dashboard'): $s=$data['stats']; $y=$data['yesterday'] ?? []; ?>
    <div class="admin-page-title">Dashboard</div>

    <?php
    // ── ALERT BANNER ── (apare numai dacă există ceva urgent)
    $alerts = [];
    if (($s['reports_pending'] ?? 0) > 0) {
        $alerts[] = ['icon'=>'⚠️','text'=>$s['reports_pending'].' '.($s['reports_pending']==1?'raport de moderat':'rapoarte de moderat'),'link'=>'/admin?s=reports&status=pending','tone'=>'danger'];
    }
    if (($s['ads_pending'] ?? 0) > 5) {
        $alerts[] = ['icon'=>'⏳','text'=>$s['ads_pending'].' anunțuri în așteptare (>5)','link'=>'/admin?s=ads&status=pending','tone'=>'warning'];
    }
    if (setting_bool('maintenance_mode')) {
        $alerts[] = ['icon'=>'🔧','text'=>'Modul mentenanță e ACTIV — vizitatorii NU pot accesa site-ul','link'=>'/admin?s=settings&tab=general','tone'=>'danger'];
    }
    ?>
    <?php if ($alerts): ?>
    <div class="dash-alerts">
      <?php foreach ($alerts as $al): ?>
        <a href="<?= h($al['link']) ?>" class="dash-alert dash-alert-<?= h($al['tone']) ?>">
          <span class="dash-alert-icon"><?= $al['icon'] ?></span>
          <span class="dash-alert-text"><?= h($al['text']) ?></span>
          <span class="dash-alert-arrow">→</span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // Helper inline: generează badge de trend ↑/↓/→
    $tr = function($cur, $prev): string {
        $cur = (int)$cur; $prev = (int)$prev;
        if ($prev === 0 && $cur === 0) return '<span class="trend trend-flat">→ stabil</span>';
        if ($prev === 0)               return '<span class="trend trend-up">↑ '.$cur.' nou</span>';
        $delta = $cur - $prev;
        $pct   = round(abs($delta) / $prev * 100);
        if ($delta > 0)  return '<span class="trend trend-up">↑ +'.$pct.'% vs ieri</span>';
        if ($delta < 0)  return '<span class="trend trend-down">↓ -'.$pct.'% vs ieri</span>';
        return '<span class="trend trend-flat">→ la fel ca ieri</span>';
    };
    ?>

    <!-- KPI cards cu trenduri -->
    <div class="stats-grid-admin">
      <div class="stat-card-admin">
        <div class="stat-card-num" style="color:var(--brand)"><?= number_format($s['ads_active']) ?></div>
        <div class="stat-card-lbl">Anunțuri active</div>
        <div class="stat-card-trend trend-up">+<?= $s['ads_today'] ?> azi</div>
      </div>
      <div class="stat-card-admin">
        <div class="stat-card-num" style="color:var(--warning)"><?= number_format($s['ads_pending']) ?></div>
        <div class="stat-card-lbl">În așteptare</div>
        <?php if (($s['ads_pending']??0) > 0): ?>
          <div class="stat-card-trend"><a href="/admin?s=ads&status=pending" style="color:var(--warning)">Moderează →</a></div>
        <?php endif; ?>
      </div>
      <div class="stat-card-admin">
        <div class="stat-card-num" style="color:var(--info)"><?= number_format($s['users_active']) ?></div>
        <div class="stat-card-lbl">Utilizatori</div>
        <div class="stat-card-trend"><?= $tr($s['users_today'], $y['users_yesterday'] ?? 0) ?></div>
      </div>
      <div class="stat-card-admin">
        <div class="stat-card-num" style="color:var(--success)"><?= number_format($y['revenue_today'] ?? 0,0,',','.') ?> RON</div>
        <div class="stat-card-lbl">Venituri azi</div>
        <div class="stat-card-trend"><?= $tr($y['revenue_today']??0, $y['revenue_yesterday']??0) ?></div>
      </div>
      <div class="stat-card-admin">
        <div class="stat-card-num" style="color:var(--brand2)"><?= number_format($s['total_views']) ?></div>
        <div class="stat-card-lbl">Vizualizări totale</div>
        <div class="stat-card-trend"><?= number_format($s['messages_today']) ?> mesaje azi</div>
      </div>
      <div class="stat-card-admin">
        <div class="stat-card-num" style="color:var(--success)"><?= number_format($s['revenue'],0,',','.') ?> RON</div>
        <div class="stat-card-lbl">Venituri totale (cumulat)</div>
        <div class="stat-card-trend trend-flat"><?= $s['orders_count'] ?> plăți</div>
      </div>
    </div>

    <!-- Grafic activitate -->
    <div class="admin-card" style="margin-bottom:20px;">
        <div class="admin-card-header">
            <h2 class="admin-card-title">📈 Activitate (14 zile)</h2>
            <div style="display:flex;gap:12px;font-size:12px;">
                <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#E8380D;display:inline-block;"></span>Anunțuri noi</span>
                <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#3B82F6;display:inline-block;"></span>Utilizatori noi</span>
            </div>
        </div>
        <canvas id="activity-chart" height="80"></canvas>
    </div>

    <!-- Grid: 2 card-uri laterale (Top categorii + Top vizionate) -->
    <div class="dash-side-grid">
        <div class="admin-card">
            <div class="admin-card-header">
                <h2 class="admin-card-title">🏷️ Top categorii</h2>
                <a href="/admin?s=categories" class="btn btn-secondary btn-sm">Vezi toate</a>
            </div>
            <?php if ($data['top_categories']): ?>
            <div class="dash-list">
                <?php foreach ($data['top_categories'] as $i => $tc): ?>
                <a href="/admin?s=ads&q=<?= urlencode($tc['name']) ?>" class="dash-list-row">
                    <span class="dash-rank">#<?= $i+1 ?></span>
                    <span class="dash-list-name"><?= $tc['icon'] ?> <?= h($tc['name']) ?></span>
                    <span class="dash-list-meta"><?= number_format($tc['ads_count']) ?> anunțuri · <?= number_format($tc['total_views']) ?> views</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--text-3);padding:24px;text-align:center;">Nicio categorie cu anunțuri active încă.</p>
            <?php endif; ?>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <h2 class="admin-card-title">👁 Top anunțuri (după vizualizări)</h2>
                <a href="/admin?s=ads" class="btn btn-secondary btn-sm">Toate</a>
            </div>
            <?php if ($data['top_ads']): ?>
            <div class="dash-list">
                <?php foreach ($data['top_ads'] as $i => $ta): ?>
                <a href="/anunt/<?= h($ta['slug']) ?>" target="_blank" class="dash-list-row">
                    <span class="dash-rank">#<?= $i+1 ?></span>
                    <span class="dash-list-name" title="<?= h($ta['title']) ?>"><?= h(mb_substr($ta['title'],0,40)) ?><?= mb_strlen($ta['title'])>40?'…':'' ?></span>
                    <span class="dash-list-meta"><strong><?= number_format($ta['views_count']) ?></strong> views</span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--text-3);padding:24px;text-align:center;">Niciun anunț cu vizualizări încă.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ACTIVITY FEED — ce s-a întâmplat în ultimele 7 zile -->
    <?php if (!empty($data['activity'])): ?>
    <div class="admin-card">
        <div class="admin-card-header">
            <h2 class="admin-card-title">⚡ Activitate platformă (ultimele 7 zile)</h2>
            <span style="color:var(--text-3);font-size:13px;"><?= count($data['activity']) ?> evenimente</span>
        </div>
        <div class="activity-feed">
            <?php
            $icons = [
                'ad_posted'       => '🆕',
                'user_registered' => '👤',
                'report'          => '⚠️',
                'order_completed' => '💰',
            ];
            $labels = [
                'ad_posted'       => 'Anunț nou',
                'user_registered' => 'Utilizator nou',
                'report'          => 'Raport',
                'order_completed' => 'Plată',
            ];
            foreach ($data['activity'] as $ev):
                $ic  = $icons[$ev['event_type']]  ?? '•';
                $lbl = $labels[$ev['event_type']] ?? $ev['event_type'];
            ?>
            <a href="<?= h($ev['link']) ?>" class="feed-row" target="_blank">
                <span class="feed-icon"><?= $ic ?></span>
                <div class="feed-body">
                    <div class="feed-line">
                        <span class="feed-type"><?= h($lbl) ?></span>
                        <?php if ($ev['extra']): ?><span class="feed-extra"><?= h($ev['extra']) ?></span><?php endif; ?>
                    </div>
                    <div class="feed-title"><?= h($ev['title']) ?></div>
                    <div class="feed-meta">
                        <span><?= h($ev['actor']) ?></span>
                        <span class="feed-time"><?= time_ago($ev['event_at']) ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($data['pending_ads']): ?>
    <div class="admin-card">
      <div class="admin-card-header"><h2 class="admin-card-title">⏳ Anunțuri în așteptare</h2><a href="/admin?s=ads&status=pending" class="btn btn-secondary btn-sm">Vezi toate</a></div>
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>Titlu</th><th>Utilizator</th><th>Categorie</th><th>Data</th><th>Acțiuni</th></tr></thead>
        <tbody>
        <?php foreach ($data['pending_ads'] as $a): ?>
        <tr>
          <td><a href="/anunt/<?= h($a['slug']) ?>" target="_blank" style="color:var(--brand)"><?= h(mb_substr($a['title'],0,55)) ?></a></td>
          <td><?= h($a['uname']) ?></td><td><?= h($a['cname']) ?></td>
          <td><?= time_ago($a['created_at']) ?></td>
          <td>
            <form method="POST" style="display:inline-flex;gap:6px;">
              <?= csrf_field() ?><input type="hidden" name="ad_id" value="<?= $a['id'] ?>">
              <button name="action" value="approve" class="btn btn-success btn-sm">✓ Aprobă</button>
              <button name="action" value="reject" class="btn btn-danger btn-sm" onclick="var r=prompt('Motiv respingere:');if(r){this.form.querySelector('[name=reason]').value=r;}else{return false;}">✗ Respinge</button>
              <input type="hidden" name="reason" value="">
            </form>
            <a href="/anunt/<?= h($a['slug']) ?>/editeaza" class="btn btn-secondary btn-sm">✏️ Editează</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <div class="admin-card">
      <div class="admin-card-header"><h2 class="admin-card-title">📋 Anunțuri recente</h2><a href="/admin?s=ads" class="btn btn-secondary btn-sm">Toate</a></div>
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>Titlu</th><th>User</th><th>Status</th><th>Vizualizări</th><th>Data</th></tr></thead>
        <tbody>
        <?php foreach ($data['recent_ads'] as $a): ?>
        <tr>
          <td><a href="/anunt/<?= h($a['slug']) ?>" target="_blank" style="color:var(--brand)"><?= h(mb_substr($a['title'],0,50)) ?></a></td>
          <td><?= h($a['uname']) ?></td>
          <td><span class="badge badge-<?= ['active'=>'success','pending'=>'warning','rejected'=>'danger','expired'=>'gray','sold'=>'info'][$a['status']]??'gray' ?>"><?= $a['status'] ?></span></td>
          <td><?= number_format($a['views_count']) ?></td>
          <td><?= time_ago($a['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

<?php elseif ($section === 'ads'):
    $sf = $_GET['status']??'all';
    $sq = $_GET['q']??'';
    $df = $_GET['date_from'] ?? '';
    $dt = $_GET['date_to']   ?? '';
    // Helper: construiește URL cu filtre curente păstrate
    $current_filters_qs = http_build_query(array_filter([
        's'=>'ads', 'status'=>$sf, 'q'=>$sq, 'date_from'=>$df, 'date_to'=>$dt
    ]));
?>
    <div class="ads-header-bar">
      <div class="admin-page-title" style="margin:0">Anunțuri</div>
      <a href="?<?= $current_filters_qs ?>&export=csv" class="btn btn-secondary btn-sm" title="Descarcă CSV cu filtrele curente">📥 Export CSV</a>
    </div>

    <!-- Filtre combinate: search + date range -->
    <form method="GET" class="ads-filter-bar" id="adsFilterForm">
      <input type="hidden" name="s" value="ads">
      <input type="hidden" name="status" value="<?= h($sf) ?>">
      <input type="search" name="q" class="form-control" placeholder="🔍 Caută titlu sau ID..." value="<?= h($sq) ?>" style="flex:1;min-width:200px;">
      <div class="date-range">
        <input type="date" name="date_from" class="form-control" value="<?= h($df) ?>" title="De la data">
        <span style="color:var(--text-3);font-size:13px;">→</span>
        <input type="date" name="date_to" class="form-control" value="<?= h($dt) ?>" title="Până la data">
      </div>
      <div class="date-presets">
        <button type="button" onclick="setDateRange('today')" class="btn btn-secondary btn-xs">Azi</button>
        <button type="button" onclick="setDateRange('week')"  class="btn btn-secondary btn-xs">7 zile</button>
        <button type="button" onclick="setDateRange('month')" class="btn btn-secondary btn-xs">30 zile</button>
        <button type="button" onclick="setDateRange('clear')" class="btn btn-secondary btn-xs">×</button>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filtrează</button>
    </form>

    <!-- Tab-uri status — păstrează filtrele de căutare/date -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
      <?php
      $sc = $data['counts'];
      $tabs2 = ['all'=>'Toate ('.$sc['all_count'].')','pending'=>'Așteptare ('.$sc['pending'].')','active'=>'Active ('.$sc['active'].')','rejected'=>'Respinse ('.$sc['rejected'].')','expired'=>'Expirate ('.$sc['expired'].')','sold'=>'Vândute ('.$sc['sold'].')'];
      foreach ($tabs2 as $k=>$lbl):
          $qs = http_build_query(array_filter(['s'=>'ads','status'=>$k,'q'=>$sq,'date_from'=>$df,'date_to'=>$dt]));
      ?>
        <a href="?<?= $qs ?>" class="btn <?= $sf===$k?'btn-primary':'btn-secondary' ?> btn-sm"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>

    <div class="admin-card">
      <div class="table-wrap"><table class="vtable">
        <thead><tr>
          <th style="width:36px;"><input type="checkbox" id="selectAllChk" onclick="toggleAll(this)"></th>
          <th>ID</th><th>Titlu</th><th>Utilizator</th><th>Categorie</th><th>Preț</th><th>Status</th><th>Boost</th><th>Vizualizări</th><th>Data</th><th>Acțiuni</th>
        </tr></thead>
        <tbody>
        <?php if (!$data['ads']): ?>
        <tr><td colspan="11" style="text-align:center;padding:32px;color:var(--text-3);">Niciun anunț care să corespundă filtrelor.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['ads'] as $a): ?>
        <tr>
          <td><input type="checkbox" class="bulk-check" value="<?= $a['id'] ?>" onclick="updateBulkBar()"></td>
          <td style="color:var(--text-3)">#<?= $a['id'] ?></td>
          <td><a href="/anunt/<?= h($a['slug']) ?>" target="_blank" style="color:var(--brand)"><?= h(mb_substr($a['title'],0,40)) ?></a></td>
          <td><?= h($a['uname']) ?></td>
          <td><?= h($a['cname']) ?></td>
          <td><?= $a['price'] ? number_format($a['price'],0,',','.') . ' ' . $a['currency'] : '-' ?></td>
          <td><span class="badge badge-<?= ['active'=>'success','pending'=>'warning','rejected'=>'danger','expired'=>'gray','sold'=>'info'][$a['status']]??'gray' ?>"><?= $a['status'] ?></span></td>
          <td><?= $a['is_boosted'] ? '<span class="badge" style="background:#FEF3C7;color:#92400E;">⭐ '.date('d.m', strtotime($a['boosted_until'])).'</span>' : '-' ?></td>
          <td><?= number_format($a['views_count']) ?></td>
          <td><?= time_ago($a['created_at']) ?></td>
          <td>
            <form method="POST" style="display:inline-flex;gap:4px;flex-wrap:wrap;">
              <?= csrf_field() ?><input type="hidden" name="ad_id" value="<?= $a['id'] ?>">
              <?php if ($a['status']==='pending'): ?>
                <button name="action" value="approve" class="btn btn-success btn-sm">✓</button>
              <?php endif; ?>
              <?php if (!$a['is_boosted']): ?>
                <button name="action" value="boost" class="btn btn-sm" style="background:var(--brand2);color:#fff;" onclick="document.querySelector('#boost-days-<?= $a['id'] ?>').style.display='inline-block'">⭐</button>
                <select name="boost_days" id="boost-days-<?= $a['id'] ?>" style="display:none;font-size:12px;padding:4px;border:1px solid var(--border);border-radius:6px;">
                  <option value="7">7 zile</option><option value="14">14 zile</option><option value="30">30 zile</option>
                </select>
              <?php else: ?>
                <button name="action" value="unboost" class="btn btn-sm btn-secondary">✗⭐</button>
              <?php endif; ?>
              <button name="action" value="delete" class="btn btn-danger btn-sm" data-confirm="Ștergi anunțul #<?= $a['id'] ?> definitiv?">🗑</button>
            </form>
            <a href="/anunt/<?= h($a['slug']) ?>/editeaza" class="btn btn-secondary btn-sm" title="Editează anunț">✏️</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <!-- Bara de acțiuni bulk (sticky bottom) — apare doar când există selecție -->
    <div class="bulk-bar" id="bulkBar" style="display:none">
      <div class="bulk-bar-info">
        <span class="bulk-bar-count" id="bulkCount">0</span>
        <span>anunțuri selectate</span>
      </div>
      <div class="bulk-bar-actions">
        <button type="button" class="btn btn-success btn-sm" onclick="bulkAction('approve')">✓ Aprobă toate</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="bulkAction('reject')">✗ Respinge toate</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="bulkAction('delete')">🗑 Șterge</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="deselectAll()">Anulează</button>
      </div>
    </div>

<?php elseif ($section === 'users'): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
      <div class="admin-page-title" style="margin:0">Utilizatori</div>
      <form method="GET" style="display:flex;gap:8px;">
        <input type="hidden" name="s" value="users">
        <input type="search" name="q" class="form-control" placeholder="Caută..." value="<?= h($_GET['q']??'') ?>" style="width:200px;">
        <select name="role" class="form-control" style="width:130px;">
          <option value="">Toate rolurile</option>
          <option value="user" <?= ($_GET['role']??'')==='user'?'selected':'' ?>>User</option>
          <option value="business" <?= ($_GET['role']??'')==='business'?'selected':'' ?>>Business</option>
          <option value="moderator" <?= ($_GET['role']??'')==='moderator'?'selected':'' ?>>Moderator</option>
          <option value="admin" <?= ($_GET['role']??'')==='admin'?'selected':'' ?>>Admin</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrează</button>
      </form>
    </div>
    <div class="admin-card">
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>ID</th><th>Nume</th><th>Email</th><th>Rol</th><th>Business</th><th>Anunțuri</th><th>Rating</th><th>Status</th><th>Înregistrat</th><th>Acțiuni</th></tr></thead>
        <tbody>
        <?php foreach ($data['users'] as $u2): ?>
        <tr>
          <td style="color:var(--text-3)">#<?= $u2['id'] ?></td>
          <td><a href="/utilizator/<?= $u2['id'] ?>" target="_blank"><?= h($u2['name']) ?></a></td>
          <td style="font-size:12px;color:var(--text-3)"><?= h($u2['email']) ?></td>
          <td><span class="badge badge-<?= ['admin'=>'danger','moderator'=>'warning','business'=>'info','user'=>'gray'][$u2['role']]??'gray' ?>"><?= $u2['role'] ?></span></td>
          <td><?= $u2['is_verified_business'] ? '✅' : '-' ?></td>
          <td><?= $u2['ad_count'] ?></td>
          <td><?= $u2['rating_count'] > 0 ? number_format($u2['rating_avg'],1).'★ ('.$u2['rating_count'].')' : '-' ?></td>
          <td><?= $u2['is_active'] ? '<span class="badge badge-success">Activ</span>' : '<span class="badge badge-danger">Blocat</span>' ?></td>
          <td style="font-size:12px"><?= date('d.m.Y', strtotime($u2['created_at'])) ?></td>
          <td>
            <?php if ($u2['role'] !== 'admin'): ?>
            <form method="POST" style="display:inline-flex;gap:4px;flex-wrap:wrap;">
              <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $u2['id'] ?>">
              <?php if (!$u2['is_verified_business']): ?>
                <button name="action" value="verify_business" class="btn btn-sm" style="background:var(--info);color:#fff;font-size:11px;">✓ Biz</button>
              <?php else: ?>
                <button name="action" value="unverify_business" class="btn btn-secondary btn-sm" style="font-size:11px;">✗ Biz</button>
              <?php endif; ?>
              <?php if ($u2['role'] === 'user'): ?>
                <button name="action" value="make_moderator" class="btn btn-sm" style="background:var(--warning);color:#fff;font-size:11px;">Mod+</button>
              <?php elseif ($u2['role'] === 'moderator'): ?>
                <button name="action" value="remove_moderator" class="btn btn-secondary btn-sm" style="font-size:11px;">Mod-</button>
              <?php endif; ?>
              <?php if ($u2['is_active']): ?>
                <button name="action" value="ban" class="btn btn-danger btn-sm" data-confirm="Blochezi utilizatorul <?= h($u2['name']) ?>?">🚫</button>
              <?php else: ?>
                <button name="action" value="activate" class="btn btn-success btn-sm">✓</button>
              <?php endif; ?>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

<?php elseif ($section === 'reports'): $rsf = $_GET['status']??'pending'; ?>
    <div class="admin-page-title">Rapoarte abuse</div>
    <div style="display:flex;gap:6px;margin-bottom:16px;">
      <a href="/admin?s=reports&status=pending" class="btn <?= $rsf==='pending'?'btn-primary':'btn-secondary' ?> btn-sm">În așteptare</a>
      <a href="/admin?s=reports&status=resolved" class="btn <?= $rsf==='resolved'?'btn-primary':'btn-secondary' ?> btn-sm">Rezolvate</a>
      <a href="/admin?s=reports&status=reviewed" class="btn <?= $rsf==='reviewed'?'btn-primary':'btn-secondary' ?> btn-sm">Ignorate</a>
    </div>
    <?php if (empty($data['reports'])): ?>
      <div class="admin-card" style="text-align:center;padding:48px;color:var(--text-3);">
        <div style="font-size:3rem;margin-bottom:12px;">✅</div>
        <h3>Niciun raport <?= $rsf === 'pending' ? 'în așteptare' : ($rsf === 'resolved' ? 'rezolvat' : 'ignorat') ?></h3>
      </div>
    <?php else: ?>
    <?php foreach ($data['reports'] as $r): ?>
    <div class="admin-card" style="margin-bottom:14px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
          <div style="font-size:13px;margin-bottom:4px;">
            <strong>Raportat de:</strong> <?= h($r['reporter_name']) ?> (<?= h($r['reporter_email']) ?>)
            <span style="color:var(--text-3);margin-left:8px;"><?= time_ago($r['created_at']) ?></span>
          </div>
          <div style="font-size:13px;margin-bottom:4px;">
            <strong>Motiv:</strong> <span class="badge badge-warning"><?= h($r['reason']) ?></span>
            <?php if ($r['ad_title']): ?>
              &nbsp;<strong>Anunț:</strong> <a href="/anunt/<?= h($r['ad_slug'] ?? '') ?>" target="_blank" style="color:var(--brand)"><?= h(mb_substr($r['ad_title'],0,60)) ?></a>
              <?php if ($r['ad_owner_name']): ?>
                &nbsp;<strong>Vânzător:</strong> <a href="/utilizator/<?= $r['ad_owner_id'] ?>" target="_blank"><?= h($r['ad_owner_name']) ?></a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($r['details']): ?>
          <div style="font-size:13px;color:var(--text-2);background:var(--bg-2);padding:8px 12px;border-radius:var(--radius);margin-top:6px;border-left:3px solid var(--warning);">
            <?= h($r['details']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($rsf === 'pending'): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0;">
          <form method="POST" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="report_id" value="<?= $r['id'] ?>">
            <?php if ($r['ad_id']): ?>
              <button name="action" value="delete_ad" class="btn btn-danger btn-sm" data-confirm="Ștergi anunțul raportat?">🗑 Șterge anunț</button>
              <button name="action" value="ban_user" class="btn btn-sm" style="background:var(--danger);color:#fff;" data-confirm="Blochezi utilizatorul?">🚫 Ban user</button>
            <?php endif; ?>
            <button name="action" value="resolve" class="btn btn-success btn-sm">✓ Rezolvat</button>
            <button name="action" value="dismiss" class="btn btn-secondary btn-sm">Ignoră</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

<?php elseif ($section === 'categories'): ?>
    <div class="admin-page-title">Categorii</div>
    <?php if ($data['edit_cat']): $ec = $data['edit_cat']; ?>
    <div class="admin-card">
      <div class="admin-card-header"><h2 class="admin-card-title">✏️ Editează: <?= h($ec['name']) ?></h2><a href="/admin?s=categories" class="btn btn-secondary btn-sm">← Înapoi</a></div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="cat_id" value="<?= $ec['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group"><label class="form-label">Nume *</label><input type="text" name="name" class="form-control" value="<?= h($ec['name']) ?>" required></div>
          <div class="form-group"><label class="form-label">Icon (emoji)</label><input type="text" name="icon" class="form-control" value="<?= h($ec['icon']) ?>"></div>
          <div class="form-group"><label class="form-label">Culoare</label><div style="display:flex;gap:8px;"><input type="color" name="color" value="<?= h($ec['color']) ?>" style="height:40px;width:60px;"> <input type="text" class="form-control" value="<?= h($ec['color']) ?>" style="flex:1" oninput="this.previousElementSibling.value=this.value"></div></div>
          <div class="form-group"><label class="form-label">Ordine afișare</label><input type="number" name="sort_order" class="form-control" value="<?= $ec['sort_order'] ?>"></div>
          <div class="form-group" style="grid-column:1/-1"><label class="form-label">Descriere</label><input type="text" name="description" class="form-control" value="<?= h($ec['description']) ?>"></div>
          <div class="form-group"><label class="form-label">Meta Title (SEO)</label><input type="text" name="meta_title" class="form-control" value="<?= h($ec['meta_title'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Meta Description (SEO)</label><input type="text" name="meta_description" class="form-control" value="<?= h($ec['meta_description'] ?? '') ?>"></div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;"><input type="checkbox" name="is_active" value="1" <?= $ec['is_active']?'checked':'' ?>> Categorie activă</label>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary">💾 Salvează</button>
          <a href="/admin?s=categories" class="btn btn-secondary">Anulează</a>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div class="admin-card" style="margin-bottom:16px;">
      <div class="admin-card-header"><h2 class="admin-card-title">➕ Categorie nouă</h2></div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div style="display:grid;grid-template-columns:1fr 80px 100px 80px auto;gap:10px;align-items:end;">
          <div class="form-group" style="margin:0"><label class="form-label">Nume *</label><input type="text" name="name" class="form-control" required placeholder="Ex: Electrocasnice"></div>
          <div class="form-group" style="margin:0"><label class="form-label">Icon</label><input type="text" name="icon" class="form-control" value="📦"></div>
          <div class="form-group" style="margin:0"><label class="form-label">Culoare</label><input type="color" name="color" value="#888888" style="width:100%;height:40px;"></div>
          <div class="form-group" style="margin:0"><label class="form-label">Ordine</label><input type="number" name="sort_order" class="form-control" value="0"></div>
          <div style="display:flex;align-items:center;gap:8px;"><label><input type="checkbox" name="is_active" value="1" checked></label><button type="submit" class="btn btn-primary">Adaugă</button></div>
        </div>
      </form>
    </div>
    <div class="admin-card">
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>Icon</th><th>Nume</th><th>Anunțuri</th><th>Culoare</th><th>Ordine</th><th>Activ</th><th>Acțiuni</th></tr></thead>
        <tbody>
        <?php foreach ($data['cats'] as $cat): ?>
        <tr>
          <td style="font-size:1.3rem"><?= h($cat['icon']) ?></td>
          <td><strong><?= h($cat['name']) ?></strong><br><small style="color:var(--text-3)">/categorie/<?= h($cat['slug']) ?></small></td>
          <td><?= $cat['ad_count'] ?></td>
          <td><span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:<?= h($cat['color']) ?>;border:1px solid var(--border);vertical-align:middle"></span> <?= h($cat['color']) ?></td>
          <td><?= $cat['sort_order'] ?></td>
          <td><?= $cat['is_active'] ? '✅' : '❌' ?></td>
          <td style="display:flex;gap:6px;">
            <a href="/admin?s=categories&edit=<?= $cat['id'] ?>" class="btn btn-secondary btn-sm">✏️ Editează</a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm="Ștergi categoria '<?= h($cat['name']) ?>'? (<?= $cat['ad_count'] ?> anunțuri)">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

<?php elseif ($section === 'pages'): ?>
    <div class="admin-page-title">Pagini statice</div>
    <?php if ($data['edit_page']): $ep = $data['edit_page']; ?>
    <div class="admin-card">
      <div class="admin-card-header">
        <h2 class="admin-card-title">✏️ Editează: <?= h($ep['title']) ?></h2>
        <div style="display:flex;gap:8px;">
          <a href="/pagina/<?= h($ep['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">👁 Preview</a>
          <a href="/admin?s=pages" class="btn btn-secondary btn-sm">← Înapoi</a>
        </div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="page_id" value="<?= $ep['id'] ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="form-group"><label class="form-label">Titlu *</label><input type="text" name="title" class="form-control" value="<?= h($ep['title']) ?>" required></div>
          <div class="form-group"><label class="form-label">Slug (URL)</label><input type="text" name="slug" class="form-control" value="<?= h($ep['slug']) ?>" placeholder="ex: despre-noi"></div>
          <div class="form-group"><label class="form-label">Meta Title (SEO)</label><input type="text" name="meta_title" class="form-control" value="<?= h($ep['meta_title'] ?? '') ?>" placeholder="Titlu pentru Google (max 60 caractere)"></div>
          <div class="form-group"><label class="form-label">Meta Description (SEO)</label><input type="text" name="meta_description" class="form-control" value="<?= h($ep['meta_description'] ?? '') ?>" placeholder="Descriere pentru Google (max 155 caractere)"></div>
        </div>
        <div class="form-group"><label class="form-label">Conținut (HTML acceptat)</label>
          <textarea name="content" class="form-control" rows="16" style="font-family:monospace;font-size:13px;"><?= h($ep['content']) ?></textarea>
          <div class="form-hint">Poți folosi HTML: &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;strong&gt;, &lt;a href=""&gt; etc.</div>
        </div>
        <div style="display:flex;gap:20px;margin-bottom:16px;">
          <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:14px;"><input type="checkbox" name="is_active" value="1" <?= $ep['is_active']?'checked':'' ?>> Activă</label>
          <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:14px;"><input type="checkbox" name="in_footer" value="1" <?= $ep['in_footer']?'checked':'' ?>> Afișată în footer</label>
          <div class="form-group" style="margin:0;display:flex;align-items:center;gap:8px;"><label class="form-label" style="margin:0">Ordine:</label><input type="number" name="sort_order" class="form-control" value="<?= $ep['sort_order'] ?>" style="width:80px;"></div>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary">💾 Salvează pagina</button>
          <a href="/admin?s=pages" class="btn btn-secondary">Anulează</a>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div class="admin-card" style="margin-bottom:16px;">
      <div class="admin-card-header"><h2 class="admin-card-title">➕ Pagină nouă</h2></div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div class="form-group" style="margin:0"><label class="form-label">Titlu *</label><input type="text" name="title" class="form-control" required placeholder="Ex: Despre noi"></div>
          <div class="form-group" style="margin:0"><label class="form-label">Slug</label><input type="text" name="slug" class="form-control" placeholder="ex: despre-noi (auto dacă e gol)"></div>
        </div>
        <div class="form-group"><label class="form-label">Conținut HTML</label><textarea name="content" class="form-control" rows="5" placeholder="<h2>Titlu</h2><p>Conținut pagină...</p>"></textarea></div>
        <div style="display:flex;gap:12px;align-items:center;">
          <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:14px;"><input type="checkbox" name="is_active" value="1" checked> Activă</label>
          <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:14px;"><input type="checkbox" name="in_footer" value="1" checked> Footer</label>
          <button type="submit" class="btn btn-primary">Crează pagina</button>
        </div>
      </form>
    </div>
    <div class="admin-card">
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>Titlu</th><th>URL</th><th>Meta SEO</th><th>Footer</th><th>Activ</th><th>Modificat</th><th>Acțiuni</th></tr></thead>
        <tbody>
        <?php foreach ($data['pages'] as $p): ?>
        <tr>
          <td><strong><?= h($p['title']) ?></strong></td>
          <td><a href="/pagina/<?= h($p['slug']) ?>" target="_blank" style="font-size:12px;color:var(--text-3)">/pagina/<?= h($p['slug']) ?></a></td>
          <td style="font-size:12px"><?= $p['meta_title'] ? '✅ Da' : '<span style="color:var(--text-3)">— Lipsă</span>' ?></td>
          <td><?= $p['in_footer'] ? '✅' : '-' ?></td>
          <td><?= $p['is_active'] ? '✅' : '❌' ?></td>
          <td style="font-size:12px"><?= time_ago($p['updated_at']) ?></td>
          <td style="display:flex;gap:6px;">
            <a href="/admin?s=pages&edit=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">✏️ Editează</a>
            <a href="/pagina/<?= h($p['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">👁</a>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
              <button class="btn btn-danger btn-sm" data-confirm="Ștergi pagina '<?= h($p['title']) ?>'?">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

<?php elseif ($section === 'orders'): ?>
    <div class="admin-page-title">Plăți & Tranzacții</div>
    <!-- Stats -->
    <div class="stats-grid-admin" style="margin-bottom:20px;">
      <div class="stat-card-admin"><div class="stat-card-num" style="color:var(--success)"><?= number_format($data['revenue']['total'],0,',','.') ?> RON</div><div class="stat-card-lbl">Venituri totale</div></div>
      <div class="stat-card-admin"><div class="stat-card-num"><?= $data['revenue']['count'] ?></div><div class="stat-card-lbl">Plăți finalizate</div></div>
      <?php
        $rev_month = DB::fetch("SELECT COALESCE(SUM(amount),0) as t FROM orders WHERE status='completed' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
        $rev_week  = DB::fetch("SELECT COALESCE(SUM(amount),0) as t FROM orders WHERE status='completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
      ?>
      <div class="stat-card-admin"><div class="stat-card-num" style="color:var(--brand)"><?= number_format($rev_month['t'],0,',','.') ?> RON</div><div class="stat-card-lbl">Luna aceasta</div></div>
      <div class="stat-card-admin"><div class="stat-card-num" style="color:var(--warning)"><?= number_format($rev_week['t'],0,',','.') ?> RON</div><div class="stat-card-lbl">Ultima săptămână</div></div>
    </div>

    <!-- Filtre -->
    <div class="admin-card" style="margin-bottom:16px;">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="s" value="orders">
        <select name="status_f" class="form-control" style="width:auto;" onchange="this.form.submit()">
          <option value="">Toate statusurile</option>
          <?php foreach(['completed'=>'Finalizate','pending'=>'În așteptare','failed'=>'Eșuate','refunded'=>'Restituite'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($_GET['status_f']??'')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <select name="method_f" class="form-control" style="width:auto;" onchange="this.form.submit()">
          <option value="">Toate metodele</option>
          <option value="stripe" <?= ($_GET['method_f']??'')==='stripe'?'selected':'' ?>>Stripe</option>
          <option value="netopia" <?= ($_GET['method_f']??'')==='netopia'?'selected':'' ?>>Netopia</option>
        </select>
        <select name="type_f" class="form-control" style="width:auto;" onchange="this.form.submit()">
          <option value="">Toate tipurile</option>
          <?php foreach(['boost_7'=>'Boost 7 zile','boost_30'=>'Boost 30 zile','business_verify'=>'Business verify'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($_GET['type_f']??'')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <a href="/admin?s=orders" class="btn btn-secondary btn-sm">Resetează</a>
        <a href="/admin?s=orders&export=csv" class="btn btn-secondary btn-sm">⬇ Export CSV</a>
      </form>
    </div>

    <?php
    // Export CSV
    if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tranzactii-'.date('Y-m-d').'.csv"');
        echo "ï»¿"; // BOM UTF-8
        echo "ID,Utilizator,Email,Anunt,Tip,Suma,Metoda,Status,Data
";
        $all = DB::fetchAll("SELECT o.*, u.name as uname, u.email as uemail, a.title as atitle FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN ads a ON a.id=o.ad_id ORDER BY o.created_at DESC");
        foreach ($all as $r) {
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',$v).'"', [
                $r['id'],$r['uname'],$r['uemail'],$r['atitle']??'',$r['type'],$r['amount'].' '.$r['currency'],$r['payment_method'],$r['status'],date('d.m.Y H:i',strtotime($r['created_at']))
            ])) . "
";
        }
        exit;
    }
    
    // Query cu filtre
    $w2 = "WHERE 1=1";
    $p2 = [];
    if (!empty($_GET['status_f'])) { $w2 .= " AND o.status=?"; $p2[] = $_GET['status_f']; }
    if (!empty($_GET['method_f'])) { $w2 .= " AND o.payment_method=?"; $p2[] = $_GET['method_f']; }
    if (!empty($_GET['type_f']))   { $w2 .= " AND o.type=?"; $p2[] = $_GET['type_f']; }
    $orders_filtered = DB::fetchAll("SELECT o.*, u.name as uname, u.email as uemail, a.title as atitle, a.slug as aslug FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN ads a ON a.id=o.ad_id $w2 ORDER BY o.created_at DESC LIMIT 100", $p2);
    ?>

    <div class="admin-card">
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>ID</th><th>Utilizator</th><th>Anunț</th><th>Tip</th><th>Sumă</th><th>Metodă</th><th>Status</th><th>Data</th><th>Acțiuni</th></tr></thead>
        <tbody>
        <?php foreach ($orders_filtered as $o): 
          $type_icons = ['boost_7'=>'⭐','boost_30'=>'🚀','business_verify'=>'🏢','ad_post'=>'📋'];
          $type_labels = ['boost_7'=>'Boost 7z','boost_30'=>'Boost 30z','business_verify'=>'Business','ad_post'=>'Post'];
        ?>
        <tr>
          <td style="font-family:monospace;font-size:12px;">#<?= $o['id'] ?></td>
          <td>
            <a href="/utilizator/<?= $o['user_id'] ?>" style="font-weight:600;font-size:13px;"><?= h($o['uname']) ?></a>
            <br><small style="color:var(--text-3)"><?= h($o['uemail']) ?></small>
          </td>
          <td style="font-size:12px;">
            <?php if ($o['atitle']): ?>
              <a href="/anunt/<?= h($o['aslug']) ?>"><?= h(mb_substr($o['atitle'],0,30)) ?>...</a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span style="font-size:16px;"><?= $type_icons[$o['type']] ?? '💳' ?></span>
            <span class="badge badge-gray" style="font-size:10px;"><?= $type_labels[$o['type']] ?? h($o['type']) ?></span>
          </td>
          <td><strong><?= number_format($o['amount'],2,',','.') ?> RON</strong></td>
          <td style="font-size:12px;"><?= strtoupper($o['payment_method']) ?></td>
          <td><span class="badge badge-<?= ['completed'=>'success','pending'=>'warning','failed'=>'danger','refunded'=>'info'][$o['status']]??'gray' ?>"><?= $o['status'] ?></span></td>
          <td style="font-size:11px;color:var(--text-3);">
            <?= date('d.m.Y', strtotime($o['created_at'])) ?>
            <br><?= date('H:i', strtotime($o['created_at'])) ?>
          </td>
          <td>
            <?php if ($o['status'] === 'pending'): ?>
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="complete_order">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <button class="btn btn-secondary btn-sm" style="font-size:11px;" title="Marchează ca finalizat">✓ Completat</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($orders_filtered)): ?>
          <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-3);">Nicio tranzacție găsită</td></tr>
        <?php endif; ?>
        </tbody>
      </table></div>
    </div>

<?php elseif ($section === 'settings'): ?>
    <div class="admin-page-title">Setări generale</div>
    <div class="tabs" id="settings-tabs">
      <?php foreach ($data['groups'] as $i=>$g): ?>
        <button type="button" class="tab <?= $i===0?'active':'' ?>" data-group="<?= h($g) ?>" onclick="showGroup('<?= h($g) ?>',this)"><?= ucfirst(h($g)) ?></button>
      <?php endforeach; ?>
    </div>
    <div id="settings-save-toast" style="display:none;position:fixed;top:20px;right:20px;z-index:9999;background:#16A34A;color:white;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:500;box-shadow:0 4px 20px rgba(0,0,0,0.15);transition:opacity 0.3s;">✓ Setările au fost salvate!</div>
    <form method="POST" id="settings-form">
      <?= csrf_field() ?>
      <input type="hidden" name="active_tab" id="active_tab_input" value="<?= h($_GET['tab'] ?? $data['groups'][0]) ?>">
      <?php $cg=null;
      foreach ($data['settings'] as $s):
        if ($s['setting_group'] !== $cg):
          if ($cg!==null) echo '</div>';
          $cg=$s['setting_group'];
          echo '<div class="settings-group" id="grp-'.h($cg).'" style="'.($cg!==$data['groups'][0]?'display:none':'').'">';
          if ($cg === 'hero') {
            echo '<div style="margin-bottom:20px;border-radius:12px;overflow:hidden;border:1px solid var(--border);">
              <div id="hero-preview" style="background:#F7F7F7;padding:32px 24px;text-align:center;position:relative;transition:all 0.3s;">
                <div id="hero-overlay-el" style="position:absolute;inset:0;pointer-events:none;"></div>
                <div style="position:relative;z-index:1;">
                  <div id="hero-title-preview" style="font-size:1.4rem;font-weight:800;color:#111;margin-bottom:6px;">'.h(setting('site_tagline','Anunțuri video. Simplu. Rapid.')).'</div>
                  <div id="hero-sub-preview" style="font-size:13px;color:#888;margin-bottom:14px;">Descoperă produse și servicii prin video</div>
                  <div style="display:inline-flex;background:white;border-radius:100px;overflow:hidden;border:1px solid #E5E5E5;max-width:400px;width:100%;">
                    <input type="text" placeholder="Caută orice anunț..." style="flex:1;border:none;padding:10px 16px;font-size:13px;outline:none;background:transparent;" disabled>
                    <div style="background:#E8380D;color:white;padding:10px 18px;font-size:13px;font-weight:600;">Caută</div>
                  </div>
                </div>
              </div>
              <div style="background:var(--bg-2);padding:8px 16px;font-size:12px;color:var(--text-3);text-align:center;">👆 Preview live — se actualizează pe măsură ce modifici setările</div>
            </div>';
          }
        endif; ?>
        <div class="admin-card" style="margin-bottom:12px;">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <label class="form-label" for="s_<?= h($s['setting_key']) ?>" style="margin:0;font-size:14px;font-weight:600;"><?= h($s['label'] ?: $s['setting_key']) ?></label>
            <div style="flex:1;min-width:200px;max-width:500px;">
              <?php if ($s['type']==='textarea'): ?>
                <textarea class="form-control" name="<?= h($s['setting_key']) ?>" id="s_<?= h($s['setting_key']) ?>" rows="3"><?= h($s['setting_value']) ?></textarea>
              <?php elseif ($s['type']==='boolean'): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                  <input type="checkbox" name="<?= h($s['setting_key']) ?>" id="s_<?= h($s['setting_key']) ?>" value="1" <?= $s['setting_value']?'checked':'' ?> style="width:18px;height:18px;">
                  <span style="font-size:14px;"><?= $s['setting_value'] ? '✅ Activat' : '❌ Dezactivat' ?></span>
                </label>
              <?php elseif ($s['type']==='color'): ?>
                <div style="display:flex;gap:8px;align-items:center;">
                  <input type="color" name="<?= h($s['setting_key']) ?>" id="s_<?= h($s['setting_key']) ?>" value="<?= h($s['setting_value']) ?>"
                         data-css-var="<?= h(['site_color_primary'=>'--brand','site_color_secondary'=>'--brand2'][$s['setting_key']]??'') ?>"
                         style="width:50px;height:40px;border:1px solid var(--border);border-radius:6px;cursor:pointer;">
                  <input type="text" class="form-control" style="width:120px;" value="<?= h($s['setting_value']) ?>"
                         oninput="document.getElementById('s_<?= h($s['setting_key']) ?>').value=this.value;document.documentElement.style.setProperty('<?= h(['site_color_primary'=>'--brand','site_color_secondary'=>'--brand2'][$s['setting_key']]??'') ?>',this.value)">
                </div>
              <?php elseif ($s['type']==='select' && $s['setting_key']==='site_theme_default'): ?>
                <select class="form-control" name="<?= h($s['setting_key']) ?>" style="max-width:200px;">
                  <option value="light" <?= $s['setting_value']==='light'?'selected':'' ?>>☀️ Light</option>
                  <option value="dark" <?= $s['setting_value']==='dark'?'selected':'' ?>>🌙 Dark</option>
                  <option value="auto" <?= $s['setting_value']==='auto'?'selected':'' ?>>🔄 Auto (browser)</option>
                </select>
              <?php elseif ($s['type']==='image'): ?>
                <div style="display:flex;gap:8px;align-items:center;">
                  <input type="url" class="form-control" name="<?= h($s['setting_key']) ?>" id="s_<?= h($s['setting_key']) ?>" value="<?= h($s['setting_value']) ?>" placeholder="https://...">
                  <?php if ($s['setting_value']): ?>
                    <img src="<?= h($s['setting_value']) ?>" style="height:36px;border-radius:4px;border:1px solid var(--border);" alt="">
                  <?php endif; ?>
                </div>
              <?php elseif ($s['setting_key'] === 'hero_bg_type'): ?>
                <select class="form-control" name="hero_bg_type" style="max-width:220px;" onchange="updateHeroPreview()">
                  <option value="color" <?= $s['setting_value']==='color'?'selected':'' ?>>🎨 Culoare solidă</option>
                  <option value="gradient" <?= $s['setting_value']==='gradient'?'selected':'' ?>>🌈 Gradient (2 culori)</option>
                  <option value="image" <?= $s['setting_value']==='image'?'selected':'' ?>>🖼️ Imagine de fundal</option>
                </select>
              <?php elseif ($s['setting_key'] === 'hero_bg_gradient_dir'): ?>
                <select class="form-control" name="hero_bg_gradient_dir" style="max-width:220px;" onchange="updateHeroPreview()">
                  <option value="to bottom" <?= $s['setting_value']==='to bottom'?'selected':'' ?>>↓ Sus → Jos</option>
                  <option value="to right" <?= $s['setting_value']==='to right'?'selected':'' ?>>→ Stânga → Dreapta</option>
                  <option value="to bottom right" <?= $s['setting_value']==='to bottom right'?'selected':'' ?>>↘ Diagonal</option>
                  <option value="to bottom left" <?= $s['setting_value']==='to bottom left'?'selected':'' ?>>↙ Diagonal invers</option>
                  <option value="135deg" <?= $s['setting_value']==='135deg'?'selected':'' ?>>↗ 135°</option>
                </select>
              <?php elseif ($s['setting_key'] === 'hero_bg_image_position'): ?>
                <select class="form-control" name="hero_bg_image_position" style="max-width:220px;">
                  <option value="center center" <?= $s['setting_value']==='center center'?'selected':'' ?>>Centru</option>
                  <option value="top center" <?= $s['setting_value']==='top center'?'selected':'' ?>>Sus</option>
                  <option value="bottom center" <?= $s['setting_value']==='bottom center'?'selected':'' ?>>Jos</option>
                  <option value="center left" <?= $s['setting_value']==='center left'?'selected':'' ?>>Stânga</option>
                  <option value="center right" <?= $s['setting_value']==='center right'?'selected':'' ?>>Dreapta</option>
                </select>
              <?php elseif ($s['setting_key'] === 'hero_search_style'): ?>
                <select class="form-control" name="hero_search_style" style="max-width:220px;">
                  <option value="unified" <?= $s['setting_value']==='unified'?'selected':'' ?>>Unificat (input + buton împreună)</option>
                  <option value="split" <?= $s['setting_value']==='split'?'selected':'' ?>>Separat (input | buton)</option>
                </select>
              <?php elseif ($s['setting_key'] === 'hero_bg_size'): ?>
                <select class="form-control" name="hero_bg_size" style="max-width:280px;" onchange="updateHeroPreview()">
                  <option value="cover" <?= $s['setting_value']==='cover'?'selected':'' ?>>🖼️ Cover — acoperă tot (recomand)</option>
                  <option value="contain" <?= $s['setting_value']==='contain'?'selected':'' ?>>📐 Contain — imaginea completă vizibilă</option>
                  <option value="100% auto" <?= $s['setting_value']==='100% auto'?'selected':'' ?>>↔️ Lățime 100%, înălțime auto</option>
                  <option value="auto 100%" <?= $s['setting_value']==='auto 100%'?'selected':'' ?>>↕️ Lățime auto, înălțime 100%</option>
                  <option value="auto" <?= $s['setting_value']==='auto'?'selected':'' ?>>📌 Dimensiune originală</option>
                  <option value="50%" <?= $s['setting_value']==='50%'?'selected':'' ?>>🔲 50% din container</option>
                  <option value="75%" <?= $s['setting_value']==='75%'?'selected':'' ?>>🔲 75% din container</option>
                </select>
              <?php elseif ($s['setting_key'] === 'hero_bg_repeat'): ?>
                <select class="form-control" name="hero_bg_repeat" style="max-width:220px;" onchange="updateHeroPreview()">
                  <option value="no-repeat" <?= $s['setting_value']==='no-repeat'?'selected':'' ?>>❌ Fără repetare (recomand)</option>
                  <option value="repeat" <?= $s['setting_value']==='repeat'?'selected':'' ?>>🔁 Repetare pe X și Y</option>
                  <option value="repeat-x" <?= $s['setting_value']==='repeat-x'?'selected':'' ?>>↔️ Repetare doar pe X</option>
                  <option value="repeat-y" <?= $s['setting_value']==='repeat-y'?'selected':'' ?>>↕️ Repetare doar pe Y</option>
                  <option value="space" <?= $s['setting_value']==='space'?'selected':'' ?>>➡️ Distribuție uniformă</option>
                </select>
              <?php elseif ($s['setting_key'] === 'hero_bg_attachment'): ?>
                <select class="form-control" name="hero_bg_attachment" style="max-width:220px;">
                  <option value="scroll" <?= $s['setting_value']==='scroll'?'selected':'' ?>>📜 Normal (scroll cu pagina)</option>
                  <option value="fixed" <?= $s['setting_value']==='fixed'?'selected':'' ?>>📌 Fixed (efect paralax)</option>
                  <option value="local" <?= $s['setting_value']==='local'?'selected':'' ?>>📦 Local</option>
                </select>
              <?php else: ?>
                <input type="<?= $s['type']==='number'?'number':'text' ?>" class="form-control" name="<?= h($s['setting_key']) ?>" id="s_<?= h($s['setting_key']) ?>" value="<?= h($s['setting_value']) ?>">
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; if ($cg!==null) echo '</div>'; ?>
      <div style="position:sticky;bottom:0;background:var(--bg-2);padding:16px 0;border-top:1px solid var(--border);z-index:10;">
        <button type="button" class="btn btn-primary btn-lg" id="save-settings-btn" onclick="saveSettings()">💾 Salvează setările</button>
        <span id="save-spinner" style="display:none;margin-left:12px;color:var(--text-3);font-size:14px;">Se salvează...</span>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('smtp-test-box').style.display=document.getElementById('smtp-test-box').style.display==='none'?'block':'none'">📧 Test Email</button>
      </div>
      <div id="smtp-test-box" style="display:none;background:var(--bg-2);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:16px;margin-top:12px;">
        <div style="font-weight:600;margin-bottom:10px;">📧 Testează trimiterea de email</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <input type="email" id="smtp-test-email" class="form-control" placeholder="email@test.ro" value="<?= h(current_user()['email']) ?>" style="flex:1;min-width:200px;">
          <button type="button" onclick="testSmtp()" class="btn btn-secondary">Trimite</button>
        </div>
        <div id="smtp-test-result" style="margin-top:8px;font-size:13px;"></div>
      </div>
    </form>
    <script>
    function showGroup(name, btn) {
      document.querySelectorAll('.settings-group').forEach(g=>g.style.display='none');
      document.querySelectorAll('#settings-tabs .tab').forEach(t=>t.classList.remove('active'));
      document.getElementById('grp-'+name).style.display='block';
      btn.classList.add('active');
      document.getElementById('active_tab_input').value = name;
    }
    // Restore active tab from URL
    (function() {
      const params = new URLSearchParams(window.location.search);
      const tab = params.get('tab');
      if (tab) {
        const btn = document.querySelector('#settings-tabs .tab[data-group="' + tab + '"]');
        if (btn) showGroup(tab, btn);
      }
    })();

    // Live hero preview
    function updateHeroPreview() {
      const preview = document.getElementById('hero-preview');
      if (!preview) return;
      const bgType = document.querySelector('[name=hero_bg_type]')?.value;
      const color1 = document.querySelector('[name=hero_bg_color]')?.value || '#F7F7F7';
      const color2 = document.querySelector('[name=hero_bg_color_2]')?.value || '#FFFFFF';
      const gradDir = document.querySelector('[name=hero_bg_gradient_dir]')?.value || 'to bottom right';
      const bgImage = document.querySelector('[name=hero_bg_image]')?.value || '';
      const overlay = document.querySelector('[name=hero_overlay_color]')?.value || 'rgba(0,0,0,0)';
      const titleCol = document.querySelector('[name=hero_title_color]')?.value || '#111111';
      const subCol = document.querySelector('[name=hero_subtitle_color]')?.value || '#888888';

      if (bgType === 'gradient') {
        preview.style.background = `linear-gradient(${gradDir}, ${color1}, ${color2})`;
      } else if (bgType === 'image' && bgImage) {
        preview.style.backgroundImage = `url(${bgImage})`;
        preview.style.backgroundSize = 'cover';
        preview.style.backgroundPosition = 'center';
        preview.style.backgroundColor = color1;
      } else {
        preview.style.background = color1;
        preview.style.backgroundImage = 'none';
      }
      const overlayEl = document.getElementById('hero-overlay-el');
      if (overlayEl) overlayEl.style.background = overlay;
      const titleEl = document.getElementById('hero-title-preview');
      if (titleEl) titleEl.style.color = titleCol;
      const subEl = document.getElementById('hero-sub-preview');
      if (subEl) subEl.style.color = subCol;
    }

    document.addEventListener('change', function(e) {
      if (e.target.closest('#grp-hero')) updateHeroPreview();
    });
    document.addEventListener('input', function(e) {
      if (e.target.closest('#grp-hero')) updateHeroPreview();
    });
    </script>

<?php elseif ($section === 'log'):
    $lf = $data['log_filters'] ?? [];
    $f_action = $lf['f_action'] ?? '';
    $f_target = $lf['f_target'] ?? '';
    $f_admin  = $lf['f_admin']  ?? 0;
    $f_from   = $lf['f_from']   ?? '';
    $f_to     = $lf['f_to']     ?? '';
    $any_filter = $f_action || $f_target || $f_admin || $f_from || $f_to;
?>
    <div class="admin-page-title">🕐 Jurnal activitate</div>

    <!-- Bara de filtre -->
    <form method="GET" class="admin-card log-filter-bar">
        <input type="hidden" name="s" value="log">

        <select name="log_action" class="form-control">
            <option value="">Toate acțiunile</option>
            <?php foreach ($data['log_actions'] as $a): ?>
            <option value="<?= h($a) ?>" <?= $a===$f_action?'selected':'' ?>><?= h($a) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="log_target" class="form-control">
            <option value="">Toate tipurile</option>
            <?php foreach ($data['log_targets'] as $t): ?>
            <option value="<?= h($t) ?>" <?= $t===$f_target?'selected':'' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="log_admin" class="form-control">
            <option value="0">Toți adminii</option>
            <?php foreach ($data['log_admins'] as $ad): ?>
            <option value="<?= $ad['id'] ?>" <?= (int)$ad['id']===(int)$f_admin?'selected':'' ?>><?= h($ad['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" class="form-control" value="<?= h($f_from) ?>" title="De la">
        <span style="color:var(--text-3);">→</span>
        <input type="date" name="date_to" class="form-control" value="<?= h($f_to) ?>" title="Până la">

        <button type="submit" class="btn btn-primary btn-sm">Filtrează</button>
        <?php if ($any_filter): ?>
            <a href="/admin?s=log" class="btn btn-secondary btn-sm">× Resetează</a>
        <?php endif; ?>

        <span class="log-total-badge"><?= number_format($data['log_total']) ?> rezultate</span>
    </form>

    <div class="admin-card">
      <div class="table-wrap"><table class="vtable">
        <thead><tr><th>Admin</th><th>Acțiune</th><th>Țintă</th><th>Modificări / Detalii</th><th>IP</th><th>Când</th></tr></thead>
        <tbody>
        <?php if (!$data['log']): ?>
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3);">Niciun eveniment care să corespundă filtrelor.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['log'] as $l):
            // Detectează JSON diff (settings_update sau alte tipuri structurate)
            $diff = null;
            $det  = trim((string)($l['details'] ?? ''));
            if ($det !== '' && ($det[0] === '{' || $det[0] === '[')) {
                $tmp = @json_decode($det, true);
                if (is_array($tmp)) $diff = $tmp;
            }
        ?>
        <tr>
          <td><?= h($l['uname'] ?? 'System') ?></td>
          <td><code class="log-action-code"><?= h($l['action']) ?></code></td>
          <td style="font-size:12px;"><?= h($l['target_type']) ?><?= $l['target_id'] ? ' <span style="color:var(--text-3)">#'.(int)$l['target_id'].'</span>' : '' ?></td>
          <td style="font-size:12px;">
            <?php if ($diff && is_array($diff) && count($diff) > 0): ?>
                <details class="log-diff-details" <?= count($diff) <= 3 ? 'open' : '' ?>>
                    <summary><?= count($diff) ?> <?= count($diff)===1?'modificare':'modificări' ?> · click pentru detalii</summary>
                    <table class="log-diff">
                        <?php foreach ($diff as $key => $vals):
                            if (is_array($vals) && isset($vals['from'], $vals['to'])) {
                                $from = (string)$vals['from'];
                                $to   = (string)$vals['to'];
                            } else {
                                // Format alternativ: just a flat value
                                $from = '';
                                $to   = is_string($vals) ? $vals : json_encode($vals, JSON_UNESCAPED_UNICODE);
                            }
                        ?>
                        <tr>
                            <td class="diff-key"><?= h((string)$key) ?></td>
                            <td class="diff-old" title="<?= h($from) ?>">
                                <?= $from === '' ? '<i style="color:var(--text-3)">(gol)</i>' : h(mb_substr($from, 0, 80)) ?>
                            </td>
                            <td class="diff-arrow">→</td>
                            <td class="diff-new" title="<?= h($to) ?>">
                                <?= $to === '' ? '<i style="color:var(--text-3)">(gol)</i>' : h(mb_substr($to, 0, 80)) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </details>
            <?php else: ?>
                <span style="color:var(--text-3);"><?= h(mb_substr($det, 0, 120)) ?><?= mb_strlen($det)>120 ? '…' : '' ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--text-3)"><code><?= h($l['ip']) ?></code></td>
          <td style="font-size:12px;color:var(--text-3)" title="<?= h($l['created_at']) ?>"><?= time_ago($l['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <?php
      $tot_pages = max(1, (int)ceil($data['log_total'] / $data['log_per_page']));
      if ($tot_pages > 1):
          // Build base URL preserving filters
          $base_params = array_filter(['s'=>'log','log_action'=>$f_action,'log_target'=>$f_target,'log_admin'=>$f_admin?:'','date_from'=>$f_from,'date_to'=>$f_to]);
          $build_url = function($p) use ($base_params) {
              return '/admin?' . http_build_query(array_merge($base_params, ['pagina'=>$p]));
          };
      ?>
      <div class="log-pagination">
        <?php if ($data['log_page'] > 1): ?>
        <a href="<?= h($build_url($data['log_page']-1)) ?>" class="btn btn-secondary btn-sm">← Anterior</a>
        <?php endif; ?>
        <span style="color:var(--text-3);font-size:13px;">Pagina <?= $data['log_page'] ?> din <?= $tot_pages ?></span>
        <?php if ($data['log_page'] < $tot_pages): ?>
        <a href="<?= h($build_url($data['log_page']+1)) ?>" class="btn btn-secondary btn-sm">Următor →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

<?php elseif ($section === 'search'):
    $q = $data['search_q'] ?? '';
    $total = count($data['search_ads']) + count($data['search_users']) + count($data['search_orders']);
?>
    <div class="admin-page-title">🔍 Căutare globală</div>

    <form method="GET" action="/admin" class="admin-card" style="padding:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
        <input type="hidden" name="s" value="search">
        <input type="search" name="q" class="form-control" placeholder="Caută titlu anunț, email, telefon, ID..." value="<?= h($q) ?>" style="flex:1;min-width:240px;" autofocus>
        <button type="submit" class="btn btn-primary">Caută</button>
    </form>

    <?php if (mb_strlen($q) < 2): ?>
        <div class="admin-card" style="padding:40px;text-align:center;color:var(--text-3);">
            <div style="font-size:48px;margin-bottom:12px;">🔎</div>
            <p>Introdu un termen de minim 2 caractere ca să cauți.<br>Caută în: <b>anunțuri</b> (titlu, slug, ID), <b>utilizatori</b> (nume, email, telefon, ID), <b>plăți</b> (ID, payment_id).</p>
        </div>
    <?php elseif ($total === 0): ?>
        <div class="admin-card" style="padding:40px;text-align:center;color:var(--text-3);">
            <div style="font-size:48px;margin-bottom:12px;">😶</div>
            <p>Niciun rezultat pentru „<b><?= h($q) ?></b>"</p>
        </div>
    <?php else: ?>
        <div style="margin-bottom:16px;color:var(--text-3);font-size:14px;">
            <?= $total ?> rezultate pentru „<b><?= h($q) ?></b>" — <?= count($data['search_ads']) ?> anunțuri, <?= count($data['search_users']) ?> utilizatori, <?= count($data['search_orders']) ?> plăți
        </div>

        <?php if ($data['search_ads']): ?>
        <div class="admin-card">
            <div class="admin-card-header"><h2 class="admin-card-title">📋 Anunțuri (<?= count($data['search_ads']) ?>)</h2></div>
            <div class="table-wrap"><table class="vtable">
                <thead><tr><th>ID</th><th>Titlu</th><th>Utilizator</th><th>Categorie</th><th>Status</th><th>Data</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($data['search_ads'] as $a): ?>
                <tr>
                    <td style="color:var(--text-3)">#<?= $a['id'] ?></td>
                    <td><a href="/anunt/<?= h($a['slug']) ?>" target="_blank" style="color:var(--brand)"><?= h(mb_substr($a['title'],0,60)) ?></a></td>
                    <td><?= h($a['uname']) ?></td>
                    <td><?= h($a['cname']) ?></td>
                    <td><span class="badge badge-<?= ['active'=>'success','pending'=>'warning','rejected'=>'danger','expired'=>'gray','sold'=>'info'][$a['status']]??'gray' ?>"><?= $a['status'] ?></span></td>
                    <td><?= time_ago($a['created_at']) ?></td>
                    <td><a href="/admin?s=ads&q=<?= urlencode($a['title']) ?>" class="btn btn-secondary btn-sm">Vezi</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>

        <?php if ($data['search_users']): ?>
        <div class="admin-card">
            <div class="admin-card-header"><h2 class="admin-card-title">👤 Utilizatori (<?= count($data['search_users']) ?>)</h2></div>
            <div class="table-wrap"><table class="vtable">
                <thead><tr><th>ID</th><th>Nume</th><th>Email</th><th>Telefon</th><th>Anunțuri</th><th>Rol</th><th>Activ</th><th>Înregistrat</th></tr></thead>
                <tbody>
                <?php foreach ($data['search_users'] as $u_): ?>
                <tr>
                    <td style="color:var(--text-3)">#<?= $u_['id'] ?></td>
                    <td><a href="/admin?s=users&q=<?= urlencode($u_['email']) ?>" style="color:var(--brand)"><?= h($u_['name']) ?></a></td>
                    <td><?= h($u_['email']) ?></td>
                    <td><?= h($u_['phone'] ?? '-') ?></td>
                    <td><?= number_format($u_['ad_count']) ?></td>
                    <td><span class="badge badge-<?= $u_['role']==='admin'?'danger':($u_['role']==='moderator'?'warning':'gray') ?>"><?= h($u_['role']) ?></span></td>
                    <td><?= $u_['is_active'] ? '✓' : '✗' ?></td>
                    <td><?= time_ago($u_['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>

        <?php if ($data['search_orders']): ?>
        <div class="admin-card">
            <div class="admin-card-header"><h2 class="admin-card-title">💳 Plăți (<?= count($data['search_orders']) ?>)</h2></div>
            <div class="table-wrap"><table class="vtable">
                <thead><tr><th>ID</th><th>Utilizator</th><th>Tip</th><th>Sumă</th><th>Status</th><th>Data</th></tr></thead>
                <tbody>
                <?php foreach ($data['search_orders'] as $o): ?>
                <tr>
                    <td style="color:var(--text-3)">#<?= $o['id'] ?></td>
                    <td><?= h($o['uname'] ?? '-') ?> <small style="color:var(--text-3);"><?= h($o['uemail'] ?? '') ?></small></td>
                    <td><?= h($o['type']) ?></td>
                    <td><?= number_format($o['amount'],2,',','.') ?> <?= h($o['currency'] ?? 'RON') ?></td>
                    <td><span class="badge badge-<?= $o['status']==='completed'?'success':($o['status']==='pending'?'warning':'danger') ?>"><?= h($o['status']) ?></span></td>
                    <td><?= time_ago($o['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>
  </div><!-- /admin-main -->
<script>
async function saveSettings() {
  const form = document.getElementById('settings-form');
  const btn = document.getElementById('save-settings-btn');
  const spinner = document.getElementById('save-spinner');
  if (!form || !btn) return;

  btn.disabled = true;
  btn.textContent = '💾 Se salvează...';
  if (spinner) spinner.style.display = 'inline';

  const data = new FormData(form);
  // Add unchecked checkboxes as 0
  form.querySelectorAll('input[type=checkbox]').forEach(cb => {
    if (!cb.checked) data.set(cb.name, '0');
  });

  try {
    const res = await fetch('/api/save-settings.php', {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: data,
      credentials: 'same-origin'
    });
    const json = await res.json();

    if (json.success) {
      const toast = document.getElementById('settings-save-toast');
      if (toast) {
        toast.style.display = 'block';
        toast.style.opacity = '1';
        setTimeout(() => { toast.style.opacity = '0'; }, 2500);
        setTimeout(() => { toast.style.display = 'none'; toast.style.opacity = '1'; }, 3000);
      }
      // Apply color changes live
      const primary = data.get('site_color_primary');
      const secondary = data.get('site_color_secondary');
      if (primary) document.documentElement.style.setProperty('--brand', primary);
      if (secondary) document.documentElement.style.setProperty('--brand2', secondary);
    } else {
      alert('Eroare la salvare: ' + (json.error || 'necunoscută'));
    }
  } catch(e) {
    alert('Eroare de conexiune.');
  } finally {
    btn.disabled = false;
    btn.textContent = '💾 Salvează setările';
    if (spinner) spinner.style.display = 'none';
  }
}

async function testSmtp() {
  const email = document.getElementById('smtp-test-email')?.value;
  const res_el = document.getElementById('smtp-test-result');
  if (!email) { res_el.textContent = 'Introdu un email.'; return; }
  res_el.textContent = '⏳ Se trimite...'; res_el.style.color = 'var(--text-3)';
  try {
    const csrf = document.querySelector('[name=csrf_token]')?.value || '';
    const r = await fetch(window.location.href, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=test_smtp&test_email='+encodeURIComponent(email)+'&csrf_token='+encodeURIComponent(csrf)
    });
    const d = await r.json();
    res_el.textContent = d.msg;
    res_el.style.color = d.ok ? 'var(--success)' : 'var(--danger)';
  } catch(e) {
    res_el.textContent = '❌ Eroare request: '+e.message;
    res_el.style.color = 'var(--danger)';
  }
}
</script>
</div><!-- /admin-layout -->

<?php if ($section === 'dashboard'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
    const canvas = document.getElementById('activity-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    // Pregatim datele din PHP
    <?php
    // Generam ultimele 14 zile
    $days14 = [];
    for ($i = 13; $i >= 0; $i--) {
        $days14[] = date('Y-m-d', strtotime("-$i days"));
    }
    $ads_by_day   = array_column($data['chart_ads'],   'count', 'day');
    $users_by_day = array_column($data['chart_users'], 'count', 'day');
    $labels = array_map(fn($d) => date('d.m', strtotime($d)), $days14);
    $ads_data   = array_map(fn($d) => (int)($ads_by_day[$d]   ?? 0), $days14);
    $users_data = array_map(fn($d) => (int)($users_by_day[$d] ?? 0), $days14);
    ?>
    const labels = <?= js_json($labels) ?>;
    const adsData   = <?= js_json($ads_data) ?>;
    const usersData = <?= js_json($users_data) ?>;

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Anunțuri noi',
                    data: adsData,
                    borderColor: '#E8380D',
                    backgroundColor: 'rgba(232,56,13,0.08)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                },
                {
                    label: 'Utilizatori noi',
                    data: usersData,
                    borderColor: '#3B82F6',
                    backgroundColor: 'rgba(59,130,246,0.08)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 3,
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                },
                x: {
                    ticks: { font: { size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($section === 'ads'): ?>
<script>
// ============== ADS: Bulk Actions + Date Range Presets ==============
(function() {

    // ====== Date range quick presets ======
    window.setDateRange = function(preset) {
        const from = document.querySelector('input[name="date_from"]');
        const to   = document.querySelector('input[name="date_to"]');
        if (!from || !to) return;
        const today = new Date();
        const fmt = d => d.toISOString().slice(0,10);
        if (preset === 'today') {
            from.value = fmt(today);
            to.value   = fmt(today);
        } else if (preset === 'week') {
            const start = new Date(today);
            start.setDate(today.getDate() - 7);
            from.value = fmt(start);
            to.value   = fmt(today);
        } else if (preset === 'month') {
            const start = new Date(today);
            start.setDate(today.getDate() - 30);
            from.value = fmt(start);
            to.value   = fmt(today);
        } else if (preset === 'clear') {
            from.value = '';
            to.value   = '';
        }
        // Submit automat după preset (pentru viteză de filtrare)
        document.getElementById('adsFilterForm').submit();
    };

    // ====== Bulk selection ======
    window.toggleAll = function(masterCheckbox) {
        document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = masterCheckbox.checked);
        updateBulkBar();
    };

    window.updateBulkBar = function() {
        const checked = document.querySelectorAll('.bulk-check:checked');
        const bar = document.getElementById('bulkBar');
        const counter = document.getElementById('bulkCount');
        if (!bar || !counter) return;
        bar.style.display = checked.length > 0 ? 'flex' : 'none';
        counter.textContent = checked.length;
        // Sincronizeaza si checkbox-ul "select all"
        const allChk = document.getElementById('selectAllChk');
        const allRows = document.querySelectorAll('.bulk-check');
        if (allChk && allRows.length > 0) {
            allChk.checked = checked.length === allRows.length;
            allChk.indeterminate = checked.length > 0 && checked.length < allRows.length;
        }
    };

    window.deselectAll = function() {
        document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = false);
        const allChk = document.getElementById('selectAllChk');
        if (allChk) { allChk.checked = false; allChk.indeterminate = false; }
        updateBulkBar();
    };

    window.bulkAction = function(action) {
        const checked = document.querySelectorAll('.bulk-check:checked');
        if (!checked.length) return;
        const n = checked.length;
        const confirmMsg = {
            approve: `Aprobi ${n} anunțuri selectate?`,
            reject:  `Respingi ${n} anunțuri selectate?`,
            delete:  `ȘTERGI PERMANENT ${n} anunțuri selectate?\n\nAcțiunea NU poate fi anulată.`
        }[action] || `Aplici acțiunea pentru ${n} anunțuri?`;

        if (!confirm(confirmMsg)) return;

        let reason = '';
        if (action === 'reject') {
            reason = prompt(`Motiv respingere (aplicat la toate ${n} anunțurile):`);
            if (reason === null) return; // anulat
            reason = (reason || '').trim();
            if (!reason) { alert('Trebuie să introduci un motiv.'); return; }
        }

        // CSRF token — luăm din primul form existent în pagină
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (!csrfInput) { alert('CSRF token lipsește. Reîncarcă pagina.'); return; }

        // Construim form-ul de bulk și submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        form.style.display = 'none';

        const addInput = (name, value) => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = name; inp.value = value;
            form.appendChild(inp);
        };
        addInput('csrf_token', csrfInput.value);
        addInput('action', 'bulk_' + action);
        if (reason) addInput('reason', reason);
        checked.forEach(cb => addInput('ad_ids[]', cb.value));

        document.body.appendChild(form);
        form.submit();
    };

})();
</script>
<?php endif; ?>
<?php include __DIR__ . "/../includes/footer.php"; ?>
