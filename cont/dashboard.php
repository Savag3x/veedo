<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$u   = current_user();
$uid = $u['id'];

// ── Perioada selectata ────────────────────────────────────
$period = in_array($_GET['period'] ?? '', ['7','30','90']) ? (int)$_GET['period'] : 30;
$prev   = $period * 2; // pentru comparatie

// ── Stats generale ────────────────────────────────────────
$stats = DB::fetch("
    SELECT
        COUNT(*)                        AS total_ads,
        SUM(status='active')            AS active_ads,
        SUM(status='pending')           AS pending_ads,
        SUM(status='sold')              AS sold_ads,
        COALESCE(SUM(views_count),0)    AS total_views,
        COALESCE(SUM(likes_count),0)    AS total_likes,
        COALESCE(SUM(contact_count),0)  AS total_contacts
    FROM ads WHERE user_id=?
", [$uid]);

// ── Views pe perioada selectata vs anterioara ─────────────
$views_now  = (int)DB::fetch("
    SELECT COUNT(*) as c FROM ad_views av
    JOIN ads a ON a.id=av.ad_id
    WHERE a.user_id=? AND av.viewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
", [$uid, $period])['c'];

$views_prev = (int)DB::fetch("
    SELECT COUNT(*) as c FROM ad_views av
    JOIN ads a ON a.id=av.ad_id
    WHERE a.user_id=?
      AND av.viewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND av.viewed_at <  DATE_SUB(NOW(), INTERVAL ? DAY)
", [$uid, $prev, $period])['c'];

$views_delta = $views_prev > 0 ? round(($views_now - $views_prev) / $views_prev * 100) : ($views_now > 0 ? 100 : 0);

// ── Likes pe perioada ─────────────────────────────────────
$likes_now  = (int)DB::fetch("
    SELECT COUNT(*) as c FROM likes l
    JOIN ads a ON a.id=l.ad_id
    WHERE a.user_id=? AND l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
", [$uid, $period])['c'];

$likes_prev = (int)DB::fetch("
    SELECT COUNT(*) as c FROM likes l
    JOIN ads a ON a.id=l.ad_id
    WHERE a.user_id=?
      AND l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND l.created_at <  DATE_SUB(NOW(), INTERVAL ? DAY)
", [$uid, $prev, $period])['c'];

$likes_delta = $likes_prev > 0 ? round(($likes_now - $likes_prev) / $likes_prev * 100) : ($likes_now > 0 ? 100 : 0);

// ── Contacte pe perioada ──────────────────────────────────
$contacts_now = (int)DB::fetch("
    SELECT COUNT(*) as c FROM conversations
    WHERE seller_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
", [$uid, $period])['c'];

// ── Rata conversie (views -> contacte) ───────────────────
$conv_rate = $views_now > 0 ? round($contacts_now / $views_now * 100, 1) : 0;

// ── Grafic views zilnic ───────────────────────────────────
$chart_data_raw = DB::fetchAll("
    SELECT DATE(av.viewed_at) as day, COUNT(*) as views
    FROM ad_views av JOIN ads a ON a.id=av.ad_id
    WHERE a.user_id=? AND av.viewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DATE(av.viewed_at) ORDER BY day ASC
", [$uid, $period]);

$chart_map = [];
for ($d = $period - 1; $d >= 0; $d--) {
    $day = date('Y-m-d', strtotime("-{$d} days"));
    $chart_map[$day] = 0;
}
foreach ($chart_data_raw as $row) {
    if (isset($chart_map[$row['day']])) $chart_map[$row['day']] = (int)$row['views'];
}

// ── Grafic likes zilnic ───────────────────────────────────
$likes_raw = DB::fetchAll("
    SELECT DATE(l.created_at) as day, COUNT(*) as cnt
    FROM likes l JOIN ads a ON a.id=l.ad_id
    WHERE a.user_id=? AND l.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DATE(l.created_at) ORDER BY day ASC
", [$uid, $period]);

$likes_map = array_fill_keys(array_keys($chart_map), 0);
foreach ($likes_raw as $row) {
    if (isset($likes_map[$row['day']])) $likes_map[$row['day']] = (int)$row['cnt'];
}

// ── Top anunturi ──────────────────────────────────────────
$top_ads = DB::fetchAll("
    SELECT a.*, c.name as cat_name, c.icon as cat_icon,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo,
           COUNT(av.id) as views_period
    FROM ads a
    JOIN categories c ON c.id=a.category_id
    LEFT JOIN ad_views av ON av.ad_id=a.id AND av.viewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    WHERE a.user_id=? AND a.status IN ('active','pending')
    GROUP BY a.id
    ORDER BY views_period DESC, a.views_count DESC
    LIMIT 6
", [$period, $uid]);

// ── Anunturi care expira curand ───────────────────────────
$expiring = DB::fetchAll("
    SELECT id, title, slug, expires_at,
           DATEDIFF(expires_at, NOW()) as days_left
    FROM ads
    WHERE user_id=? AND status='active'
      AND expires_at IS NOT NULL
      AND expires_at <= DATE_ADD(NOW(), INTERVAL 14 DAY)
    ORDER BY expires_at ASC LIMIT 5
", [$uid]);

// ── Mesaje necitite ───────────────────────────────────────
$unread_msgs = (int)DB::fetch("
    SELECT COALESCE(SUM(CASE WHEN buyer_id=? THEN buyer_unread ELSE seller_unread END),0) as c
    FROM conversations WHERE buyer_id=? OR seller_id=?
", [$uid,$uid,$uid])['c'];

$followers = (int)DB::fetch("SELECT followers_count FROM users WHERE id=?", [$uid])['followers_count'];

// ── Tranzactii recente ────────────────────────────────────
$recent_orders = DB::fetchAll("
    SELECT o.*, a.title as ad_title
    FROM orders o LEFT JOIN ads a ON a.id=o.ad_id
    WHERE o.user_id=? AND o.status='completed'
    ORDER BY o.created_at DESC LIMIT 4
", [$uid]);

// ── Venituri totale (boost-uri platite) ───────────────────
$revenue = (float)DB::fetch("
    SELECT COALESCE(SUM(amount),0) as total
    FROM orders WHERE user_id=? AND status='completed'
", [$uid])['total'];

// JSON pentru chart
$chart_labels = json_encode(array_map(fn($d) => date('d M', strtotime($d)), array_keys($chart_map)));
$chart_views  = json_encode(array_values($chart_map));
$chart_likes  = json_encode(array_values($likes_map));

$seo_title = 'Dashboard Analytics — ' . $u['name'];
include __DIR__ . '/../includes/header.php';
?>

<style>
.dash-wrap { max-width: 1140px; margin: 0 auto; padding: 24px 16px 40px; }

/* Header */
.dash-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
}
.dash-title { font-size: 1.3rem; font-weight: 800; }

/* Period selector */
.period-tabs {
    display: flex; gap: 4px;
    background: var(--bg-2); border-radius: 10px; padding: 3px;
}
.period-tab {
    padding: 5px 14px; border-radius: 8px; font-size: 13px;
    font-weight: 600; text-decoration: none; color: var(--text-3);
    transition: all 0.15s;
}
.period-tab.active {
    background: var(--surface); color: var(--text);
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}

/* KPI cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px; margin-bottom: 20px;
}
.kpi-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 18px 16px;
    display: flex; flex-direction: column; gap: 4px;
}
.kpi-icon { font-size: 1.3rem; margin-bottom: 4px; }
.kpi-val  { font-size: 1.7rem; font-weight: 800; line-height: 1; }
.kpi-lbl  { font-size: 11px; color: var(--text-3); font-weight: 500; }
.kpi-delta {
    display: inline-flex; align-items: center; gap: 2px;
    font-size: 11px; font-weight: 700; margin-top: 4px;
    padding: 2px 6px; border-radius: 6px;
}
.delta-up   { background: #D1FAE5; color: #065F46; }
.delta-down { background: #FEE2E2; color: #991B1B; }
.delta-neu  { background: var(--bg-2); color: var(--text-3); }

/* Chart card */
.chart-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 20px; margin-bottom: 20px;
}
.chart-card-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px;
}
.chart-card-title { font-size: 0.95rem; font-weight: 700; }
.chart-legend {
    display: flex; gap: 14px; font-size: 12px;
}
.chart-legend-item {
    display: flex; align-items: center; gap: 5px; color: var(--text-2);
}
.chart-legend-dot {
    width: 10px; height: 10px; border-radius: 50%;
}

/* 2 col grid */
.two-col {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 16px; margin-bottom: 20px;
}
@media (max-width: 680px) {
    .two-col { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: 1fr 1fr; }
}

/* Card general */
.g-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 18px;
}
.g-card-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px;
}
.g-card-title { font-size: 0.9rem; font-weight: 700; }
.g-card-link  { font-size: 12px; color: var(--brand); text-decoration: none; }

/* Top ads list */
.top-ad-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 0; border-bottom: 1px solid var(--border-2);
}
.top-ad-row:last-child { border-bottom: none; padding-bottom: 0; }
.top-ad-rank {
    font-size: 12px; font-weight: 800; color: var(--text-3);
    width: 18px; flex-shrink: 0; text-align: center;
}
.top-ad-thumb {
    width: 42px; height: 42px; border-radius: 8px;
    object-fit: cover; flex-shrink: 0; background: var(--bg-3);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
}
.top-ad-info { flex: 1; min-width: 0; }
.top-ad-title {
    font-size: 12px; font-weight: 600; color: var(--text);
    text-decoration: none; display: block;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.top-ad-meta { font-size: 11px; color: var(--text-3); margin-top: 2px; }
.top-ad-stat {
    font-size: 12px; font-weight: 700; color: var(--brand);
    flex-shrink: 0; text-align: right;
}

/* Expiring */
.exp-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 0; border-bottom: 1px solid var(--border-2);
    font-size: 13px;
}
.exp-row:last-child { border-bottom: none; }
.exp-badge {
    font-size: 10px; font-weight: 700; padding: 2px 7px;
    border-radius: 6px; flex-shrink: 0;
}

/* Quick actions */
.qa-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 8px; margin-top: 20px;
}
</style>

<div class="dash-wrap">

    <!-- Header -->
    <div class="dash-header">
        <div>
            <div class="dash-title">📊 Analytics</div>
            <div style="font-size:13px;color:var(--text-3);margin-top:2px;">
                Bun venit, <?= h($u['name']) ?>!
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <!-- Period tabs -->
            <div class="period-tabs">
                <?php foreach ([7=>'7 zile',30=>'30 zile',90=>'90 zile'] as $p=>$lbl): ?>
                <a href="?period=<?= $p ?>" class="period-tab <?= $period===$p?'active':'' ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
            <a href="/anunt/nou" class="btn btn-primary">+ Anunț nou</a>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">

        <!-- Vizualizari -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
            <div class="kpi-val" style="color:var(--brand)"><?= number_format($views_now) ?></div>
            <div class="kpi-lbl">Vizualizări (<?= $period ?>z)</div>
            <?php
            $cls = $views_delta > 0 ? 'delta-up' : ($views_delta < 0 ? 'delta-down' : 'delta-neu');
            $arr = $views_delta > 0 ? '↑' : ($views_delta < 0 ? '↓' : '→');
            ?>
            <div class="kpi-delta <?= $cls ?>"><?= $arr ?> <?= abs($views_delta) ?>% vs anterior</div>
        </div>

        <!-- Likes -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="#EF4444" stroke="#EF4444" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></div>
            <div class="kpi-val" style="color:#EF4444"><?= number_format($likes_now) ?></div>
            <div class="kpi-lbl">Like-uri (<?= $period ?>z)</div>
            <?php
            $cls2 = $likes_delta > 0 ? 'delta-up' : ($likes_delta < 0 ? 'delta-down' : 'delta-neu');
            $arr2 = $likes_delta > 0 ? '↑' : ($likes_delta < 0 ? '↓' : '→');
            ?>
            <div class="kpi-delta <?= $cls2 ?>"><?= $arr2 ?> <?= abs($likes_delta) ?>% vs anterior</div>
        </div>

        <!-- Contacte -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#8B5CF6" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
            <div class="kpi-val" style="color:#8B5CF6"><?= $contacts_now ?></div>
            <div class="kpi-lbl">Contacte (<?= $period ?>z)</div>
            <div class="kpi-delta delta-neu"><?= $conv_rate ?>% rată conversie</div>
        </div>

        <!-- Total views all time -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
            <div class="kpi-val" style="color:var(--text)"><?= number_format($stats['total_views']) ?></div>
            <div class="kpi-lbl">Vizualizări totale</div>
            <div class="kpi-delta delta-neu">Toate timpurile</div>
        </div>

        <!-- Anunturi active -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
            <div class="kpi-val" style="color:var(--success)"><?= $stats['active_ads'] ?></div>
            <div class="kpi-lbl">Anunțuri active</div>
            <?php if ($stats['pending_ads'] > 0): ?>
            <div class="kpi-delta delta-neu">+ <?= $stats['pending_ads'] ?> în așteptare</div>
            <?php endif; ?>
        </div>

        <!-- Urmăritori -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
            <div class="kpi-val" style="color:var(--brand)"><?= number_format($followers) ?></div>
            <div class="kpi-lbl">Urmăritori</div>
            <?php if ($unread_msgs > 0): ?>
            <div class="kpi-delta delta-up">💬 <?= $unread_msgs ?> mesaje noi</div>
            <?php endif; ?>
        </div>

        <!-- Revenue -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
            <div class="kpi-val" style="color:var(--success)"><?= number_format($revenue, 0) ?></div>
            <div class="kpi-lbl">RON cheltuiți (boost)</div>
            <div class="kpi-delta delta-neu">Toate comenzile</div>
        </div>

        <!-- Vândute -->
        <div class="kpi-card">
            <div class="kpi-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--info)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="kpi-val" style="color:var(--info)"><?= $stats['sold_ads'] ?></div>
            <div class="kpi-lbl">Anunțuri vândute</div>
            <div class="kpi-delta delta-neu">Din <?= $stats['total_ads'] ?> totale</div>
        </div>

    </div>

    <!-- Grafic vizualizari + likes -->
    <div class="chart-card">
        <div class="chart-card-header">
            <div class="chart-card-title">📈 Evoluție — ultimele <?= $period ?> zile</div>

        </div>
        <div style="position:relative;height:200px;">
            <canvas id="mainChart"></canvas>
        </div>
    </div>

    <!-- Top anunturi + Expira curand -->
    <div class="two-col">

        <!-- Top anunturi -->
        <div class="g-card">
            <div class="g-card-header">
                <div class="g-card-title">🏆 Top anunțuri — <?= $period ?> zile</div>
                <a href="/cont/anunturile-mele" class="g-card-link">Toate →</a>
            </div>
            <?php if (empty($top_ads)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-3);font-size:13px;">
                    Niciun anunț. <a href="/anunt/nou" style="color:var(--brand);">Postează!</a>
                </div>
            <?php else: ?>
                <?php foreach ($top_ads as $i => $a): ?>
                <div class="top-ad-row">
                    <div class="top-ad-rank"><?= $i+1 ?></div>
                    <?php if ($a['main_photo']): ?>
                        <img class="top-ad-thumb" src="<?= h(photo_url($a['main_photo'])) ?>" alt="">
                    <?php else: ?>
                        <div class="top-ad-thumb"><?= h($a['cat_icon']) ?></div>
                    <?php endif; ?>
                    <div class="top-ad-info">
                        <a class="top-ad-title" href="/anunt/<?= h($a['slug']) ?>"><?= h(mb_substr($a['title'],0,38)) ?></a>
                        <div class="top-ad-meta">
                            👁 <?= number_format($a['views_count']) ?> total
                            · ❤️ <?= $a['likes_count'] ?>
                            <?= $a['is_boosted'] ? ' · ⭐ Boost' : '' ?>
                        </div>
                    </div>
                    <div class="top-ad-stat">
                        <?= $a['views_period'] ?><br>
                        <span style="font-size:9px;color:var(--text-3);font-weight:400;">vizualiz.</span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Expira curand + Mesaje -->
        <div style="display:flex;flex-direction:column;gap:16px;">

            <?php if (!empty($expiring)): ?>
            <div class="g-card">
                <div class="g-card-header">
                    <div class="g-card-title">⚠️ Expiră curând</div>
                </div>
                <?php foreach ($expiring as $e): ?>
                <div class="exp-row">
                    <a href="/anunt/<?= h($e['slug']) ?>" style="font-size:12px;color:var(--text);text-decoration:none;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-right:8px;">
                        <?= h(mb_substr($e['title'],0,35)) ?>
                    </a>
                    <?php
                    $dl = (int)$e['days_left'];
                    $bc = $dl <= 3 ? '#FEE2E2' : '#FEF3C7';
                    $tc = $dl <= 3 ? '#991B1B' : '#92400E';
                    ?>
                    <span class="exp-badge" style="background:<?= $bc ?>;color:<?= $tc ?>;">
                        <?= $dl ?>z
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Mesaje recente -->
            <?php
            $recent_convs = DB::fetchAll("
                SELECT c.*,
                       CASE WHEN c.buyer_id=? THEN u2.name ELSE u1.name END as other_name,
                       CASE WHEN c.buyer_id=? THEN u2.avatar ELSE u1.avatar END as other_avatar,
                       a.title as ad_title
                FROM conversations c
                JOIN users u1 ON u1.id=c.buyer_id
                JOIN users u2 ON u2.id=c.seller_id
                LEFT JOIN ads a ON a.id=c.ad_id
                WHERE c.buyer_id=? OR c.seller_id=?
                ORDER BY c.last_message_at DESC LIMIT 4
            ", [$uid,$uid,$uid,$uid]);
            ?>
            <div class="g-card" style="flex:1;">
                <div class="g-card-header">
                    <div class="g-card-title">
                        💬 Mesaje
                        <?php if ($unread_msgs > 0): ?>
                        <span style="background:var(--brand);color:#fff;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:4px;"><?= $unread_msgs ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="/mesaje" class="g-card-link">Toate →</a>
                </div>
                <?php if (empty($recent_convs)): ?>
                    <div style="text-align:center;padding:16px;color:var(--text-3);font-size:13px;">Niciun mesaj.</div>
                <?php else: ?>
                    <?php foreach ($recent_convs as $i => $conv): ?>
                    <a href="/mesaje/<?= $conv['id'] ?>" style="display:flex;align-items:center;gap:9px;padding:8px 0;<?= $i<count($recent_convs)-1?'border-bottom:1px solid var(--border-2);':'' ?>;text-decoration:none;">
                        <img src="<?= h(avatar_url($conv['other_avatar'],$conv['other_name'])) ?>"
                             style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:12px;font-weight:600;color:var(--text);"><?= h($conv['other_name']) ?></div>
                            <div style="font-size:11px;color:var(--text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr($conv['last_message']??'',0,40)) ?></div>
                        </div>
                        <?php $my_unread = $uid==$conv['buyer_id'] ? $conv['buyer_unread'] : $conv['seller_unread']; ?>
                        <?php if ($my_unread > 0): ?>
                        <span style="background:var(--brand);color:#fff;font-size:10px;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;flex-shrink:0;"><?= $my_unread ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Tranzactii recente -->
    <?php if (!empty($recent_orders)): ?>
    <div class="g-card" style="margin-bottom:20px;">
        <div class="g-card-header">
            <div class="g-card-title">💳 Plăți recente</div>
            <a href="/cont/tranzactii" class="g-card-link">Toate →</a>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;">
        <?php
        $icons  = ['boost_7'=>'⭐','boost_30'=>'🚀','business_verify'=>'🏢','ad_post'=>'📋'];
        $labels = ['boost_7'=>'Boost 7 zile','boost_30'=>'Boost 30 zile','business_verify'=>'Business Verify','ad_post'=>'Anunț plătit'];
        foreach ($recent_orders as $o): ?>
        <div style="display:flex;align-items:center;gap:10px;background:var(--bg-2);border-radius:10px;padding:10px 12px;">
            <span style="font-size:1.4rem;"><?= $icons[$o['type']] ?? '💳' ?></span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:600;"><?= $labels[$o['type']] ?? h($o['type']) ?></div>
                <div style="font-size:11px;color:var(--text-3);"><?= date('d.m.Y', strtotime($o['created_at'])) ?></div>
            </div>
            <div style="font-weight:800;color:var(--success);font-size:13px;"><?= number_format($o['amount'],0) ?> RON</div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick actions -->
    <div class="qa-grid">
        <a href="/anunt/nou"              class="btn btn-primary"    style="justify-content:center;">+ Anunț nou</a>
        <a href="/cont/anunturile-mele"   class="btn btn-secondary"  style="justify-content:center;">📋 Anunțuri</a>
        <a href="/mesaje"                 class="btn btn-secondary"  style="justify-content:center;">💬 Mesaje</a>
        <a href="/cont/favorite"          class="btn btn-secondary"  style="justify-content:center;">❤️ Favorite</a>
        <a href="/cont/tranzactii"        class="btn btn-secondary"  style="justify-content:center;">💳 Plăți</a>
        <a href="/cont/setari"            class="btn btn-secondary"  style="justify-content:center;">⚙️ Setări</a>
        <a href="/utilizator/<?= $uid ?>" class="btn btn-secondary"  style="justify-content:center;">👤 Profil</a>
        <a href="/cont/abonamente"        class="btn btn-secondary"  style="justify-content:center;">⭐ Boost</a>
    </div>

</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
    if (typeof Chart === 'undefined') return; // CDN indisponibil: pagina funcționează fără grafic
    const labels = <?= $chart_labels ?>;
    const views  = <?= $chart_views ?>;
    const likes  = <?= $chart_likes ?>;

    const isDark = document.documentElement.dataset.theme === 'dark';
    const gridCol = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
    const textCol = isDark ? 'rgba(255,255,255,0.5)'  : 'rgba(0,0,0,0.4)';

    new Chart(document.getElementById('mainChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Vizualizări',
                    data: views,
                    borderColor: '#E8380D',
                    backgroundColor: 'rgba(232,56,13,0.08)',
                    borderWidth: 2.5,
                    pointRadius: labels.length > 30 ? 0 : 3,
                    pointHoverRadius: 5,
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y',
                },
                {
                    label: 'Like-uri',
                    data: likes,
                    borderColor: '#EF4444',
                    backgroundColor: 'rgba(239,68,68,0.05)',
                    borderWidth: 2,
                    pointRadius: labels.length > 30 ? 0 : 3,
                    pointHoverRadius: 5,
                    tension: 0.4,
                    fill: false,
                    borderDash: [5, 3],
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'line',
                        color: textCol,
                        font: { size: 12 },
                        boxWidth: 20,
                        padding: 16,
                    }
                },
                tooltip: {
                    backgroundColor: isDark ? '#1A1A1A' : '#fff',
                    titleColor: isDark ? '#fff' : '#111',
                    bodyColor: isDark ? 'rgba(255,255,255,0.7)' : 'rgba(0,0,0,0.6)',
                    borderColor: isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
                    borderWidth: 1,
                    padding: 10,
                    usePointStyle: true,
                }
            },
            scales: {
                x: {
                    grid: { color: gridCol },
                    ticks: {
                        color: textCol, maxRotation: 0,
                        maxTicksLimit: labels.length > 30 ? 10 : 15,
                    }
                },
                y: {
                    grid: { color: gridCol },
                    ticks: { color: textCol, precision: 0 },
                    position: 'left',
                },
                y1: {
                    grid: { drawOnChartArea: false },
                    ticks: { color: '#EF4444', precision: 0 },
                    position: 'right',
                }
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>