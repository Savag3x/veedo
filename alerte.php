<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$cu  = current_user();
$uid = (int)$cu['id'];

// ── POST: creare / stergere alerta ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name        = mb_substr(sanitize($_POST['name'] ?? ''), 0, 100);
        $keywords    = mb_substr(sanitize($_POST['keywords'] ?? ''), 0, 200);
        $category_id = (int)($_POST['category_id'] ?? 0) ?: null;
        $city        = mb_substr(sanitize($_POST['city'] ?? ''), 0, 100);
        $price_min   = $_POST['price_min'] !== '' ? (float)$_POST['price_min'] : null;
        $price_max   = $_POST['price_max'] !== '' ? (float)$_POST['price_max'] : null;

        if (!$keywords && !$category_id && !$city) {
            flash('error', 'Adaugă cel puțin un criteriu de căutare.');
        } else {
            $count = DB::fetch("SELECT COUNT(*) as c FROM search_alerts WHERE user_id=? AND is_active=1", [$uid])['c'];
            if ($count >= 10) {
                flash('error', 'Poți avea maxim 10 alerte active.');
            } else {
                DB::insert('search_alerts', [
                    'user_id'     => $uid,
                    'name'        => $name ?: ($keywords ?: ($city ?: 'Alertă')),
                    'keywords'    => $keywords,
                    'category_id' => $category_id,
                    'city'        => $city,
                    'price_min'   => $price_min,
                    'price_max'   => $price_max,
                ]);
                flash('success', 'Alertă creată! Vei primi email când apar anunțuri noi.');
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['alert_id'] ?? 0);
        DB::query("DELETE FROM search_alerts WHERE id=? AND user_id=?", [$id, $uid]);
        flash('success', 'Alertă ștearsă.');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['alert_id'] ?? 0);
        DB::query("UPDATE search_alerts SET is_active = NOT is_active WHERE id=? AND user_id=?", [$id, $uid]);
    }

    redirect('/alerte');
}

$alerts     = DB::fetchAll("SELECT sa.*, c.name AS cat_name FROM search_alerts sa LEFT JOIN categories c ON c.id=sa.category_id WHERE sa.user_id=? ORDER BY sa.created_at DESC", [$uid]);
$categories = DB::fetchAll("SELECT id, name, icon FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order");
$seo_title  = 'Alertele mele de căutare';
include __DIR__ . '/includes/header.php';
?>

<main style="background:var(--bg-2);min-height:calc(100vh - 120px);padding:24px 0;">
<div class="container" style="max-width:800px;">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
    <div>
      <h1 style="font-size:1.4rem;font-weight:800;">🔔 Alerte căutare</h1>
      <p style="font-size:14px;color:var(--text-3);margin-top:4px;">Primești email când apar anunțuri noi care se potrivesc.</p>
    </div>
    <a href="/cont/setari#notificari" class="btn btn-secondary" style="font-size:13px;">⚙️ Setări notificări</a>
  </div>

  <?php flash_render(); ?>

  <!-- Form creare alertă -->
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:20px;">
    <h3 style="font-size:1rem;font-weight:700;margin-bottom:18px;">+ Alertă nouă</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="form-group">
        <label class="form-label">Nume alertă (opțional)</label>
        <input type="text" name="name" class="form-control" placeholder="ex: iPhone 13, Apartament 2 camere..." maxlength="100">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Cuvinte cheie</label>
          <input type="text" name="keywords" class="form-control" placeholder="ex: iPhone, BMW, sofa..." maxlength="200">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Oraș</label>
          <input type="text" name="city" class="form-control" placeholder="ex: București, Cluj...">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Categorie</label>
          <select name="category_id" class="form-control">
            <option value="">Toate categoriile</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>"><?= h($cat['icon']) ?> <?= h($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Preț</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="number" name="price_min" class="form-control" placeholder="Min" style="flex:1;">
            <span style="color:var(--text-3);font-size:13px;">—</span>
            <input type="number" name="price_max" class="form-control" placeholder="Max" style="flex:1;">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="margin-top:16px;">🔔 Creează alertă</button>
    </form>
  </div>

  <!-- Lista alerte -->
  <?php if (empty($alerts)): ?>
  <div style="text-align:center;padding:48px 20px;color:var(--text-3);">
    <div style="font-size:3rem;margin-bottom:12px;">🔔</div>
    <p>Nu ai nicio alertă. Creează una să fii primul care află!</p>
  </div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:10px;">
    <?php foreach ($alerts as $al): ?>
    <div style="background:var(--surface);border:1px solid <?= $al['is_active'] ? 'var(--border)' : 'var(--border-2)' ?>;border-radius:12px;padding:16px;display:flex;align-items:center;gap:14px;<?= !$al['is_active'] ? 'opacity:.6;' : '' ?>">
      <div style="font-size:1.6rem;flex-shrink:0;"><?= $al['is_active'] ? '🔔' : '🔕' ?></div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;"><?= h($al['name']) ?></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;font-size:12px;color:var(--text-3);">
          <?php if ($al['keywords']): ?><span style="background:var(--bg-2);padding:2px 8px;border-radius:20px;">🔍 <?= h($al['keywords']) ?></span><?php endif; ?>
          <?php if ($al['cat_name']): ?><span style="background:var(--bg-2);padding:2px 8px;border-radius:20px;">📂 <?= h($al['cat_name']) ?></span><?php endif; ?>
          <?php if ($al['city']): ?><span style="background:var(--bg-2);padding:2px 8px;border-radius:20px;">📍 <?= h($al['city']) ?></span><?php endif; ?>
          <?php if ($al['price_min'] || $al['price_max']): ?>
            <span style="background:var(--bg-2);padding:2px 8px;border-radius:20px;">
              💰 <?= $al['price_min'] ? number_format($al['price_min'],0,',','.') : '0' ?> — <?= $al['price_max'] ? number_format($al['price_max'],0,',','.') : '∞' ?>
            </span>
          <?php endif; ?>
          <?php if ($al['last_sent']): ?>
            <span style="color:var(--success);">✓ Trimis <?= time_ago($al['last_sent']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <form method="POST" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="alert_id" value="<?= (int)$al['id'] ?>">
          <button type="submit" class="btn btn-secondary btn-sm" title="<?= $al['is_active'] ? 'Dezactivează' : 'Activează' ?>">
            <?= $al['is_active'] ? '🔕' : '🔔' ?>
          </button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Ștergi această alertă?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="alert_id" value="<?= (int)$al['id'] ?>">
          <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger);">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>