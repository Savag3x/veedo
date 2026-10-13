<?php
// api/save-settings.php - AJAX endpoint pentru salvare setări
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_admin()) {
    json_response(['success' => false, 'error' => 'Acces interzis'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (!csrf_verify()) {
    json_response(['success' => false, 'error' => 'CSRF invalid'], 403);
}

try {
    $allowed = array_column(DB::fetchAll("SELECT setting_key FROM settings"), 'setting_key');
    $saved = 0;

    foreach ($_POST as $k => $v) {
        if ($k === 'csrf_token' || $k === 'active_tab') continue;
        if (in_array($k, $allowed)) {
            if (is_array($v)) continue;
            setting_update($k, admin_setting_value($k, (string)$v));
            $saved++;
        }
    }

    admin_log('settings_update', 'settings', 0, "Salvate $saved setări via AJAX");

    json_response(['success' => true, 'saved' => $saved]);

} catch (Exception $e) {
    error_log('[veedo] save-settings: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Eroare la salvare.'], 500);
}
