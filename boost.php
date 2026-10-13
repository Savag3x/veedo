<?php
// boost.php — Promovare anunț sau verificare business
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$ad_id  = (int)($_GET['id'] ?? 0);
$type   = in_array($_GET['type'] ?? '', ['boost','business']) ? $_GET['type'] : 'boost';
$plan   = in_array($_GET['plan'] ?? '', ['7','30']) ? (int)$_GET['plan'] : 7;
$u      = current_user();

if ($type === 'boost') {
    $ad = DB::fetch("SELECT a.*, c.name AS cat_name FROM ads a JOIN categories c ON c.id=a.category_id WHERE a.id=? AND a.user_id=? AND a.status='active'", [$ad_id, $u['id']]);
    if (!$ad) { flash('error','Anunț invalid.'); redirect('/cont/anunturile-mele'); }
    $price = $plan === 30 ? (float)setting('boost_price_30days','79') : (float)setting('boost_price_7days','29');
    $seo_title = 'Promovează anunțul';
} else {
    $price = (float)setting('verified_business_price','199');
    $seo_title = 'Verificare Business';
    if (!empty($u['is_verified_business'])) {
        flash('info','Ești deja Business Verificat!'); redirect('/cont/setari');
    }
}

$stripe_pk  = setting('stripe_public_key','');
$netopia_ok = setting('netopia_signature','') && setting('netopia_public_key','');
$has_payment = $stripe_pk || $netopia_ok;

