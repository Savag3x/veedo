<?php
// cont/abonamente.php — Planuri și prețuri
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$u = current_user();

$seo_title = 'Planuri & Prețuri';
include __DIR__ . '/../includes/header.php';

$is_business = !empty($u['is_verified_business']);
$boost_7  = setting('boost_price_7days','29');
$boost_30 = setting('boost_price_30days','79');
$biz_price = setting('verified_business_price','199');
?>

<div class="container" style="padding:40px 20px;max-width:960px;margin:0 auto;">
  <div style="text-align:center;margin-bottom:40px;">
    <h1 style="font-size:2rem;margin-bottom:10px;">Planuri & Prețuri</h1>
    <p style="color:var(--text-3);font-size:1.05rem;">Alege opțiunea care se potrivește nevoilor tale</p>
  </div>

  <!-- Planuri principale -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin-bottom:48px;">

    <!-- Gratuit -->
    <div style="background:var(--surface);border:2px solid var(--border-2);border-radius:var(--radius-lg);padding:28px;position:relative;">
      <div style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">Cont Gratuit</div>
      <div style="font-size:2.4rem;font-weight:800;color:var(--brand);margin:12px 0 4px;">0 RON</div>
      <div style="font-size:13px;color:var(--text-3);margin-bottom:20px;">pentru totdeauna</div>
      <?php $feats = ['Postează anunțuri (foto+video)','Chat direct cu vânzătorii','Salvează anunțuri favorite','Follow vânzători','Comentarii & recenzii','Feed personalizat']; ?>
      <?php foreach ($feats as $f): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:10px;"><span style="color:var(--success);font-weight:700;">✓</span><?= h($f) ?></div>
      <?php endforeach; ?>
      <div style="margin-top:20px;">
        <span class="badge badge-success">Contul tău curent</span>
      </div>
    </div>

    <!-- Boost 7 zile -->
    <div style="background:var(--surface);border:2px solid var(--border);border-radius:var(--radius-lg);padding:28px;">
      <div style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">⭐ Boost anunț</div>
      <div style="display:flex;align-items:baseline;gap:8px;margin:12px 0 4px;">
        <div style="font-size:2.4rem;font-weight:800;color:var(--brand);"><?= $boost_7 ?> RON</div>
        <div style="font-size:13px;color:var(--text-3);">/ 7 zile</div>
      </div>
      <div style="font-size:13px;color:var(--text-3);margin-bottom:20px;">sau <?= $boost_30 ?> RON / 30 zile</div>
      <?php $feats2 = ['Anunțul apare primul în căutări','Badge ⭐ Promovat vizibil','+5x mai multe vizualizări','Poziție TOP în categorie','Activ pe homepage']; ?>
      <?php foreach ($feats2 as $f): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:10px;"><span style="color:var(--brand);font-weight:700;">⭐</span><?= h($f) ?></div>
      <?php endforeach; ?>
      <div style="margin-top:20px;">
        <a href="/cont/anunturile-mele" class="btn btn-primary w-full" style="justify-content:center;">Promovează un anunț</a>
      </div>
    </div>

    <!-- Business Verificat -->
    <div style="background:var(--surface);border:2px solid <?= $is_business?'var(--brand)':'var(--warning)' ?>;border-radius:var(--radius-lg);padding:28px;position:relative;">
      <?php if (!$is_business): ?>
      <div style="position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--warning);color:#fff;font-size:11px;font-weight:700;padding:4px 14px;border-radius:20px;white-space:nowrap;">🏆 RECOMANDAT</div>
      <?php endif; ?>
      <div style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">🏢 Business Verificat</div>
      <div style="display:flex;align-items:baseline;gap:8px;margin:12px 0 4px;">
        <div style="font-size:2.4rem;font-weight:800;color:var(--brand);"><?= $biz_price ?> RON</div>
        <div style="font-size:13px;color:var(--text-3);">/ an</div>
      </div>
      <div style="font-size:13px;color:var(--text-3);margin-bottom:20px;"><?= number_format($biz_price/12,0) ?> RON/lună</div>
      <?php $feats3 = ['Badge ✓ Business verificat','Pagină firmă completă (banner, program)','Prioritate în rezultatele căutare','Anunțuri nelimitate','Suport dedicat','Statistici avansate']; ?>
      <?php foreach ($feats3 as $f): ?>
        <div style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:10px;"><span style="color:var(--success);font-weight:700;">✓</span><?= h($f) ?></div>
      <?php endforeach; ?>
      <div style="margin-top:20px;">
        <?php if ($is_business): ?>
          <div class="alert alert-success" style="font-size:13px;margin:0;">
            ✓ Ești deja Business Verificat!
            <?php if (!empty($u['business_verified_until'])): ?>
              <br>Valabil până: <strong><?= date('d.m.Y', strtotime($u['business_verified_until'])) ?></strong>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <a href="/boost/0?type=business" class="btn btn-primary w-full" style="justify-content:center;background:var(--warning);">Activează Business →</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div style="max-width:680px;margin:0 auto;">
    <h2 style="text-align:center;margin-bottom:24px;">Întrebări frecvente</h2>
    <?php $faqs = [
      ['Cât durează procesarea plății?', 'Plățile prin card (Stripe/Netopia) sunt procesate instant. Boost-ul se activează în câteva secunde.'],
      ['Pot cere rambursarea?', 'Da, în primele 24h de la plată dacă boost-ul nu a generat nicio vizualizare. Contactează-ne.'],
      ['Câte anunțuri pot posta gratuit?', 'Poți posta până la 10 anunțuri active simultan. Business verificat — nelimitat.'],
      ['Datele cardului sunt în siguranță?', 'Nu stocăm datele cardului. Plățile sunt procesate prin Stripe/Netopia, cu criptare SSL 256-bit.'],
      ['Boost-ul funcționează imediat?', 'Da, imediat după confirmarea plății, anunțul tău apare în top rezultate.'],
    ]; ?>
    <?php foreach ($faqs as $i => [$q, $a]): ?>
    <div style="border:1px solid var(--border-2);border-radius:var(--radius-lg);margin-bottom:10px;overflow:hidden;">
      <button onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='block'?'none':'block';this.querySelector('span').textContent=this.nextElementSibling.style.display==='block'?'▲':'▼';"
        style="width:100%;text-align:left;padding:16px 20px;background:var(--surface);border:none;cursor:pointer;font-size:14px;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
        <?= h($q) ?> <span style="color:var(--text-3);">▼</span>
      </button>
      <div style="display:none;padding:16px 20px;background:var(--bg-2);font-size:14px;color:var(--text-2);line-height:1.6;"><?= h($a) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
