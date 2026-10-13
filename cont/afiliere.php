<?php
// cont/afiliere.php — Program de afiliere
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if (!setting('affiliate_enabled','1')) {
    flash('error','Programul de afiliere nu este activ momentan.');
    redirect('/');
}

$u = current_user();
$full = DB::fetch("SELECT * FROM users WHERE id=?", [$u['id']]);

// Generam cod de referral daca nu exista
if (empty($full['referral_code'])) {
    $code = strtoupper(substr(md5($u['id'] . 'veedo' . time()), 0, 8));
    DB::update('users', ['referral_code' => $code], 'id=?', [$u['id']]);
    $full['referral_code'] = $code;
}

$referral_url = BASE_URL . '/register?ref=' . $full['referral_code'];
$commission_pct = (float)setting('affiliate_commission_pct', '10');
$min_withdraw = (float)setting('affiliate_min_withdraw', '50');

// Referrali ai mei
$referrals = DB::fetchAll("
    SELECT r.*, u.name as referred_name, u.email as referred_email, u.created_at as user_joined
    FROM referrals r JOIN users u ON u.id=r.referred_id
    WHERE r.referrer_id=?
    ORDER BY r.created_at DESC
    LIMIT 50
", [$u['id']]);

$stats = [
    'total'     => count($referrals),
    'converted' => count(array_filter($referrals, fn($r)=>$r['status']==='converted'||$r['status']==='paid')),
    'balance'   => (float)($full['affiliate_balance'] ?? 0),
    'total_earned' => (float)($full['affiliate_total_earned'] ?? 0),
];

// Cerere retragere
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && isset($_POST['request_withdraw'])) {
    $iban = sanitize($_POST['iban'] ?? '');
    $iban = preg_replace('/\s/', '', strtoupper($iban));
    if ($stats['balance'] < $min_withdraw) {
        flash('error', 'Soldul minim pentru retragere este ' . $min_withdraw . ' RON.');
    } elseif (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{4,}$/', $iban)) {
        flash('error', 'IBAN invalid.');
    } else {
        DB::insert('affiliate_withdrawals', [
            'user_id' => $u['id'],
            'amount'  => $stats['balance'],
            'iban'    => $iban,
            'status'  => 'pending',
        ]);
        // Reset balance
        DB::update('users', ['affiliate_balance' => 0], 'id=?', [$u['id']]);
        DB::insert('notifications', [
            'user_id' => 1, // admin
            'type'    => 'affiliate_withdraw',
            'title'   => 'Cerere retragere afiliere — ' . $u['name'],
            'body'    => 'Sumă: ' . $stats['balance'] . ' RON · IBAN: ' . $iban,
            'link'    => '/admin?s=orders',
        ]);
        flash('success', 'Cererea de retragere a fost trimisă! Vei primi banii în 3-5 zile lucrătoare.');
        redirect('/cont/afiliere');
    }
}

