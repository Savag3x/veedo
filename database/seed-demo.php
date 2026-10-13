<?php
// ============================================================
// VEEDO — Populează platforma cu date demo (rulare din linia de comandă)
//
//   php database/seed-demo.php                 șterge datele demo vechi și creează altele noi
//   php database/seed-demo.php --revive        + prelungește anunțurile reale expirate cu 60 de zile
//   php database/seed-demo.php --offline       nu descarcă poze, le generează local
//   php database/seed-demo.php --remove        doar șterge datele demo
//
// Alternativ, din browser: Admin → Date demo (/admin/demo-data.php).
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CRON_MODE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/seed/seeder.php';

$args = array_slice($argv, 1);
try {
    if (in_array('--remove', $args, true)) {
        echo demo_reset(), "\n";
        exit(0);
    }
    $log = demo_seed([
        'reset'  => true,
        'revive' => in_array('--revive', $args, true),
        'photos' => in_array('--offline', $args, true) ? 'generated' : 'auto',
    ]);
    echo implode("\n", $log), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Eroare: ' . $e->getMessage() . "\n");
    exit(1);
}