include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:40px 20px;">
  <div style="max-width:540px;margin:0 auto;">

    <?php if ($type === 'business'): ?>
      <h1 style="margin-bottom:8px;">🏢 Verificare Business</h1>
      <p style="color:var(--text-3);margin-bottom:28px;">Obține badge-ul de business verificat și pagina extinsă de firmă.</p>
      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;margin-bottom:24px;">
        <div style="font-weight:700;margin-bottom:12px;">Ce primești:</div>
        <?php foreach (['✓ Badge Business verificat pe profil','✓ Pagină firmă cu banner, program, contact','✓ Prioritate în rezultatele căutare','✓ Anunțuri nelimitate simultan','✓ Statistici avansate în dashboard','✓ Suport prioritar pe email'] as $b): ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:10px;"><?= h($b) ?></div>
        <?php endforeach; ?>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-2);font-size:1.5rem;font-weight:800;color:var(--brand);"><?= number_format($price,0) ?> RON / an</div>
      </div>
    <?php else: ?>
      <h1 style="margin-bottom:8px;">⭐ Promovează anunțul</h1>
      <p style="color:var(--text-3);margin-bottom:28px;">Anunțurile promovate primesc de <strong>5x</strong> mai multe vizualizări!</p>
      <!-- Ad preview -->
      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:14px;margin-bottom:20px;display:flex;gap:12px;align-items:center;">
        <div style="width:56px;height:42px;background:var(--bg-3);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">📋</div>
        <div><div style="font-weight:700;font-size:14px;"><?= h(mb_substr($ad['title'],0,55)) ?></div><div style="font-size:12px;color:var(--text-3);"><?= h($ad['cat_name']) ?></div></div>
      </div>
      <!-- Plan selector -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
        <?php foreach ([7 => setting('boost_price_7days','29'), 30 => setting('boost_price_30days','79')] as $d => $p): ?>
        <a href="/boost/<?= $ad_id ?>?plan=<?= $d ?>" style="text-decoration:none;">
          <div style="border:2px solid <?= $plan===$d?'var(--brand)':'var(--border)' ?>;border-radius:var(--radius-lg);padding:18px;text-align:center;background:<?= $plan===$d?'rgba(232,56,13,0.04)':'var(--surface)' ?>;position:relative;">
            <?php if ($d === 30): ?><div style="position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--warning);color:#fff;font-size:10px;font-weight:700;padding:2px 10px;border-radius:10px;white-space:nowrap;">ECONOMIE 15%</div><?php endif; ?>
            <div style="font-size:1.6rem;font-weight:800;color:var(--brand);"><?= number_format($p,0) ?> RON</div>
            <div style="font-weight:700;font-size:14px;margin:4px 0;"><?= $d ?> zile</div>
            <div style="font-size:12px;color:var(--text-3);">~<?= number_format($p/$d,2) ?> RON/zi</div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Payment methods -->
    <?php if ($has_payment): ?>
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:24px;" id="payment-box">
      <h3 style="font-size:1rem;margin-bottom:16px;">💳 Alege metoda de plată</h3>

      <!-- Tabs metode plata -->
      <div style="display:flex;gap:8px;margin-bottom:20px;" id="method-tabs">
        <?php if ($stripe_pk): ?>
        <button class="method-tab active" data-method="stripe" style="flex:1;padding:10px;border:2px solid var(--brand);border-radius:var(--radius);background:rgba(232,56,13,.06);font-size:13px;font-weight:600;cursor:pointer;">
          💳 Card (Stripe)
        </button>
        <?php endif; ?>
        <?php if ($netopia_ok): ?>
        <button class="method-tab <?= !$stripe_pk?'active':'' ?>" data-method="netopia" style="flex:1;padding:10px;border:2px solid var(--border);border-radius:var(--radius);background:var(--surface);font-size:13px;font-weight:600;cursor:pointer;">
          🏦 Netopia Card
        </button>
        <?php endif; ?>
      </div>

      <!-- Stripe form -->
      <?php if ($stripe_pk): ?>
      <div id="stripe-form">
        <div id="card-element" style="border:1.5px solid var(--border);border-radius:var(--radius);padding:12px;background:var(--bg);"></div>
        <div id="card-errors" style="color:var(--danger);font-size:13px;margin-top:8px;"></div>
      </div>
      <?php endif; ?>

      <!-- Netopia info -->
      <?php if ($netopia_ok): ?>
      <div id="netopia-form" style="<?= $stripe_pk ? 'display:none' : '' ?>">
        <div style="background:var(--bg-2);border-radius:var(--radius);padding:14px;font-size:13px;color:var(--text-2);text-align:center;">
          <img src="https://www.netopia-payments.com/app/uploads/2021/03/Logo-Netopia-Payments-color.png" alt="Netopia" style="height:28px;margin-bottom:8px;display:block;margin:0 auto 8px;"><br>
          Vei fi redirecționat securizat pe pagina de plată Netopia Payments.
        </div>
      </div>
      <?php endif; ?>

      <div style="margin-top:16px;">
        <button id="pay-btn" class="btn btn-primary w-full btn-lg" style="justify-content:center;font-size:16px;">
          Plătește <?= number_format($price,0) ?> RON
        </button>
      </div>
      <p style="text-align:center;font-size:12px;color:var(--text-3);margin-top:10px;">🔒 Plată securizată SSL · Datele cardului nu sunt stocate</p>
    </div>

    <script>
    const AD_ID  = <?= $type === 'boost' ? $ad_id : 0 ?>;
    const PLAN   = '<?= $type === 'boost' ? $plan : 'business' ?>';
    const PRICE  = <?= $price ?>;

    // Method tabs
    let currentMethod = '<?= $stripe_pk ? 'stripe' : 'netopia' ?>';
    document.querySelectorAll('.method-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        currentMethod = btn.dataset.method;
        document.querySelectorAll('.method-tab').forEach(b => {
          b.style.border = '2px solid var(--border)';
          b.style.background = 'var(--surface)';
        });
        btn.style.border = '2px solid var(--brand)';
        btn.style.background = 'rgba(232,56,13,.06)';
        document.getElementById('stripe-form') && (document.getElementById('stripe-form').style.display = currentMethod === 'stripe' ? '' : 'none');
        document.getElementById('netopia-form') && (document.getElementById('netopia-form').style.display = currentMethod === 'netopia' ? '' : 'none');
      });
    });

    <?php if ($stripe_pk): ?>
    // Stripe
    const stripe   = Stripe('<?= h($stripe_pk) ?>');
    const elements = stripe.elements();
    const card = elements.create('card', {style:{base:{fontSize:'16px',color: document.documentElement.style.getPropertyValue('--color-text') || '#1C1C1E'}}});
    card.mount('#card-element');
    card.on('change', e => { document.getElementById('card-errors').textContent = e.error ? e.error.message : ''; });
    <?php endif; ?>

    document.getElementById('pay-btn').addEventListener('click', async () => {
      const btn = document.getElementById('pay-btn');
      btn.disabled = true; btn.textContent = 'Se procesează...';

      try {
        const r = await fetch('/api/boost-payment.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
          },
          body: JSON.stringify({ad_id: AD_ID, plan: PLAN, method: currentMethod})
        });
        const res = await r.json();

        if (res.error) {
          document.getElementById('card-errors') && (document.getElementById('card-errors').textContent = res.error);
          alert(res.error);
          btn.disabled = false; btn.textContent = 'Plătește ' + PRICE.toFixed(0) + ' RON';
          return;
        }

        // Netopia redirect
        if (res.netopia_redirect) {
          const form = document.createElement('form');
          form.method = 'POST'; form.action = res.url;
          ['env_key','data'].forEach(k => {
            const i = document.createElement('input');
            i.type='hidden'; i.name=k; i.value=res[k];
            form.appendChild(i);
          });
          document.body.appendChild(form); form.submit();
          return;
        }

        <?php if ($stripe_pk): ?>
        // Stripe confirm
        if (res.client_secret) {
          const result = await stripe.confirmCardPayment(res.client_secret, {payment_method:{card}});
          if (result.error) {
            document.getElementById('card-errors').textContent = result.error.message;
            btn.disabled = false; btn.textContent = 'Plătește ' + PRICE.toFixed(0) + ' RON';
          } else {
            // Aplicam boost pe server la success (stripe webhook o face automat)
            await fetch('/api/boost-apply.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({pi: result.paymentIntent.id})});
            window.location.href = '/cont/tranzactii?status=ok';
          }
        }
        <?php endif; ?>
      } catch(e) {
        alert('Eroare conexiune. Încearcă din nou.');
        btn.disabled = false; btn.textContent = 'Plătește ' + PRICE.toFixed(0) + ' RON';
      }
    });
    </script>
    <?php if ($stripe_pk): ?><script src="https://js.stripe.com/v3/"></script><?php endif; ?>

    <?php else: ?>
    <div class="alert alert-warning">
      ⚠️ Metodele de plată nu sunt configurate. Administratorul trebuie să activeze Stripe sau Netopia din panoul Admin → Setări → Plăți.
    </div>
    <?php endif; ?>

    <div style="margin-top:16px;text-align:center;">
      <a href="/cont/anunturile-mele" style="font-size:13px;color:var(--text-3);">← Înapoi la anunțuri</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
