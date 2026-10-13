<?php
// ============================================
// VEEDO - Helper Functions
// ============================================

// ---- Settings cache ----
$_settings_cache = null;
function setting(string $key, string $default = ''): string {
    global $_settings_cache;
    if ($_settings_cache === null) {
        $rows = DB::fetchAll("SELECT setting_key, setting_value FROM settings");
        $_settings_cache = array_column($rows, 'setting_value', 'setting_key');
    }
    return $_settings_cache[$key] ?? $default;
}

function setting_bool(string $key): bool {
    return (bool)(int)setting($key);
}

function setting_update(string $key, string $value): void {
    global $_settings_cache;
    DB::query("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?", [$key, $value, $value]);
    if ($_settings_cache !== null) $_settings_cache[$key] = $value;
}

// ---- Auth ----
function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $u = null;
    if ($u === null) {
        $u = DB::fetch("SELECT id,name,email,role,avatar,is_verified_business,theme_preference FROM users WHERE id=? AND is_active=1", [$_SESSION['user_id']]);
    }
    return $u;
}

function is_logged(): bool { return isset($_SESSION['user_id']); }
function is_admin(): bool  { return (current_user()['role'] ?? '') === 'admin'; }
function is_moderator(): bool { return in_array(current_user()['role'] ?? '', ['admin','moderator']); }

