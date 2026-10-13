<?php
// =========================================================
// VEEDO Admin — Date demo (populare / ștergere)
// =========================================================
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/../database/seed/seeder.php';

$log = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF verificat global în bootstrap
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'seed') {
            $log = demo_seed([
                'reset'  => true,
                'revive' => !empty($_POST['revive']),
                'photos' => !empty($_POST['offline']) ? 'generated' : 'auto',
            ]);
            admin_log('demo_seed', 'system', 0, 'Date demo generate');
        } elseif ($action === 'remove') {
            $log = [demo_reset()];
            admin_log('demo_remove', 'system', 0, 'Date demo șterse');
        } elseif ($action === 'revive') {
            $log = [demo_revive()];
            admin_log('demo_revive', 'system', 0, 'Anunțuri expirate prelungite');
        }
    } catch (Throwable $e) {
        error_log('[veedo] demo-data: ' . $e->getMessage());
        $error = 'Eroare: ' . $e->getMessage();
    }
}

$demo_users = (int)(DB::fetch("SELECT COUNT(*) c FROM users WHERE email LIKE ?", ['%@' . DEMO_EMAIL_DOMAIN])['c'] ?? 0);
$demo_ads   = (int)(DB::fetch("SELECT COUNT(*) c FROM ads a JOIN users u ON u.id=a.user_id WHERE u.email LIKE ?", ['%@' . DEMO_EMAIL_DOMAIN])['c'] ?? 0);
$expired    = (int)(DB::fetch("SELECT COUNT(*) c FROM ads WHERE status='active' AND expires_at < CURDATE()")['c'] ?? 0);

$seo_title = 'Date demo — Admin';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-sm" style="padding:28px 24px 60px;">
  <a href="/admin" class="btn btn-ghost btn-sm" style="margin-bottom:14px;">← Înapoi la admin</a>
  <h1 style="font-size:1.8rem;margin-bottom:8px;">Date demo</h1>
  <p style="color:var(--text-2);margin-bottom:24px;">Populează platforma cu 16 utilizatori, ~50 de anunțuri cu poze în toate categoriile,
     like-uri, comentarii, recenzii, conversații și stories. Datele demo pot fi șterse oricând, fără să afecteze conținutul real.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($log): ?>
    <div class="alert alert-success"><?php foreach ($log as $l): ?><div>✓ <?= h($l) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:16px;">
    <div style="display:flex;gap:28px;flex-wrap:wrap;margin-bottom:22px;font-family:var(--font-mono);">
      <div><div style="font-size:1.8rem;font-weight:600;"><?= $demo_users ?></div><div style="font-size:12px;color:var(--text-3);">UTILIZATORI DEMO</div></div>
      <div><div style="font-size:1.8rem;font-weight:600;"><?= $demo_ads ?></div><div style="font-size:12px;color:var(--text-3);">ANUNȚURI DEMO</div></div>
      <div><div style="font-size:1.8rem;font-weight:600;"><?= $expired ?></div><div style="font-size:12px;color:var(--text-3);">ANUNȚURI REALE EXPIRATE</div></div>
    </div>

    <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Se generează… (15–60 s)';">
      <input type="hidden" name="action" value="seed">
      <label style="display:flex;gap:10px;align-items:center;margin-bottom:10px;font-size:14px;">
        <input type="checkbox" name="revive" value="1" <?= $expired ? 'checked' : '' ?>> Prelungește și anunțurile reale expirate (+60 zile)
      </label>
      <label style="display:flex;gap:10px;align-items:center;margin-bottom:18px;font-size:14px;">
        <input type="checkbox" name="offline" value="1"> Nu descărca poze de pe internet (le generează local, mai rapid)
      </label>
      <button type="submit" class="btn btn-primary btn-lg"><?= $demo_users ? 'Regenerează datele demo' : 'Populează cu date demo' ?></button>
      <p class="form-hint">Rularea șterge mai întâi datele demo anterioare. Pozele reale se descarcă de pe LoremFlickr (licențe Creative Commons); dacă serverul nu are acces la internet, se generează automat.</p>
    </form>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if ($demo_users): ?>
    <form method="POST" onsubmit="return confirm('Ștergi toți utilizatorii și anunțurile demo?');">
      <input type="hidden" name="action" value="remove">
      <button type="submit" class="btn btn-danger">Șterge datele demo</button>
    </form>
    <?php endif; ?>
    <?php if ($expired): ?>
    <form method="POST">
      <input type="hidden" name="action" value="revive">
      <button type="submit" class="btn btn-secondary">Doar prelungește anunțurile expirate (<?= $expired ?>)</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