// Retrageri anterioare
$withdrawals = DB::fetchAll("SELECT * FROM affiliate_withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [$u['id']]);

$seo_title = 'Program Afiliere';
include __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding:28px 20px;max-width:760px;">
  <h1 style="margin-bottom:6px;">💰 Program de Afiliere</h1>
  <p style="color:var(--text-3);margin-bottom:24px;">Câștigă <strong><?= $commission_pct ?>%</strong> comision din fiecare plată a utilizatorilor pe care îi aduci pe platformă.</p>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:28px;">
    <?php foreach ([
      ['👥', $stats['total'], 'Referrali înregistrați', ''],
      ['✅', $stats['converted'], 'Convertiți (au plătit)', 'var(--success)'],
      ['💰', number_format($stats['balance'],2).' RON', 'Sold disponibil', 'var(--brand)'],
      ['📊', number_format($stats['total_earned'],2).' RON', 'Total câștigat', 'var(--warning)'],
    ] as [$icon,$val,$lbl,$col]): ?>
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:16px;text-align:center;">
      <div style="font-size:1.5rem;"><?= $icon ?></div>
      <div style="font-size:1.3rem;font-weight:800;color:<?= $col?:'var(--text)' ?>;margin:4px 0;"><?= $val ?></div>
      <div style="font-size:11px;color:var(--text-3);"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Link referral -->
  <div style="background:linear-gradient(135deg,rgba(232,56,13,0.08),rgba(255,107,53,0.05));border:2px solid var(--brand);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;">
    <h2 style="font-size:1rem;margin-bottom:14px;">🔗 Link-ul tău de referral</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <input type="text" id="ref-link" class="form-control" value="<?= h($referral_url) ?>" readonly style="flex:1;font-size:13px;font-family:monospace;">
      <button onclick="copyRef()" class="btn btn-primary" id="copy-btn">📋 Copiază</button>
    </div>
    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
      <a href="https://wa.me/?text=<?= urlencode('Încearcă Veedo — platforma de anunțuri video! '.$referral_url) ?>" 
         target="_blank" class="btn btn-secondary btn-sm">📱 WhatsApp</a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($referral_url) ?>" 
         target="_blank" class="btn btn-secondary btn-sm">📘 Facebook</a>
      <span style="font-size:12px;color:var(--text-3);align-self:center;">Codul tău: <strong><?= h($full['referral_code']) ?></strong></span>
    </div>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-2);">
      <div style="font-size:13px;font-weight:600;margin-bottom:8px;">Cum funcționează:</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;font-size:12px;color:var(--text-2);">
        <div>1️⃣ Trimite link-ul prietenilor</div>
        <div>2️⃣ Ei se înregistrează pe Veedo</div>
        <div>3️⃣ Când plătesc, tu câștigi <?= $commission_pct ?>%</div>
        <div>4️⃣ Retragi banii în cont bancar</div>
      </div>
    </div>
  </div>

  <!-- Retragere sold -->
  <?php if ($stats['balance'] >= $min_withdraw): ?>
  <div style="background:var(--surface);border:1px solid var(--success);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px;">
    <h2 style="font-size:1rem;margin-bottom:14px;color:var(--success);">💸 Retrage soldul disponibil</h2>
    <p style="font-size:14px;color:var(--text-2);margin-bottom:16px;">
      Ai <strong><?= number_format($stats['balance'],2) ?> RON</strong> disponibili pentru retragere.
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label">IBAN (cont bancar românesc)</label>
        <input type="text" name="iban" class="form-control" placeholder="RO49AAAA1B31007593840000" required maxlength="34"
               oninput="this.value=this.value.replace(/\s/g,'').toUpperCase()">
        <div class="form-hint">Contul trebuie să fie pe numele tău.</div>
      </div>
      <div style="font-size:13px;color:var(--text-3);margin-bottom:12px;">
        ⏱ Procesare în 3-5 zile lucrătoare. Suma: <strong><?= number_format($stats['balance'],2) ?> RON</strong>
      </div>
      <button type="submit" name="request_withdraw" class="btn btn-primary">💸 Solicită retragerea</button>
    </form>
  </div>
  <?php elseif ($stats['balance'] > 0): ?>
  <div class="alert alert-info" style="margin-bottom:24px;">
    💰 Mai ai nevoie de <strong><?= number_format($min_withdraw - $stats['balance'],2) ?> RON</strong> pentru a putea retrage (minim <?= $min_withdraw ?> RON).
  </div>
  <?php endif; ?>

  <!-- Lista referrali -->
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px;">
    <h2 style="font-size:1rem;margin-bottom:14px;">👥 Referralii tăi</h2>
    <?php if (empty($referrals)): ?>
      <p style="color:var(--text-3);font-size:14px;">Niciun referral momentan. Distribuie link-ul pentru a câștiga!</p>
    <?php else: ?>
    <div class="table-wrap"><table style="width:100%;font-size:13px;border-collapse:collapse;">
      <thead><tr style="border-bottom:1px solid var(--border-2);">
        <th style="padding:8px 0;text-align:left;color:var(--text-3);">Utilizator</th>
        <th style="padding:8px 0;text-align:center;color:var(--text-3);">Status</th>
        <th style="padding:8px 0;text-align:right;color:var(--text-3);">Comision</th>
        <th style="padding:8px 0;text-align:right;color:var(--text-3);">Data</th>
      </tr></thead>
      <tbody>
      <?php foreach ($referrals as $r): ?>
      <tr style="border-bottom:1px solid var(--border-2);">
        <td style="padding:10px 0;"><?= h($r['referred_name']) ?></td>
        <td style="text-align:center;">
          <span class="badge badge-<?= ['pending'=>'warning','converted'=>'success','paid'=>'info'][$r['status']] ?? 'gray' ?>">
            <?= ['pending'=>'Înregistrat','converted'=>'A plătit','paid'=>'Plătit'][$r['status']] ?? $r['status'] ?>
          </span>
        </td>
        <td style="text-align:right;font-weight:700;color:<?= $r['commission_amount']>0?'var(--success)':'var(--text-3)' ?>">
          <?= $r['commission_amount'] > 0 ? '+'.number_format($r['commission_amount'],2).' RON' : '—' ?>
        </td>
        <td style="text-align:right;color:var(--text-3);"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- Retrageri anterioare -->
  <?php if (!empty($withdrawals)): ?>
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;">
    <h2 style="font-size:1rem;margin-bottom:14px;">📜 Istoricul retragerilor</h2>
    <?php foreach ($withdrawals as $w): ?>
    <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-2);font-size:13px;">
      <div><?= date('d.m.Y', strtotime($w['created_at'])) ?> · IBAN: <?= substr(h($w['iban']),0,8) ?>...<?= substr(h($w['iban']),-4) ?></div>
      <div style="display:flex;gap:10px;align-items:center;">
        <strong><?= number_format($w['amount'],2) ?> RON</strong>
        <span class="badge badge-<?= ['pending'=>'warning','paid'=>'success','rejected'=>'danger'][$w['status']]??'gray' ?>"><?= $w['status'] ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function copyRef() {
    navigator.clipboard.writeText(document.getElementById('ref-link').value).then(() => {
        const btn = document.getElementById('copy-btn');
        btn.textContent = '✅ Copiat!';
        btn.style.background = 'var(--success)';
        setTimeout(() => { btn.textContent = '📋 Copiază'; btn.style.background = ''; }, 2000);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
