<?php
// api/theme.php - saves user theme preference
require_once __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);
$data = request_json();
$theme = in_array($data['theme'] ?? '', ['light','dark','auto']) ? $data['theme'] : 'light';
if (is_logged()) {
    DB::query("UPDATE users SET theme_preference=? WHERE id=?", [$theme, current_user()['id']]);
}
json_response(['success' => true]);
