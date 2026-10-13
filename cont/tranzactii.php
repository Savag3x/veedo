<?php
// cont/tranzactii.php — Istoricul tranzacțiilor
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$u = current_user();

$tranz = DB::fetchAll("
    SELECT t.*, a.title AS ad_title, a.slug AS ad_slug
    FROM orders t
    LEFT JOIN ads a ON a.id = t.ad_id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
    LIMIT 50
", [$u['id']]);

$seo_title = 'Istoricul tranzacțiilor';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding:28px 20px;max-width:860px;margin:0 auto;">
  <h1 style="margin-bottom:6px;">💳 Tranzacții</h1>
  <p style="color:var(--text-3);margin-bottom:28px;">Istoricul plăților tale pe platformă</p>

  <?php if (empty($tranz)): ?>
    <div style="text-align:center;padding:80px 20px;color:var(--text-3);">
      <div style="font-size:3rem;margin-bottom:12px;">💳</div>
      <h3>Nicio tranzacție</h3>
      <p style="margin-top:8px;">Nu ai efectuat nicio plată pe platformă.</p>
      <a href="/cont/anunturile-mele" class="btn btn-primary mt-3">Anunțurile mele</a>
    </div>
  <?php else: ?>
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);overflow:hidden;">
      <!-- Header tabel -->
      <div style="display:grid;grid-template-columns:1fr 130px 100px 100px;gap:12px;padding:14px 20px;background:var(--bg-2);border-bottom:1px solid var(--border-2);font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;">
        <div>Descriere</div>
        <div>Data</div>
        <div>Metodă</div>
        <div style="text-align:right;">Sumă</div>
      </div>
      <?php foreach ($tranz as $i => $t): 
        $status_colors = ['completed'=>'success','pending'=>'warning','failed'=>'danger','refunded'=>'info'];
        $status_labels = ['completed'=>'Plătit','pending'=>'În așteptare','failed'=>'Eșuat','refunded'=>'Restituit'];
        $status_col = $status_colors[$t['status']] ?? 'gray';
        $status_lbl = $status_labels[$t['status']] ?? $t['status'];
        $type_icons = ['boost_7'=>'⭐','boost_30'=>'🚀','business_verify'=>'🏢','ad_post'=>'📋'];
        $type_labels = ['boost_7'=>'Boost 7 zile','boost_30'=>'Boost 30 zile','business_verify'=>'Verificare Business','ad_post'=>'Postare anunț'];
        $icon = $type_icons[$t['type']] ?? '💳';
        $lbl  = $type_labels[$t['type']] ?? $t['type'];
      ?>
      <div style="display:grid;grid-template-columns:1fr 130px 100px 100px;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border-2);align-items:center;<?= $i%2===1?'background:var(--bg-2)':'' ?>">
        <!-- Descriere -->
        <div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:1.2rem;"><?= $icon ?></span>
            <div>
              <div style="font-weight:600;font-size:14px;"><?= $lbl ?></div>
              <?php if ($t['ad_title']): ?>
                <a href="/anunt/<?= h($t['ad_slug']) ?>" style="font-size:12px;color:var(--text-3);">
                  <?= h(mb_substr($t['ad_title'],0,50)) ?>
                </a>
              <?php endif; ?>
              <?php if ($t['transaction_id']): ?>
                <div style="font-size:11px;color:var(--text-3);font-family:monospace;">ID: <?= h($t['transaction_id']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <!-- Data -->
        <div style="font-size:13px;color:var(--text-2);">
          <?= date('d.m.Y', strtotime($t['created_at'])) ?>
          <div style="font-size:11px;color:var(--text-3);"><?= date('H:i', strtotime($t['created_at'])) ?></div>
        </div>
        <!-- Metoda -->
        <div>
          <?php 
            $methods = ['stripe'=>'💳 Card','netopia'=>'💳 Netopia','cash'=>'💵 Cash','free'=>'🎁 Gratuit'];
            echo $methods[$t['payment_method'] ?? ''] ?? ('💳 ' . ucfirst($t['payment_method'] ?? 'Card'));
          ?>
          <div><span class="badge badge-<?= $status_col ?>" style="font-size:10px;margin-top:4px;"><?= $status_lbl ?></span></div>
        </div>
        <!-- Suma -->
        <div style="text-align:right;font-weight:700;font-size:15px;color:<?= $t['status']==='refunded'?'var(--danger)':($t['status']==='failed'?'var(--text-3)':'var(--success)') ?>;">
          <?= $t['status']==='refunded' ? '−' : '' ?><?= number_format($t['amount'], 2) ?> RON
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Total -->
    <?php 
      $total_paid = array_sum(array_map(fn($t)=> $t['status']==='completed' ? $t['amount'] : 0, $tranz));
    ?>
    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:16px 24px;text-align:right;">
        <div style="font-size:12px;color:var(--text-3);">Total plătit</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--brand);"><?= number_format($total_paid, 2) ?> RON</div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
