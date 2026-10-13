<?php
// api/theme.php - saves user theme preference
require_once __DIR__ . '/../includes/bootstrap.php';
$data = json_decode(file_get_contents('php://input'), true);
$theme = in_array($data['theme'] ?? '', ['light','dark','auto']) ? $data['theme'] : 'light';
if (is_logged()) {
    DB::query("UPDATE users SET theme_preference=? WHERE id=?", [$theme, current_user()['id']]);
}
json_response(['success' => true]);
