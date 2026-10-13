<?php
// api/category-fields.php - returns fields for a category (AJAX)
require_once __DIR__ . '/../includes/bootstrap.php';

$cat_id = (int)($_GET['cat_id'] ?? 0);
if (!$cat_id) json_response(['fields' => []]);

$fields = DB::fetchAll("SELECT * FROM category_fields WHERE category_id=? AND is_active=1 ORDER BY sort_order", [$cat_id]);
json_response(['fields' => $fields]);
