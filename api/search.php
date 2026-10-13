<?php
// api/search.php - autocomplete search
require_once __DIR__ . '/../includes/bootstrap.php';
$q = sanitize($_GET['q'] ?? '');
if (strlen($q) < 2) json_response(['results' => []]);

$results = DB::fetchAll("
    SELECT a.title, a.slug, a.price, a.currency
    FROM ads a
    WHERE a.status='active' AND (a.title LIKE ? OR MATCH(a.title,a.description) AGAINST(? IN BOOLEAN MODE))
    ORDER BY a.is_boosted DESC, a.views_count DESC
    LIMIT 8
", ['%' . $q . '%', $q . '*']);

foreach ($results as &$r) {
    $r['price'] = $r['price'] ? format_price((float)$r['price'], $r['currency']) : 'Preț negociabil';
}
json_response(['results' => $results]);