function require_login(): void {
    if (!is_logged()) {
        redirect('/login?next=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

function require_admin(): void {
    if (!is_admin()) {
        http_response_code(403);
        die(render_error(403, 'Acces interzis'));
    }
}

function login_user(int $id): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $id;
    DB::query("UPDATE users SET last_login=NOW() WHERE id=?", [$id]);
}

function logout_user(): void {
    session_destroy();
    setcookie(SESSION_NAME, '', time()-3600, '/');
    redirect('/');
}

// ---- CSRF ----
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

function csrf_get_param(): string {
    return 'csrf_token=' . urlencode($_SESSION['csrf_token'] ?? '');
}

function csrf_check_get(): bool {
    return isset($_GET['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token']);
}

// ---- XSS / Output ----
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function slug(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ț'=>'t','ş'=>'s','ţ'=>'t','é'=>'e','è'=>'e','ê'=>'e']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function unique_slug(string $title, string $table = 'ads', string $col = 'slug'): string {
    $base = slug($title);
    $slug = $base;
    $i = 1;
    while (DB::fetch("SELECT id FROM $table WHERE $col=?", [$slug])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function make_slug(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map = ['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ț'=>'t','ş'=>'s','ţ'=>'t','ä'=>'a','ö'=>'o','ü'=>'u'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    return substr($text, 0, 80);
}

// ---- Redirect ----
function redirect(string $url, int $code = 302): never {
    header("Location: $url", true, $code);
    exit;
}

// ---- Pagination (SQL-based, used in index.php and most pages) ----
function paginate(string $sql, string $countSql, array $params, int $perPage = 20): array {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)DB::fetch($countSql, $params)['total'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $items = DB::fetchAll($sql . " LIMIT $perPage OFFSET $offset", $params);
    return compact('items','total','pages','page','perPage');
}

// ---- Pagination helper (count-based, used with render_pagination) ----
function paginate_count(int $total, int $per_page, int $current_page, string $base_url): array {
    $total_pages = max(1, (int)ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    $offset = ($current_page - 1) * $per_page;
    return [
        'total'       => $total,
        'per_page'    => $per_page,
        'current'     => $current_page,
        'total_pages' => $total_pages,
        'offset'      => $offset,
        'has_prev'    => $current_page > 1,
        'has_next'    => $current_page < $total_pages,
        'prev'        => $current_page - 1,
        'next'        => $current_page + 1,
        'base_url'    => $base_url,
    ];
}

// ---- Render pagination HTML ----
function render_pagination(array $p): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<div style="display:flex;justify-content:center;align-items:center;gap:6px;padding:24px 0;flex-wrap:wrap;">';
    $sep = strpos($p['base_url'], '?') !== false ? '&' : '?';

    if ($p['has_prev']) {
        $html .= '<a href="'.$p['base_url'].$sep.'pagina='.$p['prev'].'" class="btn btn-secondary btn-sm">← Anterior</a>';
    }

    $start = max(1, $p['current'] - 2);
    $end   = min($p['total_pages'], $p['current'] + 2);

    if ($start > 1) $html .= '<span style="color:var(--text-3);padding:0 4px;">1</span><span style="color:var(--text-3);">...</span>';
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $p['current'];
        $html .= '<a href="'.$p['base_url'].$sep.'pagina='.$i.'" class="btn '.($active?'btn-primary':'btn-secondary').' btn-sm" style="min-width:36px;justify-content:center;">'.$i.'</a>';
    }
    if ($end < $p['total_pages']) $html .= '<span style="color:var(--text-3);">...</span><span style="color:var(--text-3);padding:0 4px;">'.$p['total_pages'].'</span>';

    if ($p['has_next']) {
        $html .= '<a href="'.$p['base_url'].$sep.'pagina='.$p['next'].'" class="btn btn-secondary btn-sm">Următor →</a>';
    }
    $html .= '</div>';
    return $html;
}

// ---- SEO meta tags ----
function seo_tags(string $title='', string $desc='', string $image='', string $url='', string $type='website'): string {
    $siteName = setting('site_name','Veedo');
    $defDesc  = setting('site_description');
    $defImg   = setting('og_image');
    $t   = $title ? h($title) . ' | ' . h($siteName) : h($siteName) . ' - ' . h(setting('site_tagline'));
    $d   = h($desc  ?: $defDesc);
    $img = h($image ?: ($defImg ?: BASE_URL . '/assets/og-default.jpg'));
    $u   = h($url   ?: BASE_URL . $_SERVER['REQUEST_URI']);
    $robots = setting('meta_robots','index, follow');
    $out = <<<HTML
<title>{$t}</title>
<meta name="description" content="{$d}">
<meta name="robots" content="{$robots}">
<link rel="canonical" href="{$u}">
<meta property="og:type" content="{$type}">
<meta property="og:title" content="{$t}">
<meta property="og:description" content="{$d}">
<meta property="og:image" content="{$img}">
<meta property="og:url" content="{$u}">
<meta property="og:site_name" content="{$siteName}">
<meta property="og:locale" content="ro_RO">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$t}">
<meta name="twitter:description" content="{$d}">
<meta name="twitter:image" content="{$img}">
HTML;
    return $out;
}

function seo_breadcrumb(array $items): string {
    $list = [];
    foreach ($items as $i => $item) {
        $list[] = '{"@type":"ListItem","position":'.($i+1).',"name":"'.addslashes($item['name']).'","item":"'.addslashes($item['url'] ?? '').'" }';
    }
    return '<script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":['.implode(',', $list).']}</script>';
}

// ---- Format helpers ----
// format_price: returns 'Negociabil' for price <= 0
function format_price(float $price, string $currency = 'RON'): string {
    if ($price <= 0) return 'Negociabil';
    return number_format($price, 0, ',', '.') . ' ' . $currency;
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes/1024, 0) . ' KB';
    return $bytes . ' B';
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'acum câteva secunde';
    if ($diff < 3600)   return 'acum ' . floor($diff/60) . ' min';
    if ($diff < 86400)  return 'acum ' . floor($diff/3600) . ' ore';
    if ($diff < 604800) return 'acum ' . floor($diff/86400) . ' zile';
    return date('d.m.Y', strtotime($datetime));
}

function format_duration(int $seconds): string {
    $m = floor($seconds/60);
    $s = $seconds % 60;
    return sprintf('%d:%02d', $m, $s);
}

function truncate(string $text, int $len = 100, string $suffix = '...'): string {
    if (mb_strlen($text) <= $len) return $text;
    return mb_substr($text, 0, $len) . $suffix;
}

// ---- URL / Media helpers ----
function avatar_url(?string $path, string $name = ''): string {
    if ($path) return BASE_URL . '/uploads/avatars/' . $path;
    return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=FF3B30&color=fff&size=100';
}

function thumb_url(?string $path): string {
    if (!$path) return BASE_URL . '/assets/no-video.jpg';
    return BASE_URL . '/uploads/thumbs/' . $path;
}

function photo_url(?string $path): string {
    if (!$path) return BASE_URL . '/assets/no-photo.jpg';
    return BASE_URL . '/uploads/photos/' . $path;
}

function og_image(string $photo = '', string $category_icon = ''): string {
    if ($photo) return BASE_URL . '/uploads/photos/' . $photo;
    return BASE_URL . '/css/og-default.png';
}

// ---- Check if ad is boosted and active ----
function is_boosted(array $ad): bool {
    return !empty($ad['is_boosted']) &&
           (!empty($ad['boosted_until']) && strtotime($ad['boosted_until']) > time());
}

// ---- Notifications ----

/**
 * Fixat: folosește user_id (snake_case) consistent, nu mixt camelCase+snake_case.
 */
function add_notification(int $user_id, string $type, string $title, string $body = '', string $link = ''): void {
    DB::insert('notifications', compact('user_id', 'type', 'title', 'body', 'link'));
}

/**
 * Număr notificări necitite — cu cache în sesiune (TTL 60s).
 * Înlocuiește query DB la fiecare request cu un refresh o dată pe minut.
 */
function unread_notifications(int $userId): int {
    $key = 'uns_' . $userId;
    $at  = 'uns_at_' . $userId;
    if (!isset($_SESSION[$key]) || (time() - ($_SESSION[$at] ?? 0)) > 60) {
        $_SESSION[$key] = (int)(DB::fetch(
            "SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0",
            [$userId]
        )['c'] ?? 0);
        $_SESSION[$at] = time();
    }
    return $_SESSION[$key];
}

/**
 * Număr mesaje necitite — cu cache în sesiune (TTL 60s).
 * Apelat din header.php în loc de query inline.
 */
function unread_messages(int $userId): int {
    $key = 'ums_' . $userId;
    $at  = 'ums_at_' . $userId;
    if (!isset($_SESSION[$key]) || (time() - ($_SESSION[$at] ?? 0)) > 60) {
        $row = DB::fetch(
            "SELECT COALESCE(SUM(CASE WHEN buyer_id=? THEN buyer_unread ELSE seller_unread END), 0) AS c
             FROM conversations WHERE buyer_id=? OR seller_id=?",
            [$userId, $userId, $userId]
        );
        $_SESSION[$key] = (int)($row['c'] ?? 0);
        $_SESSION[$at]  = time();
    }
    return $_SESSION[$key];
}

/**
 * Invalidează cache-ul de mesaje/notificări pentru un user (apelează după trimitere mesaj).
 */
function invalidate_unread_cache(int $userId): void {
    unset(
        $_SESSION['uns_' . $userId], $_SESSION['uns_at_' . $userId],
        $_SESSION['ums_' . $userId], $_SESSION['ums_at_' . $userId]
    );
}

// ---- Error pages ----
function render_error(int $code, string $msg): string {
    http_response_code($code);
    return "<html><body style='font-family:sans-serif;text-align:center;padding:80px'><h1>$code</h1><p>$msg</p><a href='/'>← Acasă</a></body></html>";
}

// ---- Admin log ----
function admin_log(string $action, string $targetType='', int $targetId=0, string $details=''): void {
    $uid = $_SESSION['user_id'] ?? 0;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    DB::query("INSERT INTO admin_log (user_id,action,target_type,target_id,details,ip) VALUES (?,?,?,?,?,?)",
        [$uid,$action,$targetType,$targetId,$details,$ip]);
}

// ---- Flash messages ----
function flash(string $key, string $msg): void { $_SESSION['flash'][$key] = $msg; }

/* Etichetă „Reactualizat" în stil OLX: „azi la 13:19" / „ieri la 09:40" / „14.05.2026". */
function reactualizat_text(string $dt): string {
    $ts = @strtotime($dt);
    if (!$ts) return '';
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d'))                      return 'azi la '  . date('H:i', $ts);
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'ieri la ' . date('H:i', $ts);
    return date('d.m.Y', $ts);
}

function get_flash(string $key): string {
    $m = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $m;
}

// ---- Sanitize ----


// ---- Video helpers - compatibil shared hosting ----

/**
 * Găsește binarele ffmpeg/ffprobe în locații cunoscute.
 * is_executable() returnează false din PHP pe shared hosting → fallback file_exists().
 */
function find_ffmpeg_binary(string $bin = 'ffmpeg'): ?string {
    $paths = [
        '/usr/bin/'       . $bin,
        '/usr/local/bin/' . $bin,
        '/home/veedo1/bin/' . $bin,  // binar static instalat manual
    ];
    foreach ($paths as $p) {
        if (is_executable($p) || (file_exists($p) && is_file($p))) return $p;
    }
    return null;
}

/**
 * Generează thumbnail pentru video.
 * Pe shared hosting ffmpeg poate lipsi — returnează false.
 * Thumb-ul va fi generat côté client via canvas JS (captureVideoThumb).
 */
function generate_video_thumb(string $video_path, string $thumb_path): bool {
    $ffmpeg = find_ffmpeg_binary('ffmpeg');
    if (!$ffmpeg) return false;

    // Încearcă la secunda 0, 1 și 2 până reușește
    foreach (['00:00:00', '00:00:01', '00:00:02'] as $ts) {
        // -threads 1: OBLIGATORIU pe shared hosting (evită ff_frame_thread_encoder_init failed)
        @exec($ffmpeg
            . ' -ss ' . $ts
            . ' -i ' . escapeshellarg($video_path)
            . ' -vframes 1 -q:v 2 -threads 1 -y '
            . escapeshellarg($thumb_path)
            . ' 2>/dev/null');
        if (file_exists($thumb_path) && filesize($thumb_path) > 0) return true;
    }
    return false;
}

/**
 * Comprimă video cu FFmpeg (server-side).
 * -threads 1: OBLIGATORIU pe shared hosting.
 * -preset fast: viteză bună fără a suprasolicita CPU-ul shared.
 * -movflags +faststart: streaming web optimizat (moov atom la început).
 */
function compress_video_ffmpeg(string $input_path, string $output_path, int $max_width = 1080): bool {
    $ffmpeg = find_ffmpeg_binary('ffmpeg');
    if (!$ffmpeg) {
        error_log("[veedo video] compress_video_ffmpeg: ffmpeg binary not found");
        return false;
    }

    @set_time_limit(300);

    // Scale: limiteaza latura cea mai lunga la $max_width, fara referinte circulare.
    // -2 = "auto, divizibil cu 2" (cerinta codec H.264). min() previne marirea videourilor mici.
    $scale = "scale='if(gt(iw,ih),min({$max_width},iw),-2)':'if(gt(iw,ih),-2,min({$max_width},ih))'";

    // Base command — testat pe shared hosting (DirectAdmin, limita threaduri OS).
    // - OMP_NUM_THREADS=1: previne thread-urile OpenMP din libx264
    // - -threads/filter_threads/filter_complex_threads 1: serializeaza filtrele si codec-ul
    // - fara -movflags +faststart: muxer-ul MP4 face second pass care creeaza thread-uri (Resource temporarily unavailable)
    $base = 'OMP_NUM_THREADS=1 ' . $ffmpeg
        . ' -i '   . escapeshellarg($input_path)
        . ' -vf '  . escapeshellarg($scale)
        . ' -c:v libx264 -crf 28 -preset fast'
        . ' -threads 1 -filter_threads 1 -filter_complex_threads 1';

    // Pas 1: copiaza audio (cel mai usor — fara AAC encoder care esueaza pe limita threaduri)
    $cmd1 = $base . ' -c:a copy -y ' . escapeshellarg($output_path) . ' 2>&1';
    @exec($cmd1, $out1, $ret1);
    $ok = ($ret1 === 0 && file_exists($output_path) && filesize($output_path) > 0);
    if ($ok) return true;

    // Pas 2 (fallback): audio incompatibil cu MP4 — re-codifica cu AAC bitrate mic
    @unlink($output_path);
    $cmd2 = $base . ' -c:a aac -b:a 96k -ac 2 -y ' . escapeshellarg($output_path) . ' 2>&1';
    @exec($cmd2, $out2, $ret2);
    $ok = ($ret2 === 0 && file_exists($output_path) && filesize($output_path) > 0);

    if (!$ok) {
        $err = substr(implode(' | ', array_slice(array_merge($out1, ['---FB---'], $out2 ?? []), -10)), 0, 1500);
        error_log("[veedo video] compress_video_ffmpeg FAIL ret={$ret1}/{$ret2}: {$err}");
    }

    return $ok;
}

function get_video_duration(string $video_path): int {
    $ffprobe = find_ffmpeg_binary('ffprobe');
    if (!$ffprobe) return 0;

    $dur = @shell_exec($ffprobe
        . ' -v error -show_entries format=duration'
        . ' -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($video_path)
        . ' 2>/dev/null');
    return $dur ? (int)$dur : 0;
}

// ---- Rate Limiting (optimizat pentru shared hosting) ----
function rate_limit(string $action, int $max_requests = 10, int $window_seconds = 60, ?int $user_id = null): bool {
    $ip  = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $uid = $user_id ?? ($_SESSION['user_id'] ?? null);
    $key = $action . ':' . $ip . ':' . ($uid ?? 'guest');

    // Preferă APCu (in-memory, rapid) dacă e disponibil pe server
    if (function_exists('apcu_fetch')) {
        $data = apcu_fetch($key, $success);
        if (!$success) {
            apcu_store($key, 1, $window_seconds);
            return true;
        }
        if ((int)$data >= $max_requests) return false;
        apcu_inc($key);
        return true;
    }

    // Fallback: baza de date (shared hosting fără APCu)
    try {
        // Curăță RAR (1% din request-uri) pentru a nu suprasolicita DB-ul
        if (mt_rand(1, 100) === 1) {
            DB::query("DELETE FROM rate_limits WHERE expires_at < NOW()");
        }

        $row = DB::fetch("SELECT hits FROM rate_limits WHERE rate_key=? AND expires_at > NOW()", [$key]);

        if (!$row) {
            DB::query(
                "INSERT INTO rate_limits (rate_key, hits, expires_at) VALUES (?,1,DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE hits=IF(expires_at < NOW(), 1, hits+1), expires_at=IF(expires_at < NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), expires_at)",
                [$key, $window_seconds, $window_seconds]
            );
            return true;
        }

        if ((int)$row['hits'] >= $max_requests) return false;

        DB::query("UPDATE rate_limits SET hits=hits+1 WHERE rate_key=?", [$key]);
        return true;
    } catch (Exception $e) {
        return true; // Dacă tabelul nu există încă, nu bloca
    }
}

function rate_limit_or_die(string $action, int $max = 10, int $window = 60): void {
    if (!rate_limit($action, $max, $window)) {
        http_response_code(429);
        // Intotdeauna JSON pentru API-uri (evita alert 'Eroare' in browser)
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Prea multe cereri. Încearcă din nou mai târziu.', 'retry_after' => $window]);
        exit;
    }
}

function sanitize(string $s): string { return trim(strip_tags($s)); }
function sanitize_html(string $s): string {
    return strip_tags(trim($s), '<p><br><b><strong><i><em><ul><ol><li><h2><h3>');
}

// ---- Upload helpers ----

/**
 * Verifică MIME-ul REAL al fișierului (nu cel declarat de browser)
 * Previne upload de fișiere PHP/malware deghizate ca imagini
 */
function real_mime(string $tmp_path): string {
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $tmp_path);
        finfo_close($fi);
        return $mime ?: '';
    }
    return mime_content_type($tmp_path) ?: '';
}

