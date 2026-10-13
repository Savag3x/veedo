<?php
// cont/favorite.php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$uid = current_user()['id'];

$ads = DB::fetchAll("
    SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) AS main_photo
    FROM favorites f
    JOIN ads a ON a.id=f.ad_id
    JOIN categories c ON c.id=a.category_id
    JOIN users u ON u.id=a.user_id
    WHERE f.user_id=? AND a.status='active'
    ORDER BY f.created_at DESC
", [$uid]);

$seo_title = 'Favorite';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding:28px 20px;">
  <h1 style="margin-bottom:24px;">❤️ Anunțuri favorite</h1>

  <?php if (empty($ads)): ?>
    <div style="text-align:center;padding:80px 20px;color:var(--text-3);">
      <div style="font-size:3rem;margin-bottom:12px;">❤️</div>
      <h3>Niciun anunț salvat</h3>
      <p style="margin-top:8px;">Apasă ❤️ pe un anunț pentru a-l salva la favorite.</p>
      <a href="/" class="btn btn-primary mt-3">Explorează anunțuri</a>
    </div>
  <?php else: ?>
    <p style="color:var(--text-3);margin-bottom:20px;"><?= count($ads) ?> anunțuri salvate</p>
    <div class="items-grid">
      <?php foreach ($ads as $ad): include __DIR__ . '/../includes/ad-card.php'; endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
