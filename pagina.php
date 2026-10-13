<?php
// public/pagina.php - renders static CMS pages
require_once __DIR__ . '/includes/bootstrap.php';

$slug = sanitize($_GET['slug'] ?? '');
$page = DB::fetch("SELECT * FROM pages WHERE slug=? AND is_active=1", [$slug]);

if (!$page) {
    http_response_code(404);
    $seo_title = 'Pagina nu a fost găsită';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container text-center mt-4" style="padding:60px 20px;"><h2>404 — Pagina nu există</h2><a href="/" class="btn btn-primary mt-2">← Acasă</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$seo_title = $page['meta_title'] ?: $page['title'];
$seo_desc  = $page['meta_description'];
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:40px 20px;">
  <h1 style="margin-bottom:24px;"><?= h($page['title']) ?></h1>
  <div style="font-size:15px;line-height:1.8;color:var(--text-2);">
    <?= $page['content'] ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
