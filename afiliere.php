<?php
// afiliere.php — Program de afiliere Veedo
require_once __DIR__ . '/includes/bootstrap.php';

$u   = current_user();
$uid = $u['id'] ?? null;
$aff = $uid ? DB::fetch("SELECT * FROM affiliates WHERE user_id=?", [$uid]) : null;
$errors = [];

// Cerere inscriere
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && $uid) {
    if (!$aff) {
        $code = generate_affiliate_code($u['name']);
        DB::insert('affiliates', [
            'user_id'        => $uid,
            'affiliate_code' => $code,
            'commission_rate'=> (float)setting('affiliate_commission','10'),
        ]);
        flash('success','Cererea ta a fost trimisă! Vei fi notificat la aprobare (1-2 zile lucrătoare).');
        redirect('/afiliere');
    }
    // Update date plată
    if ($aff) {
        DB::update('affiliates', [
            'paypal_email' => sanitize($_POST['paypal_email'] ?? ''),
            'bank_iban'    => sanitize($_POST['bank_iban'] ?? ''),
        ], 'id=?', [$aff['id']]);
        flash('success','Datele de plată au fost actualizate!');
        redirect('/afiliere');
    }
}

// Statistici dacă e afiliat activ
$stats = null;
$recent_clicks = [];
$payouts = [];
if ($aff && $aff['status'] === 'active') {
    $stats = DB::fetch("
        SELECT 
            SUM(1) as total_clicks,
            SUM(converted) as conversions,
            SUM(commission_amount) as total_earned
        FROM affiliate_clicks WHERE affiliate_id=?
    ", [$aff['id']]);
    $recent_clicks = DB::fetchAll("
        SELECT ac.*, a.title as ad_title
        FROM affiliate_clicks ac
        LEFT JOIN ads a ON a.id=ac.ad_id
        WHERE ac.affiliate_id=?
        ORDER BY ac.created_at DESC LIMIT 20
    ", [$aff['id']]);
    $payouts = DB::fetchAll("SELECT * FROM affiliate_payouts WHERE affiliate_id=? ORDER BY created_at DESC LIMIT 10", [$aff['id']]);
}

$commission = setting('affiliate_commission','10');
$min_payout = setting('affiliate_min_payout','50');
$seo_title = 'Program Afiliere Veedo';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:28px 20px;max-width:860px;">

<?php if (!$aff): ?>
<!-- ═══ PAGINA PUBLICA - nu e inscris ═════════════════════════════ -->
  <div style="text-align:center;padding:40px 20px;max-width:600px;margin:0 auto;">
    <div style="font-size:3rem;margin-bottom:16px;">💰</div>
    <h1 style="font-size:1.8rem;margin-bottom:10px;">Câștigă cu Veedo</h1>
    <p style="color:var(--text-3);font-size:1.05rem;margin-bottom:32px;line-height:1.6;">
      Promovează anunțurile Veedo și câștigă <strong style="color:var(--brand);"><?= $commission ?>% comision</strong> pentru fiecare plată generată prin link-ul tău.
    </p>

    <!-- Cum functioneaza -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:32px;text-align:center;">
      <?php foreach([
        ['1️⃣','Înscrie-te','Completează formularul și obții codul tău unic de afiliat'],
        ['2️⃣','Promovează','Distribuie link-uri cu codul tău pe social media, blog, YouTube'],
        ['3️⃣','Câștigă','Primești '.$commission.'% din fiecare plată de boost sau business verify'],
      ] as [$num,$title,$desc]): ?>
      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;">
        <div style="font-size:2rem;margin-bottom:8px;"><?= $num ?></div>
        <div style="font-weight:700;margin-bottom:6px;"><?= $title ?></div>
        <div style="font-size:13px;color:var(--text-3);"><?= $desc ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Comisioane -->
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:24px;margin-bottom:28px;">
      <h2 style="font-size:1rem;margin-bottom:16px;">💸 Comisioane generate</h2>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;text-align:left;">
        <?php foreach([
          ['Boost 7 zile (29 RON)', number_format(29*$commission/100,2).' RON'],
          ['Boost 30 zile (79 RON)', number_format(79*$commission/100,2).' RON'],
          ['Business Verify (199 RON)', number_format(199*$commission/100,2).' RON / an'],
        ] as [$prod,$earn]): ?>
        <div style="font-size:14px;color:var(--text-3);"><?= $prod ?></div>
        <div style="font-size:14px;font-weight:700;color:var(--success);">+<?= $earn ?></div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-2);font-size:13px;color:var(--text-3);">
        Plată minimă: <strong><?= $min_payout ?> RON</strong> · Metode: PayPal, Transfer bancar
      </div>
    </div>

    <?php if ($uid): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-primary btn-lg" style="font-size:16px;">🚀 Înscrie-mă în program</button>
    </form>
    <?php else: ?>
    <a href="/register" class="btn btn-primary btn-lg">Creează cont gratuit →</a>
    <?php endif; ?>
  </div>

<?php elseif ($aff['status'] === 'pending'): ?>
<!-- ═══ IN ASTEPTARE APROBARE ═════════════════════════════════════ -->
  <div style="text-align:center;padding:60px 20px;">
    <div style="font-size:3rem;margin-bottom:16px;">⏳</div>
    <h2>Cererea ta este în curs de procesare</h2>
    <p style="color:var(--text-3);margin-top:10px;">Vei fi notificat prin email în 1-2 zile lucrătoare la aprobarea contului de afiliat.</p>
    <div style="margin-top:20px;background:var(--bg-2);border-radius:var(--radius-lg);padding:16px;display:inline-block;">
      Codul tău viitor: <strong style="font-family:monospace;letter-spacing:2px;"><?= h($aff['affiliate_code']) ?></strong>
    </div>
  </div>

<?php else: ?>
<!-- ═══ DASHBOARD AFILIAT ACTIV ═══════════════════════════════════ -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
    <div>
      <h1 style="margin:0;">💰 Dashboard Afiliat</h1>
      <p style="color:var(--text-3);font-size:13px;margin:4px 0 0;">Cod: <span style="font-family:monospace;font-weight:700;color:var(--brand);"><?= h($aff['affiliate_code']) ?></span></p>
    </div>
    <button onclick="copyAffLink()" class="btn btn-primary">🔗 Copiază link-ul tău</button>
  </div>

  <!-- Stats -->
  <div class="stats-grid" style="margin-bottom:24px;">
    <?php foreach([
      ['💰', number_format($aff['total_earned'],2).' RON', 'Total câștigat'],
      ['✅', number_format($aff['total_paid'],2).' RON', 'Total plătit'],
      ['📊', number_format($stats['total_clicks']??0), 'Click-uri'],
      ['🎯', number_format($stats['conversions']??0), 'Conversii'],
    ] as [$icon,$val,$lbl]): ?>
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;text-align:center;">
      <div style="font-size:1.5rem;margin-bottom:4px;"><?= $icon ?></div>
      <div style="font-size:1.4rem;font-weight:800;"><?= $val ?></div>
      <div style="font-size:12px;color:var(--text-3);"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Link-ul de afiliat -->
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;margin-bottom:20px;">
    <h2 style="font-size:1rem;margin-bottom:12px;">🔗 Link-urile tale</h2>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php foreach([
        'Homepage' => BASE_URL . '?ref=' . $aff['affiliate_code'],
        'Caută anunțuri' => BASE_URL . '/cauta?ref=' . $aff['affiliate_code'],
        'Boost anunțuri' => BASE_URL . '/cont/abonamente?ref=' . $aff['affiliate_code'],
      ] as $name => $link): ?>
      <div style="display:flex;align-items:center;gap:10px;background:var(--bg-2);border-radius:var(--radius);padding:10px 14px;">
        <span style="font-size:13px;font-weight:600;min-width:120px;"><?= $name ?></span>
        <input type="text" value="<?= h($link) ?>" readonly style="flex:1;font-size:12px;background:transparent;border:none;font-family:monospace;color:var(--text-2);" onclick="this.select()">
        <button onclick="navigator.clipboard.writeText('<?= h($link) ?>').then(()=>this.textContent='✓')" class="btn btn-secondary btn-sm">📋</button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Date plata + cerere payout -->
  <?php $balance = round($aff['total_earned'] - $aff['total_paid'], 2); ?>
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
      <h2 style="font-size:1rem;margin:0;">💳 Plată comisioane</h2>
      <div style="font-size:1.2rem;font-weight:800;color:<?= $balance >= $min_payout ? 'var(--success)' : 'var(--text-3)' ?>;">
        Disponibil: <?= number_format($balance, 2) ?> RON
      </div>
    </div>
    <?php if ($balance < $min_payout): ?>
      <div class="alert alert-info" style="font-size:13px;">Plata minimă este <?= $min_payout ?> RON. Mai ai nevoie de <?= number_format($min_payout - $balance, 2) ?> RON.</div>
    <?php else: ?>
      <div class="alert alert-success" style="font-size:13px;">Poți solicita plata! Contactează-ne la admin pentru procesare manuală.</div>
    <?php endif; ?>
    <form method="POST" style="margin-top:14px;">
      <?= csrf_field() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Email PayPal</label>
          <input type="email" name="paypal_email" class="form-control" value="<?= h($aff['paypal_email']??'') ?>" placeholder="paypal@exemplu.ro">
        </div>
        <div class="form-group">
          <label class="form-label">IBAN (transfer bancar)</label>
          <input type="text" name="bank_iban" class="form-control" value="<?= h($aff['bank_iban']??'') ?>" placeholder="RO49AAAA1B31007593840000">
        </div>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">💾 Salvează date plată</button>
    </form>
  </div>

  <!-- Click-uri recente -->
  <?php if (!empty($recent_clicks)): ?>
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;">
    <h2 style="font-size:1rem;margin-bottom:14px;">📊 Activitate recentă</h2>
    <div style="overflow-x:auto;">
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <thead><tr style="border-bottom:1px solid var(--border-2);">
          <th style="text-align:left;padding:8px 4px;color:var(--text-3);">Data</th>
          <th style="text-align:left;padding:8px 4px;color:var(--text-3);">Anunț</th>
          <th style="text-align:center;padding:8px 4px;color:var(--text-3);">Conversie</th>
          <th style="text-align:right;padding:8px 4px;color:var(--text-3);">Comision</th>
        </tr></thead>
        <tbody>
        <?php foreach($recent_clicks as $cl): ?>
        <tr style="border-bottom:1px solid var(--border-2);">
          <td style="padding:8px 4px;color:var(--text-3);"><?= date('d.m H:i', strtotime($cl['created_at'])) ?></td>
          <td style="padding:8px 4px;"><?= $cl['ad_title'] ? h(mb_substr($cl['ad_title'],0,35)) : '—' ?></td>
          <td style="padding:8px 4px;text-align:center;"><?= $cl['converted'] ? '<span style="color:var(--success);">✓ Da</span>' : '<span style="color:var(--text-3);">—</span>' ?></td>
          <td style="padding:8px 4px;text-align:right;font-weight:<?= $cl['commission_amount']>0?'700':'400' ?>;color:<?= $cl['commission_amount']>0?'var(--success)':'var(--text-3)' ?>;">
            <?= $cl['commission_amount'] > 0 ? '+'.number_format($cl['commission_amount'],2).' RON' : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
function copyAffLink() {
    const link = '<?= BASE_URL ?>?ref=<?= h($aff['affiliate_code'] ?? '') ?>';
    navigator.clipboard.writeText(link).then(() => alert('Link copiat: ' + link));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
