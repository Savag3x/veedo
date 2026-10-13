<?php
// ============================================
// VEEDO.RO - Configuration
// ============================================

// ---- Încarcă .env ----
// Pe DirectAdmin shared hosting: .env se pune DEASUPRA public_html
// Ex: /home/gestiuneflota/.env (NU în /home/gestiuneflota/domains/veedo.ro/public_html/)
// Asta îl face inaccesibil din browser chiar fără .htaccess
function _find_env(): ?string {
    // Caută .env în directorul curent, părinte și bunic (deasupra public_html)
    $dirs = [
        dirname(__DIR__),                    // rădăcina site-ului (public_html)
        dirname(dirname(__DIR__)),           // un nivel deasupra (domains/veedo.ro/)
        dirname(dirname(dirname(__DIR__))),  // două nivele (home/user/) - IDEAL pe DA
    ];
    foreach ($dirs as $dir) {
        $f = $dir . '/.env';
        if (file_exists($f) && is_readable($f)) return $f;
    }
    return null;
}

$_env_file = _find_env();
if ($_env_file) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
function _env(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : trim((string)$v, " \t\"'");
}

define('VEEDO_VERSION', '1.0.0');
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', _env('BASE_URL', 'https://veedo.gestiuneflota.ro'));

// ---- Database (citite din .env, nu hardcodate!) ----
define('DB_HOST',    _env('DB_HOST', 'localhost'));
define('DB_NAME',    _env('DB_NAME', 'gestiuneflota_veedo'));
define('DB_USER',    _env('DB_USER', 'gestiuneflota_veedo'));
define('DB_PASS',    _env('DB_PASS', ''));          // <-- Fără valoare default!
define('DB_CHARSET', 'utf8mb4');

// ---- Upload paths ----
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('UPLOAD_URL',  BASE_URL . '/uploads/');
define('VIDEO_PATH',  UPLOAD_PATH . 'videos/');
define('THUMB_PATH',  UPLOAD_PATH . 'thumbs/');
define('PHOTO_PATH',  UPLOAD_PATH . 'photos/');
define('AVATAR_PATH', UPLOAD_PATH . 'avatars/');

// ---- Session ----
define('SESSION_NAME',     'veedo_sess');
define('SESSION_LIFETIME', 86400 * 30);

// ---- Security ----
define('CSRF_TOKEN_LENGTH',    32);
define('PASSWORD_MIN_LENGTH',  8);
define('MAX_LOGIN_ATTEMPTS',   5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// ---- Web Push VAPID ----
define('VAPID_PUBLIC_KEY',  _env('VAPID_PUBLIC_KEY', ''));
define('VAPID_PRIVATE_KEY', _env('VAPID_PRIVATE_KEY', ''));

// ---- Timezone ----
date_default_timezone_set('Europe/Bucharest');

// ---- Environment ----
define('APP_ENV', _env('APP_ENV', 'production'));
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Erorile NU se afișează vizitatorilor, dar se scriu în log (înainte error_reporting(0) le ascundea complet)
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// ---- Asset versioning ----
// filemtime() face un syscall disk la fiecare request — calculăm o singură dată la bootstrap
define('CSS_VER', @filemtime(BASE_PATH . '/css/style.css') ?: VEEDO_VERSION);
