<?php
// public/sitemap.php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$base = BASE_URL;
$ads = DB::fetchAll("SELECT slug, updated_at FROM ads WHERE status='active' ORDER BY updated_at DESC LIMIT 5000");
$cats = DB::fetchAll("SELECT slug FROM categories WHERE is_active=1");
$pages = DB::fetchAll("SELECT slug FROM pages WHERE is_active=1");
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc><?= h($base) ?>/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>
  <url><loc><?= h($base) ?>/anunturi</loc><changefreq>hourly</changefreq><priority>0.9</priority></url>
  <?php foreach ($cats as $c): ?>
  <url><loc><?= h($base) ?>/categorie/<?= h($c['slug']) ?></loc><changefreq>daily</changefreq><priority>0.8</priority></url>
  <?php endforeach; ?>
  <?php foreach ($ads as $a): ?>
  <url>
    <loc><?= h($base) ?>/anunt/<?= h($a['slug']) ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($a['updated_at'])) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
  </url>
  <?php endforeach; ?>
  <?php foreach ($pages as $p): ?>
  <url><loc><?= h($base) ?>/pagina/<?= h($p['slug']) ?></loc><changefreq>monthly</changefreq><priority>0.5</priority></url>
  <?php endforeach; ?>
</urlset>
