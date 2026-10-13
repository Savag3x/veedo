<?php
// ============================================
// VEEDO - Securitate centralizată
// - Headere HTTP de securitate
// - Protecție CSRF globală pentru toate cererile care modifică date
// - Validatori pentru URL-uri, telefoane, redirect-uri și endpoint-uri push
// ============================================

/**
 * Endpoint-uri apelate de servicii externe (fără sesiune/CSRF),
 * protejate prin semnătură proprie (Stripe/Netopia) sau CLI.
 */
const CSRF_EXEMPT_PATHS = [
    '/api/stripe-webhook.php', '/api/stripe-webhook',
    '/api/netopia-ipn.php',    '/api/netopia-ipn',
];

function send_security_headers(): void {
    if (headers_sent() || PHP_SAPI === 'cli') return;

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=(self), payment=(self "https://js.stripe.com")');

    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // CSP: site-ul folosește încă scripturi inline, deci păstrăm 'unsafe-inline' pentru script-src,
    // dar blocăm plugin-uri, <base> injectat, framing extern și form-uri trimise către domenii străine.
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://js.stripe.com https://www.googletagmanager.com https://www.google-analytics.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' data: https://fonts.gstatic.com",
        "img-src 'self' data: blob: https:",
        "media-src 'self' blob: https:",
        "connect-src 'self' https://api.stripe.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com",
        "frame-src 'self' https://js.stripe.com https://hooks.stripe.com https://www.youtube.com https://player.twitch.tv",
        "worker-src 'self'",
        "manifest-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self' https://secure.mobilpay.ro https://sandboxsecure.mobilpay.ro",
        "frame-ancestors 'self'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

function is_https_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// ---- CSRF ----

/** JSON body decodat o singură dată și reutilizat (php://input se poate citi de mai multe ori, dar evităm munca dublă). */
function request_json(): array {
    static $json = null;
    if ($json === null) {
        $json = [];
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($ct, 'multipart/form-data') && !str_contains($ct, 'application/x-www-form-urlencoded')) {
            $raw = file_get_contents('php://input');
            if ($raw !== '' && $raw !== false && strlen($raw) < 1048576) {
                $d = json_decode($raw, true);
                if (is_array($d)) $json = $d;
            }
        }
    }
    return $json;
}

function csrf_request_token(): string {
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) return $_POST['csrf_token'];
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    $j = request_json();
    if (isset($j['csrf_token']) && is_string($j['csrf_token'])) return $j['csrf_token'];
    if (isset($_GET['csrf_token']) && is_string($_GET['csrf_token'])) return $_GET['csrf_token'];
    return '';
}

function csrf_request_valid(): bool {
    $sess = $_SESSION['csrf_token'] ?? '';
    $tok  = csrf_request_token();
    return $sess !== '' && $tok !== '' && hash_equals($sess, $tok);
}

/** Cererea vine de pe alt domeniu? (verificare Origin/Referer ca strat suplimentar) */
function request_is_cross_origin(): bool {
    $src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($src === '' || $src === 'null') return false;
    $host = parse_url($src, PHP_URL_HOST);
    $self = $_SERVER['HTTP_HOST'] ?? parse_url(BASE_URL, PHP_URL_HOST);
    $self = preg_replace('/:\d+$/', '', (string)$self);
    return $host && strcasecmp($host, $self) !== 0 && strcasecmp($host, (string)parse_url(BASE_URL, PHP_URL_HOST)) !== 0;
}

function is_api_request(): bool {
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return str_starts_with($path, '/api/')
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
}

/**
 * Orice cerere POST/PUT/PATCH/DELETE trebuie să conțină un token CSRF valid
 * (în câmpul csrf_token, header-ul X-CSRF-Token sau în body-ul JSON).
 */
