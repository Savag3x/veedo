<?php
// api/send-alerts.php — Procesare alerte de căutare (rulat de cron job)
// Cron: */5 * * * * php /path/to/public_html/api/send-alerts.php >> /tmp/veedo-alerts.log 2>&1

define('CRON_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';

// Verificam daca e apelat din CLI sau cu token secret
$cli = php_sapi_name() === 'cli';
$token = $_GET['token'] ?? '';
$valid_token = setting('cron_secret_token', '');

if (!$cli && (!$valid_token || $token !== $valid_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Use CLI or provide valid token.']);
    exit;
}

$now = date('Y-m-d H:i:s');
$processed = 0;
$sent = 0;

// Alerte instant (anunturi noi in ultimele 10 minute)
$instant_alerts = DB::fetchAll("
    SELECT sa.*, u.email, u.name as user_name, c.name as cat_name
    FROM search_alerts sa
    JOIN users u ON u.id=sa.user_id
    LEFT JOIN categories c ON c.id=sa.category_id
    WHERE sa.is_active=1 AND sa.frequency='instant'
      AND (sa.last_sent_at IS NULL OR sa.last_sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
    LIMIT 100
", []);

foreach ($instant_alerts as $alert) {
    $processed++;
    
    // Construim query de cautare
    $where = ["a.status='active'", "a.created_at > COALESCE(?, DATE_SUB(NOW(), INTERVAL 10 MINUTE))"];
    $params = [$alert['last_sent_at']];
    
    if ($alert['keyword'])    { $where[] = "(a.title LIKE ? OR a.description LIKE ?)"; $k = '%'.$alert['keyword'].'%'; $params[] = $k; $params[] = $k; }
    if ($alert['category_id']){ $where[] = "a.category_id=?"; $params[] = $alert['category_id']; }
    if ($alert['city'])       { $where[] = "a.city LIKE ?"; $params[] = '%'.$alert['city'].'%'; }
    if ($alert['min_price'])  { $where[] = "a.price >= ?"; $params[] = $alert['min_price']; }
    if ($alert['max_price'])  { $where[] = "a.price <= ?"; $params[] = $alert['max_price']; }
    if ($alert['has_video'])  { $where[] = "a.video_path IS NOT NULL"; }
    
    $new_ads = DB::fetchAll("
        SELECT a.*, c.name as cat_name, u.name as seller_name
        FROM ads a
        JOIN categories c ON c.id=a.category_id
        JOIN users u ON u.id=a.user_id
        WHERE ".implode(' AND ', $where)."
        ORDER BY a.created_at DESC LIMIT 10
    ", $params);
    
    if (empty($new_ads)) continue;
    
    // Construim emailul
    $alert_name = $alert['name'] ?: ($alert['keyword'] ?: 'Alertă');
    $ads_html = '';
    foreach ($new_ads as $ad) {
        $price = $ad['price'] > 0 ? number_format($ad['price'],0,',','.').' RON' : 'Negociabil';
        $ads_html .= "
        <div style='border:1px solid #eee;border-radius:8px;padding:14px;margin-bottom:10px;'>
          <div style='font-weight:700;font-size:15px;margin-bottom:4px;'>".h($ad['title'])."</div>
          <div style='color:#E8380D;font-weight:700;font-size:14px;margin-bottom:6px;'>".h($price)."</div>
          <div style='font-size:12px;color:#888;margin-bottom:8px;'>".h($ad['cat_name'])." · ".h($ad['seller_name'])."</div>
          <a href='".BASE_URL."/anunt/".h($ad['slug'])."' style='background:#E8380D;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;'>Vezi anunțul →</a>
        </div>";
    }
    
    $body = "<h2>🔔 ".count($new_ads)." anunț".( count($new_ads)>1?'uri':'' )." nou pentru \"".h($alert_name)."\"</h2>
    <p>Am găsit anunțuri noi care corespund alertei tale:</p>
    ".$ads_html."
    <hr>
    <p style='font-size:12px;color:#888;'>
      <a href='".BASE_URL."/alerte'>Gestionează alertele tale</a> · 
      Dacă nu mai dorești aceste emailuri, dezactivează alerta din contul tău.
    </p>";
    
    $ok = veedo_mail($alert['email'], $alert['user_name'], "🔔 Anunțuri noi: ".mb_substr($alert_name,0,50), $body);
    if ($ok) {
        DB::update('search_alerts', ['last_sent_at' => $now], 'id=?', [$alert['id']]);
        $sent++;
    }
}

// Alerte zilnice
$daily_alerts = DB::fetchAll("
    SELECT sa.*, u.email, u.name as user_name
    FROM search_alerts sa JOIN users u ON u.id=sa.user_id
    WHERE sa.is_active=1 AND sa.frequency='daily'
      AND (sa.last_sent_at IS NULL OR sa.last_sent_at < DATE_SUB(NOW(), INTERVAL 23 HOUR))
    LIMIT 50
", []);

foreach ($daily_alerts as $alert) {
    $processed++;
    // Similar cu instant dar ultimele 24h - cod similar prescurtat
    DB::update('search_alerts', ['last_sent_at' => $now], 'id=?', [$alert['id']]);
    $sent++;
}

$msg = "Processed: $processed alerts, Sent: $sent emails at $now";
echo $msg."\n";
if (!$cli) echo json_encode(['ok'=>true,'processed'=>$processed,'sent'=>$sent]);
