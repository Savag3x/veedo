<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = sanitize($_GET['slug'] ?? '');
$ad = DB::fetch("
    SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon, c.slug AS cat_slug,
           u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
           u.rating_avg, u.rating_count, u.ads_count AS seller_ads, u.city AS user_city,
           u.created_at AS user_since, u.phone AS user_phone
    FROM ads a
    JOIN categories c ON c.id = a.category_id
    JOIN users u ON u.id = a.user_id
    WHERE a.slug=? AND a.status='active'
", [$slug]);

if (!$ad) {
    http_response_code(404);
    $seo_title = 'Anunț negăsit';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container text-center" style="padding:80px 20px"><div style="font-size:4rem">😕</div><h2 style="margin:16px 0 8px">Anunțul nu a fost găsit</h2><p style="color:var(--text-3)">A expirat sau a fost șters.</p><a href="/" class="btn btn-primary mt-3">← Înapoi acasă</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Keep original $ad safe - foreach loops below overwrite it
$oad = $ad;

// Track view
$ip_hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
if (!DB::fetch("SELECT id FROM ad_views WHERE ad_id=? AND ip_hash=? AND viewed_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)", [$oad['id'], $ip_hash])) {
    DB::query("INSERT INTO ad_views (ad_id, user_id, ip_hash) VALUES (?,?,?)", [$oad['id'], $_SESSION['user_id'] ?? null, $ip_hash]);
    DB::query("UPDATE ads SET views_count=views_count+1 WHERE id=?", [$oad['id']]);
}

$photos  = DB::fetchAll("SELECT * FROM ad_photos WHERE ad_id=? ORDER BY sort_order", [$oad['id']]);

$ad_custom_fields = [];
try {
    $ad_custom_fields = DB::fetchAll("
        SELECT cf.field_label, cf.field_unit, af.field_value
        FROM ad_fields af
        JOIN category_fields cf ON cf.id = af.field_id
        WHERE af.ad_id=? AND cf.is_active=1 AND af.field_value!=''
        ORDER BY cf.sort_order
    ", [$oad['id']]);
} catch (Exception $e) {}

$reviews = DB::fetchAll("
    SELECT r.*, u.name AS reviewer_name, u.avatar AS reviewer_avatar
    FROM reviews r JOIN users u ON u.id=r.reviewer_id
    WHERE r.reviewed_id=? AND r.is_approved=1
    ORDER BY r.created_at DESC LIMIT 6
", [$oad['user_id']]);

// Anunturi similare: acelasi oras > aceeasi categorie > pret similar
$related = DB::fetchAll("
    SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) AS main_photo,
           (a.city = ? AND a.city != '') AS same_city
    FROM ads a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.user_id
    WHERE a.category_id=? AND a.id!=? AND a.status='active'
    ORDER BY a.is_boosted DESC, same_city DESC, a.views_count DESC, a.created_at DESC
    LIMIT 8
", [$oad['city'] ?? '', $oad['category_id'], $oad['id']]);

$cu       = current_user();
$is_owner = $cu && $cu['id'] == $oad['user_id'];
$is_liked = $cu ? (bool)DB::fetch("SELECT id FROM likes WHERE user_id=? AND ad_id=?", [$cu['id'], $oad['id']]) : false;
$is_faved = $cu ? (bool)DB::fetch("SELECT id FROM favorites WHERE user_id=? AND ad_id=?", [$cu['id'], $oad['id']]) : false;

// Build media
$all_media = [];
if ($oad['video_path']) {
    $all_media[] = ['type'=>'video','src'=>BASE_URL.'/uploads/videos/'.$oad['video_path'],'thumb'=>$oad['video_thumb']?thumb_url($oad['video_thumb']):''];
}
foreach ($photos as $p) {
    $all_media[] = ['type'=>'photo','src'=>photo_url($p['path']),'thumb'=>photo_url($p['path'])];
}

// Price conversion
$price_ron = null;
if ($oad['price']) {
    if ($oad['currency']==='EUR') $price_ron = round($oad['price']*4.97);
    elseif ($oad['currency']==='USD') $price_ron = round($oad['price']*4.55);
    else $price_ron = $oad['price'];
}

$seo_title = $oad['meta_title'] ?: $oad['title'];
$seo_desc  = $oad['meta_description'] ?: mb_substr(strip_tags($oad['description']),0,155);
$seo_image = !empty($all_media) ? $all_media[0]['thumb'] : '';
$seo_url   = BASE_URL.'/anunt/'.$oad['slug'];

include __DIR__ . '/includes/header.php';
?>
<!-- Lightbox -->
<div id="lb" class="lb-overlay" onclick="closeLB()">
    <button class="lb-close" onclick="closeLB()">✕</button>
    <?php if(count($all_media)>1): ?>
    <button class="lb-nav" style="left:16px" onclick="event.stopPropagation();lbNav(-1)">‹</button>
    <button class="lb-nav" style="right:16px" onclick="event.stopPropagation();lbNav(1)">›</button>
    <?php endif; ?>
    <div id="lb-content" onclick="event.stopPropagation()"></div>
    <div id="lb-ctr" class="lb-counter"></div>
    <?php if(count($all_media)>1): ?>
    <div class="lb-thumbs">
        <?php foreach($all_media as $lbi=>$lbm): ?>
        <img class="lb-th <?= $lbi===0?'active':'' ?>" src="<?= h($lbm['thumb']?:$lbm['src']) ?>"
             onclick="event.stopPropagation();curMedia=<?= $lbi ?>;renderLB()" alt="">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.anunt-layout{display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:flex-start;}
.media-main{width:100%;background:#111;border-radius:14px;overflow:hidden;position:relative;cursor:zoom-in;
    /* Adaptiv: 16/9 pe desktop, auto pe mobil pentru verticale */
    aspect-ratio:16/9;
    max-height:70vh;
}
.media-main video{width:100%;height:100%;object-fit:contain;display:block;transition:transform 0.3s;background:#000;}
.media-main img{width:100%;height:100%;object-fit:cover;display:block;transition:transform 0.3s;}
/* Pe mobil: daca e video vertical, lasam sa se adapteze */
/* Mobile sticky bottom bar cu pret + contact */
.mob-sticky-bar {
    display: none;
}
@media(max-width:900px){
    /* Bara de contact pluteste ca o bara separata, peste continut.
       Meniul de jos ramane vizibil pe toate paginile. */
    .mob-sticky-bar {
        display: flex;
        position: fixed;
        bottom: 12px;
        left: 12px; right: 12px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 9px 10px 9px 16px;
        gap: 9px;
        align-items: center;
        z-index: 95;
        box-shadow: 0 12px 30px -10px rgba(20,18,30,0.32);
    }
    .mob-sticky-price {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--brand);
        flex: 1;
        line-height: 1.12;
    }
    .mob-sticky-btn {
        flex-shrink: 0;
    }
    body { padding-bottom: calc(82px + env(safe-area-inset-bottom,0px)) !important; }
}
@media(max-width:768px){
    /* Meniul de jos e prezent — ridicam bara de contact DEASUPRA lui, separat. */
    .mob-sticky-bar { bottom: 92px; }
    body { padding-bottom: calc(164px + env(safe-area-inset-bottom,0px)) !important; }
}
@media(max-width:768px){
    .media-main{aspect-ratio:unset;height:auto;max-height:80vh;min-height:240px;}
    .media-main video{width:100%;height:auto;max-height:80vh;object-fit:contain;}
    .media-main img{width:100%;height:auto;max-height:80vh;object-fit:cover;}
}
.media-thumbs{display:flex;gap:8px;margin-top:10px;overflow-x:auto;scrollbar-width:none;padding-bottom:4px;}
.media-thumbs::-webkit-scrollbar{display:none;}
.media-thumb{flex-shrink:0;width:80px;height:60px;border-radius:8px;overflow:hidden;cursor:pointer;border:2.5px solid transparent;transition:all 0.2s;position:relative;background:var(--bg-3);}
.media-thumb.active{border-color:var(--brand);transform:scale(1.04);}
.media-thumb img{width:100%;height:100%;object-fit:cover;}
.media-thumb:hover{border-color:var(--brand);opacity:0.9;}
.mnav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(10,10,14,.5);color:#fff;border:1px solid rgba(255,255,255,.2);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);width:40px;height:40px;border-radius:50%;cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 12px rgba(0,0,0,0.2);transition:all 0.15s;z-index:2;}
.mnav:hover{background:var(--accent);color:var(--on-accent);transform:translateY(-50%) scale(1.1);}
/* Lightbox */
.lb-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.95);z-index:99999;display:none;align-items:center;justify-content:center;flex-direction:column;}
.lb-overlay.open{display:flex;}
.lb-img{max-width:90vw;max-height:85vh;object-fit:contain;border-radius:8px;user-select:none;}
.lb-video{max-width:90vw;max-height:85vh;border-radius:8px;}
.lb-close{position:absolute;top:16px;right:20px;color:#fff;font-size:2rem;cursor:pointer;background:none;border:none;line-height:1;opacity:0.8;}
.lb-close:hover{opacity:1;}
.lb-nav{position:absolute;top:50%;transform:translateY(-50%);color:#fff;font-size:2.5rem;cursor:pointer;background:rgba(255,255,255,0.1);border:none;width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:background 0.15s;}
.lb-nav:hover{background:rgba(255,255,255,0.25);}
.lb-counter{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.7);font-size:13px;font-weight:600;}
.lb-thumbs{display:flex;gap:6px;position:absolute;bottom:50px;left:50%;transform:translateX(-50%);max-width:90vw;overflow-x:auto;scrollbar-width:none;}
.lb-thumbs::-webkit-scrollbar{display:none;}
.lb-th{width:52px;height:40px;border-radius:4px;object-fit:cover;cursor:pointer;opacity:0.5;border:2px solid transparent;transition:all 0.15s;flex-shrink:0;}
.lb-th.active,.lb-th:hover{opacity:1;border-color:#fff;}
.sticky-sidebar{position:sticky;top:76px;}
.ccard{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:14px;}
.price-big{font-size:1.9rem;font-weight:800;color:var(--brand);font-family:var(--font-display);line-height:1.1;}
.apill{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:100px;border:1.5px solid var(--border);background:var(--surface);font-size:13px;color:var(--text-2);cursor:pointer;transition:all 0.18s;font-family:var(--font-main);}
.apill:hover,.apill:focus{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.apill.liked{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.apill.saved{border-color:var(--info);color:var(--info);}
.itable{width:100%;font-size:14px;border-collapse:collapse;}
.itable tr{border-bottom:1px solid var(--border-2);}
.itable tr:last-child{border-bottom:none;}
.itable td{padding:10px 0;}
.itable td:first-child{color:var(--text-3);width:45%;}
.itable td:last-child{font-weight:500;}
.scard{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:14px;}
.scard h2{font-size:1rem;font-weight:700;margin-bottom:14px;}
@media(max-width:900px){.anunt-layout{grid-template-columns:1fr;}.sticky-sidebar{position:static;}}
</style>

<nav style="border-bottom:1px solid var(--border);padding:10px 0;background:var(--bg);">
  <div class="container" style="font-size:13px;color:var(--text-3);display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
    <a href="/" style="color:var(--text-3)">Acasă</a><span>/</span>
    <a href="/categorie/<?= h($oad['cat_slug']) ?>" style="color:var(--text-3)"><?= h($oad['cat_icon']) ?> <?= h($oad['cat_name']) ?></a><span>/</span>
    <span style="color:var(--text)"><?= h(mb_substr($oad['title'],0,60)) ?></span>
  </div>
</nav>

<div class="container" style="padding:20px;max-width:1200px;">
<!-- CSRF global pentru butoanele AJAX -->
<?= csrf_field() ?>
<div class="anunt-layout">

<!-- STANGA -->
<div>

<?php if (!empty($all_media)): ?>
<div style="margin-bottom:20px;">
  <div class="media-main" id="mmain">
    <?php $fm=$all_media[0]; ?>
    <?php if($fm['type']==='video'): ?>
      <video controls autoplay muted playsinline poster="<?= h($fm['thumb']) ?>" style="width:100%;height:100%;object-fit:cover;">
        <source src="<?= h($fm['src']) ?>" type="video/mp4">
      </video>
    <?php else: ?>
      <img src="<?= h($fm['src']) ?>" alt="<?= h($oad['title']) ?>">
    <?php endif; ?>
    <div style="position:absolute;bottom:12px;left:12px;background:rgba(0,0,0,0.6);color:#fff;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;" id="mcounter">
      <?= $fm['type']==='video'?'🎬':'📷' ?> 1 / <?= count($all_media) ?>
    </div>
    <?php if(count($all_media)>1): ?>
    <button class="mnav" style="left:10px;" onclick="chgMedia(-1)">‹</button>
    <button class="mnav" style="right:10px;" onclick="chgMedia(1)">›</button>
    <?php endif; ?>
  </div>
  <?php if(count($all_media)>1): ?>
  <div class="media-thumbs">
    <?php foreach($all_media as $mi=>$mm): ?>
    <div class="media-thumb <?= $mi===0?'active':'' ?>" onclick="setMedia(<?= $mi ?>)" id="mth<?= $mi ?>">
      <img src="<?= h($mm['thumb']?:$mm['src']) ?>" alt="">
      <?php if($mm['type']==='video'): ?>
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="white"><path d="M5 3l14 9-14 9V3z"/></svg>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="background:var(--bg-3);border-radius:12px;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;font-size:5rem;margin-bottom:20px;"><?= h($oad['cat_icon']) ?></div>
<?php endif; ?>

<!-- TITLU -->
<div class="scard">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <div style="flex:1;">
      <div style="margin-bottom:8px;">
        <span style="background:<?= h($oad['cat_color']) ?>;color:#fff;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;"><?= h($oad['cat_icon']) ?> <?= h($oad['cat_name']) ?></span>
        <?php if($oad['is_boosted']): ?><span style="background:#FEF3C7;color:#92400E;font-size:12px;font-weight:600;padding:3px 12px;border-radius:20px;margin-left:4px;">⭐ Promovat</span><?php endif; ?>
      </div>
      <h1 style="font-size:1.3rem;font-weight:800;line-height:1.3;color:var(--text);"><?= h($oad['title']) ?></h1>
    </div>

  </div>
  <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:var(--text-3);margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border-2);">
    <?php if($oad['city']): ?><span>📍 <?= h($oad['city']) ?><?= $oad['county']?', '.h($oad['county']):'' ?></span><?php endif; ?>
    <span>👁 <?= number_format($oad['views_count']) ?> vizualizări</span>
    <span>❤️ <?= number_format($oad['likes_count']) ?> like-uri</span>
    <?php if(!empty($oad['bumped_at'])): ?>
      <span>🔄 Reactualizat <?= reactualizat_text($oad['bumped_at']) ?></span>
    <?php else: ?>
      <span>🕐 <?= time_ago($oad['created_at']) ?></span>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button class="apill like-btn <?= $is_liked?'liked':'' ?>" data-ad-id="<?= (int)$oad['id'] ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="<?= $is_liked?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      <span class="like-count"><?= number_format($oad['likes_count']) ?></span> Like
    </button>
    <button class="apill fav-btn <?= $is_faved?'saved':'' ?>" data-ad-id="<?= (int)$oad['id'] ?>">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="<?= $is_faved?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
      <span class="fav-label"><?= $is_faved?'Salvat':'Salvează' ?></span>
    </button>
    <button class="apill" onclick="if(navigator.share){navigator.share({title:'<?= addslashes(h($oad['title'])) ?>',url:'<?= h($seo_url) ?>'})}else{navigator.clipboard.writeText('<?= h($seo_url) ?>').then(function(){alert('Link copiat!')})}">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
      Distribuie
    </button>
    <?php if(!$is_owner && $cu): ?>
    <button class="apill" onclick="var s=document.getElementById('report-wrap');s.style.display=s.style.display==='none'?'block':'none'">
      ⚠️ Raportează
    </button>
    <?php endif; ?>
  </div>

  <?php if(!$is_owner && $cu): ?>
  <div id="report-wrap" style="display:none;margin-top:12px;background:var(--bg-2);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;">
    <h3 style="font-size:1rem;margin-bottom:14px;">🚨 Raportează anunțul</h3>
    <form action="/api/report.php" method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="ad_id" value="<?= (int)$oad['id'] ?>">
      <div class="form-group">
        <label class="form-label">Motiv</label>
        <select name="reason" class="form-control" required>
          <option value="spam">Spam / Duplicat</option>
          <option value="fake">Anunț fals</option>
          <option value="inappropriate">Conținut inadecvat</option>
          <option value="scam">Tentativă de fraudă</option>
          <option value="other">Altul</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Detalii (opțional)</label>
        <textarea name="details" class="form-control" rows="3" placeholder="Descrie problema..."></textarea>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="btn btn-danger">Trimite raport</button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('report-wrap').style.display='none'">Anulează</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if(!empty($ad_custom_fields)): ?>
<div class="scard">
  <h2>📊 Specificații</h2>
  <table class="itable">
    <?php foreach($ad_custom_fields as $cf): ?>
    <tr><td><?= h($cf['field_label']) ?></td><td><?= h($cf['field_value']) ?><?= $cf['field_unit']?' <small style="color:var(--text-3)">'.h($cf['field_unit']).'</small>':'' ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="scard">
  <h2>📄 Descriere</h2>
  <div style="font-size:15px;line-height:1.8;color:var(--text-2);white-space:pre-line;"><?= h($oad['description']) ?></div>
</div>

<div class="scard">
  <h2>ℹ️ Informații anunț</h2>
  <table class="itable">
    <tr><td>Categorie</td><td><?= h($oad['cat_icon']) ?> <?= h($oad['cat_name']) ?></td></tr>
    <?php if($oad['city']): ?><tr><td>Locație</td><td>📍 <?= h($oad['city']) ?><?= $oad['county']?', '.h($oad['county']):'' ?></td></tr><?php endif; ?>
    <?php if($oad['video_path']): ?><tr><td>Tip</td><td>🎬 Cu video</td></tr><?php endif; ?>
    <tr><td>Postat</td><td><?= date('d.m.Y',strtotime($oad['created_at'])) ?></td></tr>
    <?php if(!empty($oad['bumped_at'])): ?>
    <tr><td>Reactualizat</td><td><?= reactualizat_text($oad['bumped_at']) ?></td></tr>
    <?php endif; ?>
    <tr><td>Valabil până</td><td><?= $oad['expires_at']?date('d.m.Y',strtotime($oad['expires_at'])):'Nedefinit' ?></td></tr>
  </table>
</div>

<?php if(!empty($reviews)): ?>
<div class="scard">
  <h2>⭐ Recenzii vânzător
    <span style="font-size:13px;font-weight:400;color:var(--text-3);"><?= number_format($oad['rating_avg'],1) ?>/5 · <?= $oad['rating_count'] ?> recenzii</span>
  </h2>
  <?php foreach($reviews as $rv): ?>
  <div style="padding:12px 0;border-bottom:1px solid var(--border-2);display:flex;gap:12px;">
    <img src="<?= h(avatar_url($rv['reviewer_avatar'],$rv['reviewer_name'])) ?>" width="38" height="38" style="border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
    <div style="flex:1;">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <strong style="font-size:14px;"><?= h($rv['reviewer_name']) ?></strong>
        <span style="color:var(--brand2);font-size:13px;"><?= str_repeat('★',(int)$rv['rating']).str_repeat('☆',5-(int)$rv['rating']) ?></span>
        <span style="font-size:12px;color:var(--text-3);margin-left:auto;"><?= time_ago($rv['created_at']) ?></span>
      </div>
      <?php if($rv['comment']): ?><p style="font-size:14px;color:var(--text-2);margin-top:5px;line-height:1.5;"><?= h($rv['comment']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<?php if(!$is_owner && $cu): ?>
<?php $can_review = !DB::fetch("SELECT id FROM reviews WHERE reviewer_id=? AND reviewed_id=?",[$cu['id'],$oad['user_id']]); ?>
<?php if($can_review): ?>
<div class="scard">
  <h2>✍️ Lasă o recenzie</h2>
  <form action="/api/review.php" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="reviewed_id" value="<?= (int)$oad['user_id'] ?>">
    <input type="hidden" name="ad_id" value="<?= (int)$oad['id'] ?>">
    <div class="form-group">
      <label class="form-label">Rating *</label>
      <div style="display:flex;gap:4px;font-size:2rem;cursor:pointer;" id="stars">
        <?php for($s=1;$s<=5;$s++): ?>
        <span data-v="<?= $s ?>" style="opacity:0.25;transition:opacity 0.1s;">★</span>
        <?php endfor; ?>
        <input type="hidden" name="rating" id="rating-val" required>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Comentariu</label>
      <textarea name="comment" class="form-control" rows="3" placeholder="Experiența ta cu vânzătorul..."></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Trimite recenzia</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if(!empty($related)): ?>
<div style="margin-top:8px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <h2 style="font-size:1rem;font-weight:700;">🔍 Anunțuri similare</h2>
      <a href="/categorie/<?= h($oad['cat_slug']) ?>" style="font-size:13px;color:var(--brand);text-decoration:none;">
          Vezi toate din <?= h($oad['cat_name']) ?> →
      </a>
  </div>
  <div class="items-grid">
    <?php foreach($related as $ad): include __DIR__ . '/includes/ad-card.php'; endforeach; ?>
  </div>
</div>
<?php $ad = $oad; ?>
<?php endif; ?>



</div><!-- /stanga -->

<!-- SIDEBAR -->
<div class="sticky-sidebar">

<div class="ccard">
  <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-3);margin-bottom:12px;">
    <?= $oad['is_verified_business'] ? '🏢 Business verificat' : '👤 Vânzător privat' ?>
  </div>
  <a href="/utilizator/<?= (int)$oad['user_id'] ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--border-2);">
    <img src="<?= h(avatar_url($oad['user_avatar'],$oad['user_name'])) ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0;" alt="">
    <div style="min-width:0;">
      <div style="font-weight:700;color:var(--text);font-size:15px;display:flex;align-items:center;gap:5px;">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($oad['user_name']) ?></span>
        <?php if($oad['is_verified_business']): ?>
        <svg viewBox="0 0 24 24" fill="none" style="width:15px;height:15px;flex-shrink:0;" aria-label="Vânzător verificat"><title>Vânzător verificat</title><circle cx="12" cy="12" r="11" fill="#FF6B35"/><path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:var(--text-3);margin-top:3px;display:flex;flex-wrap:wrap;gap:3px 8px;align-items:center;">
        <?php if($oad['rating_count']>0): ?>
        <span style="color:var(--brand2);font-weight:600;">★ <?= number_format($oad['rating_avg'],1) ?> <span style="color:var(--text-3);font-weight:400;">(<?= (int)$oad['rating_count'] ?>)</span></span>
        <?php endif; ?>
        <?php if((int)$oad['seller_ads']>0): ?><span><?= (int)$oad['seller_ads'] ?> anunțuri</span><?php endif; ?>
        <span>din <?= date('Y',strtotime($oad['user_since'])) ?></span>
      </div>
    </div>
  </a>
  <?php if($is_owner): ?>
    <a href="/anunt/<?= h($oad['slug']) ?>/editeaza" class="btn btn-secondary w-full" style="justify-content:center;margin-bottom:8px;">✏️ Editează anunțul</a>
    <?php
      $bump_ref     = !empty($oad['bumped_at']) ? $oad['bumped_at'] : $oad['created_at'];
      $bump_elapsed = time() - strtotime($bump_ref);
    ?>
    <?php if($oad['status']==='active' && $bump_elapsed >= 86400): ?>
      <form action="/api/reactualizare.php" method="POST" style="margin-bottom:8px;">
        <?= csrf_field() ?>
        <input type="hidden" name="ad_id" value="<?= (int)$oad['id'] ?>">
        <button type="submit" class="btn btn-secondary w-full" style="justify-content:center;">🔄 Reactualizează anunțul</button>
      </form>
    <?php elseif($oad['status']==='active'): ?>
      <?php $bump_h = (int)ceil((86400 - $bump_elapsed)/3600); ?>
      <button class="btn btn-secondary w-full" disabled style="justify-content:center;margin-bottom:8px;opacity:.55;cursor:not-allowed;">🔄 Reactualizare disponibilă în <?= $bump_h ?> h</button>
    <?php endif; ?>
    <?php if(!$oad['is_boosted']): ?><a href="/boost/<?= (int)$oad['id'] ?>" class="btn w-full" style="background:var(--brand2);color:#fff;justify-content:center;">⭐ Promovează</a><?php endif; ?>
  <?php elseif($cu): ?>
    <form action="/api/start-chat.php" method="POST" style="margin-bottom:10px;">
      <?= csrf_field() ?>
      <input type="hidden" name="ad_id" value="<?= (int)$oad['id'] ?>">
      <button type="submit" class="btn btn-primary w-full" style="justify-content:center;font-size:15px;">💬 Trimite mesaj</button>
    </form>
    <?php if($oad['user_phone']): ?>
    <div id="phone-wrap" style="margin-bottom:8px;">
      <button onclick="revealPhone(<?= (int)$oad['id'] ?>)" id="phone-btn" class="btn btn-secondary w-full" style="justify-content:center;font-size:14px;">
        📞 <?= h(maskPhone($oad['user_phone'])) ?> · Apasă pentru a vedea
      </button>
    </div>
    <?php endif; ?>
    <a href="/utilizator/<?= (int)$oad['user_id'] ?>" class="btn btn-ghost w-full" style="justify-content:center;font-size:13px;">Vezi toate anunțurile →</a>
  <?php else: ?>
    <a href="/login?next=<?= urlencode('/anunt/'.$oad['slug']) ?>" class="btn btn-primary w-full" style="justify-content:center;">💬 Contactează vânzătorul</a>
    <p style="font-size:12px;color:var(--text-3);text-align:center;margin-top:8px;">Trebuie să fii conectat</p>
  <?php endif; ?>
</div>

<?php if($oad['price']): ?>
<div class="ccard" style="text-align:center;">
  <div class="price-big"><?= h(format_price((float)$oad['price'],$oad['currency'])) ?></div>
  <?php if($price_ron && $oad['currency']!=='RON'): ?><div style="font-size:13px;color:var(--text-3);margin-top:3px;">≈ <?= number_format($price_ron,0,',','.') ?> RON</div><?php endif; ?>
  <?php if($oad['price_negotiable']): ?><div style="font-size:13px;color:var(--success);margin-top:6px;font-weight:500;">✓ Preț negociabil</div><?php endif; ?>
</div>
<?php endif; ?>

<div class="ccard">
  <div style="font-size:12px;color:var(--text-3);margin-bottom:10px;font-weight:600;">Distribuie anunțul</div>
  <div style="display:flex;gap:8px;">
    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($seo_url) ?>" target="_blank" class="btn btn-primary" style="flex:1;justify-content:center;font-size:12px;background:#1877F2;border-color:#1877F2;">Facebook</a>
    <a href="https://wa.me/?text=<?= urlencode($oad['title'].' '.$seo_url) ?>" target="_blank" class="btn btn-primary" style="flex:1;justify-content:center;font-size:12px;background:#25D366;border-color:#25D366;">WhatsApp</a>
    <button onclick="navigator.clipboard.writeText('<?= h($seo_url) ?>').then(function(){this.textContent='✓';}.bind(this))" class="btn btn-secondary" style="width:40px;flex-shrink:0;justify-content:center;padding:8px;">🔗</button>
  </div>
</div>

</div><!-- /sidebar -->
</div><!-- /layout -->
</div><!-- /container -->

<?php
$_ld = [
    '@context' => 'https://schema.org', '@type' => 'Product',
    'name' => $oad['title'],
    'description' => mb_substr(strip_tags($oad['description']), 0, 300),
    'offers' => [
        '@type' => 'Offer', 'price' => (string)($oad['price'] ?? 0), 'priceCurrency' => $oad['currency'],
        'availability' => 'https://schema.org/InStock', 'url' => $seo_url,
    ],
];
if ($seo_image) $_ld['image'] = [$seo_image];
?>
<script type="application/ld+json"><?= json_ld($_ld) ?></script>
<?= seo_breadcrumb([
    ['name'=>setting('site_name'),'url'=>BASE_URL],
    ['name'=>$oad['cat_name'],'url'=>BASE_URL.'/categorie/'.$oad['cat_slug']],
    ['name'=>mb_substr($oad['title'],0,60),'url'=>$seo_url]
]) ?>

<script>
var mediaArr = <?= js_json(array_map(function($m){ return ['type'=>$m['type'],'src'=>$m['src'],'thumb'=>$m['thumb']?$m['thumb']:$m['src']]; }, $all_media)) ?>;
var curM = 0;

function setMedia(idx) {
    if(idx<0) idx=mediaArr.length-1;
    if(idx>=mediaArr.length) idx=0;
    curM=idx; curMedia=idx;
    var m=mediaArr[idx];
    var wrap=document.getElementById('mmain');
    if(!wrap) return;
    var nav=mediaArr.length>1?'<button class="mnav" style="left:10px;" onclick="chgMedia(-1)">&#8249;</button><button class="mnav" style="right:10px;" onclick="chgMedia(1)">&#8250;</button>':'';
    var badge='<div style="position:absolute;bottom:12px;left:12px;background:rgba(0,0,0,0.6);color:#fff;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;">'+(m.type==='video'?'🎬':'📷')+' '+(idx+1)+' / '+mediaArr.length+'</div>';
    if(m.type==='video'){
        wrap.innerHTML='<video controls autoplay muted playsinline style="width:100%;height:100%;object-fit:cover;display:block;"><source src="'+m.src+'" type="video/mp4"></video>'+badge+nav;
    } else {
        wrap.innerHTML='<img src="'+m.src+'" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">'+badge+nav;
    }
    document.querySelectorAll('.media-thumb').forEach(function(t,i){ t.classList.toggle('active',i===idx); });
    var th=document.getElementById('mth'+idx);
    if(th) th.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
}
function chgMedia(d){ setMedia(curM+d); }
document.addEventListener('keydown',function(e){ if(e.key==='ArrowLeft') chgMedia(-1); if(e.key==='ArrowRight') chgMedia(1); });

// Phone reveal
function revealPhone(adId) {
    var btn  = document.getElementById('phone-btn');
    var wrap = document.getElementById('phone-wrap');

    var loadingEl = btn || document.querySelector('[onclick*="revealPhone"]');
    if (loadingEl) { loadingEl.disabled = true; loadingEl.textContent = 'Se încarcă...'; }

    fetch('/api/reveal-phone.php?ad_id='+adId, {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.phone) {
                // Actualizam sidebar daca exista
                if (wrap) {
                    wrap.innerHTML = '<a href="tel:'+escHtml(d.phone_clean)+'" class="btn btn-secondary w-full" style="justify-content:center;font-size:16px;font-weight:700;letter-spacing:1px;">📞 '+escHtml(d.phone)+'</a>';
                }
                // Pe mobile: afisam un popup simplu si redirectam catre tel:
                if (window.innerWidth <= 900) {
                    // Cream popup
                    var existing = document.getElementById('phone-popup');
                    if (existing) existing.remove();
                    var popup = document.createElement('div');
                    popup.id = 'phone-popup';
                    popup.style.cssText = 'position:fixed;left:16px;right:16px;top:50%;transform:translateY(-50%);max-width:380px;margin-left:auto;margin-right:auto;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;z-index:9999;box-shadow:0 16px 48px -8px rgba(20,18,30,0.4);text-align:center;';
                    var closeBtn = document.createElement('button');
                    closeBtn.textContent = 'Închide';
                    closeBtn.style.cssText = 'background:none;border:none;color:var(--text-3);cursor:pointer;font-size:13px;width:100%;margin-top:4px;';
                    closeBtn.onclick = function(){ popup.remove(); };
                    popup.innerHTML = '<div style="font-size:13px;color:var(--text-3);margin-bottom:10px;">Număr de telefon vânzător</div>'
                        + '<a href="tel:'+escHtml(d.phone_clean)+'" style="display:block;font-size:1.4rem;font-weight:800;color:var(--brand);text-decoration:none;margin-bottom:14px;">📞 '+escHtml(d.phone)+'</a>'
                        + '<a href="tel:'+escHtml(d.phone_clean)+'" class="btn btn-primary" style="width:100%;justify-content:center;display:flex;margin-bottom:8px;">Sună acum</a>';
                    popup.appendChild(closeBtn);
                    document.body.appendChild(popup);
                    // Auto-close dupa 15 secunde
                    setTimeout(function(){ if(popup.parentNode) popup.remove(); }, 15000);
                }
                // Resetam butonul
                if (loadingEl && loadingEl.id !== 'phone-btn') {
                    loadingEl.disabled = false;
                    loadingEl.innerHTML = '📞';
                }
            } else {
                if (loadingEl) { loadingEl.textContent = d.error||'Număr indisponibil'; loadingEl.disabled = false; }
            }
        })
        .catch(function(){
            if (loadingEl) { loadingEl.textContent = 'Eroare. Încearcă din nou.'; loadingEl.disabled = false; }
        });
}

// Star rating
var stars=document.querySelectorAll('#stars span');
stars.forEach(function(s,i){
    s.addEventListener('mouseover',function(){ stars.forEach(function(x,j){ x.style.opacity=j<=i?'1':'0.25'; }); });
    s.addEventListener('click',function(){ document.getElementById('rating-val').value=s.dataset.v; stars.forEach(function(x,j){ x.style.opacity=j<=i?'1':'0.25'; }); });
});
document.getElementById('stars') && document.getElementById('stars').addEventListener('mouseleave',function(){
    var v=document.getElementById('rating-val').value;
    stars.forEach(function(x,j){ x.style.opacity=j<v?'1':'0.25'; });
});
</script>

<!-- COMENTARII -->
<div class="container" style="max-width:1200px;padding:0 20px 40px;">
  <div style="max-width:760px;">
    <div class="scard">
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;">💬 Comentarii <span id="comments-count" style="font-size:13px;font-weight:400;color:var(--text-3);"></span></h2>

      <?php if($cu): ?>
      <div style="display:flex;gap:10px;margin-bottom:20px;align-items:flex-start;">
        <img src="<?= h(avatar_url($cu['avatar']??null,$cu['name'])) ?>" width="36" height="36" style="border-radius:50%;flex-shrink:0;object-fit:cover;" alt="">
        <div style="flex:1;">
          <textarea id="new-comment" class="form-control" rows="2" placeholder="Adaugă un comentariu public..." style="resize:none;font-size:14px;" maxlength="1000"></textarea>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
            <span style="font-size:12px;color:var(--text-3);" id="comment-chars">0/1000</span>
            <button onclick="postComment()" class="btn btn-primary btn-sm" id="comment-btn">Publică</button>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div style="background:var(--bg-2);border-radius:10px;padding:14px;text-align:center;margin-bottom:16px;font-size:14px;color:var(--text-3);">
        <a href="/login?next=<?= urlencode('/anunt/'.$oad['slug']) ?>" style="color:var(--brand);font-weight:600;">Conectează-te</a> pentru a lăsa un comentariu
      </div>
      <?php endif; ?>

      <div id="comments-list">
        <div style="text-align:center;padding:20px;color:var(--text-3);font-size:13px;">Se încarcă comentariile...</div>
      </div>
    </div>
  </div>
</div>

<script>
const AD_ID = <?= (int)$oad['id'] ?>;
const CURRENT_USER_ID = <?= $cu ? (int)$cu['id'] : 'null' ?>;
// Citeste CSRF din meta tag (disponibil pe toate paginile) sau din input hidden
const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
          || document.querySelector('[name=csrf_token]')?.value
          || '';

// Load comments on page load
loadComments();

async function loadComments() {
    try {
        const r = await fetch('/api/comment.php?ad_id=' + AD_ID);
        const d = await r.json();
        renderComments(d.comments || []);
        const el = document.getElementById('comments-count');
        if (el) el.textContent = '(' + (d.total||0) + ')';
    } catch(e) { document.getElementById('comments-list').innerHTML = ''; }
}

function renderComments(comments) {
    const list = document.getElementById('comments-list');
    if (!comments.length) {
        list.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-3);font-size:14px;">Niciun comentariu încă. Fii primul! 💬</div>';
        return;
    }
    list.innerHTML = comments.map(renderComment).join('');
}

function renderComment(cm, isReply) {
    const avatarUrl = escHtml(cm.avatar_url || '');

    const verified = cm.is_verified_business ? ' <span style="color:var(--info);font-size:11px;">✓</span>' : '';
    const deleteBtn = (CURRENT_USER_ID && CURRENT_USER_ID == cm.user_id)
        ? `<button onclick="deleteComment(${cm.id})" style="background:none;border:none;color:var(--text-3);font-size:11px;cursor:pointer;padding:0;">Șterge</button>` : '';

    const repliesHtml = (!isReply && cm.replies && cm.replies.length)
        ? '<div style="margin-top:10px;padding-left:20px;border-left:2px solid var(--border-2);">'
          + cm.replies.map(r => renderComment(r, true)).join('')
          + '</div>'
        : '';

    const replyBtn = !isReply
        ? `<button onclick="showReply(${cm.id})" style="background:none;border:none;color:var(--text-3);font-size:12px;cursor:pointer;padding:0;">↩ Răspunde</button>` : '';

    return `<div id="comment-${cm.id}" style="display:flex;gap:10px;margin-bottom:16px;">
        <img src="${avatarUrl}" width="34" height="34" loading="lazy" style="border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                <strong style="font-size:13px;">${escHtml(cm.user_name)}${verified}</strong>
                <span style="font-size:11px;color:var(--text-3);">${escHtml(cm.time_ago)}</span>
                <span style="margin-left:auto;display:flex;gap:8px;">${replyBtn} ${deleteBtn}</span>
            </div>
            <div style="font-size:14px;line-height:1.5;color:var(--text-2);">${escHtml(cm.body)}</div>
            <div id="reply-form-${cm.id}" style="display:none;margin-top:10px;">
                <div style="display:flex;gap:8px;">
                    <textarea class="form-control" rows="2" placeholder="Scrie un răspuns..." style="flex:1;font-size:13px;resize:none;" id="reply-text-${cm.id}" maxlength="500"></textarea>
                    <button onclick="postReply(${cm.id})" class="btn btn-primary btn-sm" style="align-self:flex-end;">Trimite</button>
                </div>
            </div>
            ${repliesHtml}
        </div>
    </div>`;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function postComment() {
    const textarea = document.getElementById('new-comment');
    const body = textarea.value.trim();
    if (!body) return;
    const btn = document.getElementById('comment-btn');
    btn.disabled = true; btn.textContent = 'Se publică...';
    try {
        const fd = new FormData();
        fd.append('ad_id', AD_ID);
        fd.append('body', body);
        fd.append('csrf_token', CSRF);
        const r = await fetch('/api/comment.php', {method:'POST', body:fd, credentials:'same-origin'});
        const d = await r.json();
        if (d.error) { alert(d.error); return; }
        textarea.value = '';
        document.getElementById('comment-chars').textContent = '0/1000';
        loadComments();
    } catch(e) { alert('Eroare. Încearcă din nou.'); }
    finally { btn.disabled = false; btn.textContent = 'Publică'; }
}

function showReply(commentId) {
    const form = document.getElementById('reply-form-' + commentId);
    if (form) {
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
        if (form.style.display === 'block') document.getElementById('reply-text-' + commentId)?.focus();
    }
}

async function postReply(parentId) {
    const textarea = document.getElementById('reply-text-' + parentId);
    const body = textarea?.value.trim();
    if (!body) return;
    const fd = new FormData();
    fd.append('ad_id', AD_ID);
    fd.append('body', body);
    fd.append('parent_id', parentId);
    fd.append('csrf_token', CSRF);
    try {
        const r = await fetch('/api/comment.php', {method:'POST', body:fd, credentials:'same-origin'});
        const d = await r.json();
        if (d.error) { alert(d.error); return; }
        loadComments();
    } catch(e) { alert('Eroare. Încearcă din nou.'); }
}

async function deleteComment(commentId) {
    if (!confirm('Ștergi comentariul?')) return;
    try {
        const r = await fetch('/api/comment.php?id=' + commentId, {
            method:'DELETE',
            credentials:'same-origin',
            headers: {'X-CSRF-Token': CSRF}
        });
        const d = await r.json();
        if (d.success) {
            const el = document.getElementById('comment-' + commentId);
            if (el) el.remove();
        }
    } catch(e) {}
}

// Character counter
const newComment = document.getElementById('new-comment');
if (newComment) {
    newComment.addEventListener('input', function() {
        document.getElementById('comment-chars').textContent = this.value.length + '/1000';
    });
    newComment.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'Enter') postComment();
    });
}

const media = <?= js_json(array_map(fn($m) => ['type'=>$m['type'],'src'=>$m['src'],'thumb'=>$m['thumb']], $all_media), JSON_UNESCAPED_UNICODE) ?>;
let curMedia = 0;

// Lightbox
function openLB(idx) {
    curMedia = idx; curM = idx;
    renderLB();
    document.getElementById('lb').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLB() {
    document.getElementById('lb').classList.remove('open');
    document.body.style.overflow = '';
    const v = document.getElementById('lb-content').querySelector('video');
    if (v) v.pause();
}
function renderLB() {
    const m = media[curMedia];
    const el = document.getElementById('lb-content');
    if (m.type === 'video') {
        el.innerHTML = '<video class="lb-video" src="'+m.src+'" controls autoplay playsinline></video>';
    } else {
        el.innerHTML = '<img class="lb-img" src="'+m.src+'" alt="" onclick="event.stopPropagation()" ondragstart="return false">';
    }
    // Update thumbs
    document.querySelectorAll('.lb-th').forEach((t,i) => t.classList.toggle('active', i===curMedia));
    document.getElementById('lb-ctr').textContent = (curMedia+1) + ' / ' + media.length;
}
function lbNav(dir) {
    const v = document.getElementById('lb-content').querySelector('video');
    if (v) v.pause();
    curMedia = (curMedia + dir + media.length) % media.length;
    renderLB();
}
// Touch swipe pentru lightbox
let lbTouchX = 0;
document.getElementById('lb')?.addEventListener('touchstart', e => { lbTouchX = e.touches[0].clientX; }, {passive:true});
document.getElementById('lb')?.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - lbTouchX;
    if (Math.abs(dx) > 50) lbNav(dx < 0 ? 1 : -1);
});
// Keyboard
document.addEventListener('keydown', e => {
    if (!document.getElementById('lb').classList.contains('open')) return;
    if (e.key === 'ArrowRight') lbNav(1);
    if (e.key === 'ArrowLeft')  lbNav(-1);
    if (e.key === 'Escape') closeLB();
});

