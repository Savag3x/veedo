<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$u = current_user();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $ad_id = (int)($_POST['ad_id'] ?? 0);
    $ad = DB::fetch("SELECT id, slug FROM ads WHERE id=? AND user_id=?", [$ad_id, $u['id']]);
    if ($ad) {
        if ($_POST['action'] === 'delete') {
            DB::query("DELETE FROM ads WHERE id=?", [$ad_id]);
            DB::query("UPDATE users SET ads_count=GREATEST(0,ads_count-1) WHERE id=?", [$u['id']]);
            flash('success', 'Anunțul a fost șters.');
        } elseif ($_POST['action'] === 'mark_sold') {
            DB::query("UPDATE ads SET status='sold' WHERE id=?", [$ad_id]);
            flash('success', 'Anunțul a fost marcat ca vândut.');
        } elseif ($_POST['action'] === 'republish') {
            $days = (int)setting('anunt_expiry_days', '60');
            DB::query("UPDATE ads SET status='pending', expires_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?", [$days, $ad_id]);
            flash('success', 'Anunțul a fost retrimis spre aprobare.');
        }
    }
    redirect('/cont/anunturile-mele');
}

$status_filter = sanitize($_GET['status'] ?? 'all');
$where = "user_id=" . (int)$u['id'];
if ($status_filter !== 'all') $where .= " AND status='" . $status_filter . "'";
$ads = DB::fetchAll("SELECT a.*, c.name AS cat_name, c.icon AS cat_icon,
    (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) AS main_photo
    FROM ads a JOIN categories c ON c.id=a.category_id
    WHERE $where ORDER BY a.created_at DESC");

$seo_title = 'Anunțurile mele';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding:28px 20px;">

  <!-- Profile header -->
  <div class="profile-header">
    <img src="<?= h(avatar_url($u['avatar'], $u['name'])) ?>" class="profile-avatar" alt="">
    <div class="profile-info">
      <h2><?= h($u['name']) ?> <?= $u['is_verified_business'] ? '<span class="item-verified" style="font-size:14px;">✓</span>' : '' ?></h2>
      <div class="profile-meta">
        <span>📋 <?= $u['ads_count'] ?> anunțuri postate</span>
        <span>👤 <?= h($u['role']) ?></span>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;">
      <a href="/anunt/nou" class="btn btn-primary">+ Anunț nou</a>
      <a href="/cont/setari" class="btn btn-secondary">⚙️ Setări</a>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:0;border-bottom:1px solid var(--border-2);margin-bottom:24px;overflow-x:auto;">
    <a href="/cont/anunturile-mele" class="tab active">📋 Anunțurile mele</a>
    <a href="/cont/favorite" class="tab">❤️ Favorite</a>
    <a href="/cont/setari" class="tab">⚙️ Setări cont</a>
    <?php if (!$u['is_verified_business']): ?>
    <a href="/cont/verifica-business" class="tab">🏢 Devino business</a>
    <?php endif; ?>
  </div>

  <!-- Status filter -->
  <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (['all'=>'Toate','active'=>'Active','pending'=>'În așteptare','expired'=>'Expirate','sold'=>'Vândute','rejected'=>'Respinse'] as $s=>$lbl): ?>
      <a href="?status=<?= $s ?>" class="btn <?= $status_filter===$s?'btn-primary':'btn-secondary' ?> btn-sm"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($ads)): ?>
    <div style="text-align:center;padding:60px 20px;color:var(--text-3);">
      <div style="font-size:3rem;margin-bottom:12px;">📭</div>
      <h3>Niciun anunț</h3>
      <p style="margin-top:8px;"><a href="/anunt/nou">Postează primul tău anunț!</a></p>
    </div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($ads as $a): ?>
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:16px;display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
      <div style="width:80px;height:60px;border-radius:var(--radius-sm);overflow:hidden;flex-shrink:0;background:var(--bg-3);">
        <?php if ($a['video_thumb']): ?>
          <img src="<?= h(thumb_url($a['video_thumb'])) ?>" style="width:100%;height:100%;object-fit:cover;" alt="" loading="lazy">
        <?php elseif ($a['main_photo']): ?>
          <img src="<?= h(photo_url($a['main_photo'])) ?>" style="width:100%;height:100%;object-fit:cover;" alt="" loading="lazy">
        <?php else: ?>
          <div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:1.5rem;"><?= h($a['cat_icon']) ?></div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:200px;">
        <a href="/anunt/<?= h($a['slug']) ?>" style="font-weight:700;color:var(--text);text-decoration:none;font-family:var(--font-display);"><?= h($a['title']) ?></a>
        <div style="font-size:12px;color:var(--text-3);margin-top:4px;">
          <?= $a['price'] ? h(format_price($a['price'], $a['currency'])) : 'Preț negociabil' ?>
          · <?= h($a['cat_name']) ?>
          · 👁 <?= number_format($a['views_count']) ?>
          · ❤️ <?= $a['likes_count'] ?>
          · <?= time_ago($a['created_at']) ?>
        </div>
        <?php if ($a['status'] === 'rejected' && $a['reject_reason']): ?>
          <div class="alert alert-error" style="padding:6px 10px;font-size:12px;margin-top:6px;">Motiv respingere: <?= h($a['reject_reason']) ?></div>
        <?php endif; ?>
        <?php if ($a['expires_at'] && $a['status'] === 'active'): ?>
          <div style="font-size:12px;color:var(--warning);margin-top:4px;">⚠️ Expiră: <?= date('d.m.Y', strtotime($a['expires_at'])) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span class="badge badge-<?= ['active'=>'success','pending'=>'warning','rejected'=>'danger','expired'=>'gray','sold'=>'info','draft'=>'gray'][$a['status']]??'gray' ?>">
          <?= h($a['status']) ?>
        </span>
        <a href="/anunt/<?= h($a['slug']) ?>/editeaza" class="btn btn-secondary btn-sm">✏️</a>
        <form method="POST" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="ad_id" value="<?= $a['id'] ?>">
          <?php if ($a['status'] === 'active'): ?>
            <button name="action" value="mark_sold" class="btn btn-sm" style="background:var(--info);color:#fff;">✓ Vândut</button>
          <?php endif; ?>
          <?php if (in_array($a['status'], ['expired','rejected'])): ?>
            <button name="action" value="republish" class="btn btn-secondary btn-sm">🔄 Republică</button>
          <?php endif; ?>
          <button name="action" value="delete" class="btn btn-danger btn-sm" data-confirm="Ștergi anunțul definitiv?">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