function allowed_video_type(string $mime): bool {
    // Verificăm MIME-ul real, nu cel declarat de browser
    return in_array($mime, ['video/mp4','video/webm','video/ogg','video/quicktime']);
}

function upload_photo(array $file, string $destDir): ?string {
    // 1. Verifică că fișierul e un upload valid
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;

    // 2. Verifică MIME-ul REAL (nu cel declarat de browser - poate fi fals!)
    $real_mime = real_mime($file['tmp_name']);
    $allowed_mimes = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($real_mime, $allowed_mimes)) return null;

    // 3. Verifică că e o imagine validă (anti-polyglot attack)
    $img_info = @getimagesize($file['tmp_name']);
    if (!$img_info) return null;

    // 4. Mapare MIME -> extensie sigură (nu folosi extensia din filename!)
    $ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $ext = $ext_map[$real_mime] ?? 'jpg';

    // 5. Nume complet aleator - nu păstrăm nimic din filename original
    $name = bin2hex(random_bytes(16)) . '.' . $ext;

    // 6. Mută fișierul
    if (move_uploaded_file($file['tmp_name'], $destDir . $name)) return $name;
    return null;
}

/**
 * Verifică videoul - MIME real + extensie sigură
 */
function upload_video_secure(array $file, string $destDir): ?string {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;

    $real_mime = real_mime($file['tmp_name']);
    if (!allowed_video_type($real_mime)) return null;

    $ext_map = ['video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv','video/quicktime'=>'mov'];
    $ext  = $ext_map[$real_mime] ?? 'mp4';
    $name = bin2hex(random_bytes(16)) . '.' . $ext;

    if (move_uploaded_file($file['tmp_name'], $destDir . $name)) return $name;
    return null;
}

// ---- JSON response (for AJAX/API) ----
function json_response(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Phone masking ----
function maskPhone(string $phone): string {
    $clean = preg_replace('/\s+/', '', $phone);
    $len = strlen($clean);
    if ($len < 6) return str_repeat('*', $len);
    $visible_start = 4;
    $visible_end   = 2;
    $masked_len    = $len - $visible_start - $visible_end;
    return substr($clean, 0, $visible_start)
         . str_repeat('*', max(0, $masked_len))
         . substr($clean, -$visible_end);
}

// ---- URL validation ----
function is_valid_url(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// ---- Generate unique affiliate code ----
function generate_affiliate_code(string $name): string {
    $base = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 6));
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    return $base . $suffix;
}