// Touch swipe pe galerie principala
let touchX = 0;
document.getElementById('mmain')?.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, {passive:true});
document.getElementById('mmain')?.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 40) chgMedia(dx < 0 ? 1 : -1);
});

// Click pe galerie -> lightbox
document.getElementById('mmain')?.addEventListener('click', function(e) {
    if (e.target.closest('.mnav')) return;
    openLB(curMedia);
});

// Re-init like/fav - asiguram ca butoanele sunt gasite dupa ce tot DOM-ul e gata
if (typeof initLikes === 'function') initLikes();
if (typeof initFavorites === 'function') initFavorites();
</script>

<!-- Mobile sticky bottom bar -->
<?php if (!$is_owner): ?>
<div class="mob-sticky-bar">
    <?php if ($oad['price']): ?>
    <div class="mob-sticky-price"><?= h(format_price((float)$oad['price'], $oad['currency'])) ?></div>
    <?php else: ?>
    <div class="mob-sticky-price" style="font-size:1rem;color:var(--text-2);">Preț la cerere</div>
    <?php endif; ?>

    <?php if ($cu): ?>
    <form action="/api/start-chat.php" method="POST" class="mob-sticky-btn">
        <?= csrf_field() ?>
        <input type="hidden" name="ad_id" value="<?= (int)$oad['id'] ?>">
        <button type="submit" class="btn btn-primary" style="padding:10px 20px;font-size:14px;">
            💬 Contactează
        </button>
    </form>
    <?php if ($oad['user_phone']): ?>
    <button onclick="revealPhone(<?= (int)$oad['id'] ?>)" class="btn btn-secondary mob-sticky-btn" style="padding:10px 16px;">
        📞
    </button>
    <?php endif; ?>
    <?php else: ?>
    <a href="/login?next=<?= urlencode('/anunt/'.$oad['slug']) ?>" class="btn btn-primary mob-sticky-btn" style="padding:10px 20px;">
        💬 Contactează
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.Veedo && Veedo.recordView) {
    Veedo.recordView({
      id: <?= (int)$oad['id'] ?>,
      slug: <?= js_json($oad['slug'], JSON_UNESCAPED_UNICODE) ?>,
      title: <?= js_json($oad['title'], JSON_UNESCAPED_UNICODE) ?>,
      img: <?= js_json($all_media[0]['thumb'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
      price: <?= js_json($oad['price'] ? format_price((float)$oad['price'], $oad['currency']) : 'Preț la cerere', JSON_UNESCAPED_UNICODE) ?>
    });
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>