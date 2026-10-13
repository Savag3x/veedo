<?php
// =========================================================
// VEEDO — PWA Web App Manifest
// =========================================================
// Servim ca application/manifest+json. Browser-ul îl folosește
// pentru "Add to Home Screen" / install prompt (Android + iOS).
// Dinamic: ia numele și descrierea din setări.
// =========================================================

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600'); // 1h — dacă schimbi setări, se va actualiza

$name       = setting('site_name', 'Veedo');
$short_name = mb_substr($name, 0, 12);
$desc       = setting('site_description', 'Anunțuri video. Simplu. Rapid.');

echo json_encode([
    'name'              => $name,
    'short_name'        => $short_name,
    'description'       => $desc,
    'start_url'         => '/?source=pwa',
    'scope'             => '/',
    'display'           => 'standalone',          // fără bara browser-ului → arată ca app nativă
    'orientation'       => 'portrait-primary',
    'background_color'  => '#FFFFFF',
    'theme_color'       => '#E8380D',             // ember brand color (pe bara de status)
    'lang'              => 'ro',
    'dir'               => 'ltr',
    'categories'        => ['shopping', 'social', 'lifestyle'],
    'icons' => [
        [
            'src'   => '/assets/icon-192.png',
            'sizes' => '192x192',
            'type'  => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'   => '/assets/icon-512.png',
            'sizes' => '512x512',
            'type'  => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'   => '/assets/icon-512-maskable.png',
            'sizes' => '512x512',
            'type'  => 'image/png',
            'purpose' => 'maskable',              // Android adaptive icons
        ],
        [
            'src'   => '/favicon.svg',
            'sizes' => 'any',
            'type'  => 'image/svg+xml',
            'purpose' => 'any',
        ],
    ],
    'shortcuts' => [
        [
            'name'        => 'Postează anunț',
            'short_name'  => 'Postează',
            'url'         => '/anunt-nou.php?source=pwa',
            'icons'       => [['src' => '/assets/icon-192.png', 'sizes' => '192x192']],
        ],
        [
            'name'        => 'Mesaje',
            'short_name'  => 'Mesaje',
            'url'         => '/mesaje.php?source=pwa',
            'icons'       => [['src' => '/assets/icon-192.png', 'sizes' => '192x192']],
        ],
        [
            'name'        => 'Explorează',
            'short_name'  => 'Feed',
            'url'         => '/explore.php?source=pwa',
            'icons'       => [['src' => '/assets/icon-192.png', 'sizes' => '192x192']],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