function enforce_csrf(): void {
    if (PHP_SAPI === 'cli' || defined('CRON_MODE')) return;
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    if (in_array($path, CSRF_EXEMPT_PATHS, true)) return;

    if (!request_is_cross_origin() && csrf_request_valid()) return;

    // Upload mai mare decât post_max_size: PHP golește $_POST, deci tokenul lipsește.
    $too_large = empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $msg = $too_large
        ? 'Fișierul trimis este prea mare. Încearcă un fișier mai mic.'
        : 'Sesiunea a expirat sau cererea nu este validă. Reîncarcă pagina și încearcă din nou.';

    if (is_api_request()) {
        http_response_code($too_large ? 413 : 403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'ok' => false, 'error' => $msg, 'csrf' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('error', $msg);
    redirect(safe_back_url('/'), 303);
}

// ---- Validatori ----

/** Returnează URL-ul doar dacă e http(s) absolut (sau cale relativă sigură, dacă e permis). */
function safe_url(?string $url, bool $allow_relative = false): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if ($allow_relative && preg_match('~^/(?![/\\\\])~', $url) && !preg_match('/[\x00-\x1f]/', $url)) return $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

/** Cale internă sigură pentru redirect (previne open redirect: //evil.com, /\evil.com, javascript:) */
function safe_redirect_path(?string $path, string $default = '/'): string {
    $path = (string)$path;
    if ($path === '' || $path[0] !== '/' || preg_match('~^/[/\\\\]~', $path) || preg_match('/[\x00-\x1f\\\\]/', $path)) {
        return $default;
    }
    return $path;
}

/** Referer de pe același domeniu, convertit în cale relativă. */
function safe_back_url(string $default = '/'): string {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!$ref || request_is_cross_origin()) return $default;
    $p = parse_url($ref);
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    return safe_redirect_path($path, $default);
}

/** Normalizează un număr de telefon: păstrează doar cifre, +, spații, -, (, ). */
function clean_phone(?string $phone): string {
    $phone = preg_replace('/[^0-9+\-\s().]/', '', (string)$phone);
    $phone = trim(preg_replace('/\s+/', ' ', $phone));
    return mb_substr($phone, 0, 20);
}

/** Culoare hex validă (#RGB / #RRGGBB) sau valoarea implicită. */
function safe_color(?string $c, string $default = '#888888'): string {
    $c = trim((string)$c);
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c) ? $c : $default;
}

/** Elimină caractere de control (CR/LF) - previne header injection în emailuri. */
function no_crlf(string $s): string {
    return trim(preg_replace('/[\r\n\t\x00-\x1f]+/', ' ', $s));
}

/**
 * Endpoint Web Push valid: doar HTTPS către servicii push cunoscute.
 * Previne SSRF (serverul ar face POST către orice URL trimis de client).
 */
function is_valid_push_endpoint(string $endpoint): bool {
    if (strlen($endpoint) > 1000 || !filter_var($endpoint, FILTER_VALIDATE_URL)) return false;
    if (strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') return false;
    $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
    $allowed = [
        'fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com',
        'web.push.apple.com', 'push.apple.com', 'notify.windows.com', 'push.services.mozilla.com',
    ];
    foreach ($allowed as $a) {
        if ($host === $a || str_ends_with($host, '.' . $a)) return true;
    }
    return false;
}

/**
 * Salvează un fișier uploadat (imagine sau video) verificând MIME-ul REAL.
 * Extensia se derivă din MIME, nu din numele trimis de client.
 * Returnează ['name'=>..., 'type'=>'photo'|'video'] sau null.
 */
function upload_media_secure(array $file, string $destDir, bool $allow_video = true): ?array {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    $mime = real_mime($file['tmp_name']);
    $img = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $vid = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];
    if (isset($img[$mime])) {
        if (!@getimagesize($file['tmp_name'])) return null;
        $ext = $img[$mime]; $type = 'photo';
    } elseif ($allow_video && isset($vid[$mime])) {
        $ext = $vid[$mime]; $type = 'video';
    } else {
        return null;
    }
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . $name)) return null;
    @chmod($destDir . $name, 0644);
    return ['name' => $name, 'type' => $type];
}
