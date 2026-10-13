<?php
require_once __DIR__ . '/includes/bootstrap.php';

$seo_title = setting('site_name') . ' — ' . setting('site_tagline','Anunțuri video. Simplu. Rapid.');
$seo_desc  = setting('site_description');

$categories = DB::fetchAll("SELECT * FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order");
$active_cat = (int)($_GET['cat'] ?? 0);
// /categorie/{slug} (din sitemap și link-uri SEO) — înainte era ignorat și afișa toate anunțurile
if (!$active_cat && !empty($_GET['categorie']) && is_string($_GET['categorie'])) {
    foreach ($categories as $_c) {
        if ($_c['slug'] === $_GET['categorie']) { $active_cat = (int)$_c['id']; break; }
    }
    if (!$active_cat) { http_response_code(404); }
}
if ($active_cat) {
    foreach ($categories as $_c) {
        if ((int)$_c['id'] === $active_cat) {
            $seo_title = ($_c['meta_title'] ?? '') ?: ($_c['name'] . ' — anunțuri video');
            $seo_desc  = ($_c['meta_description'] ?? '') ?: ($_c['description'] ?? $seo_desc);
            $seo_url   = BASE_URL . '/categorie/' . $_c['slug'];
            break;
        }
    }
}
$sort       = in_array($_GET['sort']??'', ['views','price_asc','price_desc']) ? $_GET['sort'] : 'recent';

$where  = "a.status='active' AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())";
$params = [];
if ($active_cat) { $where .= " AND a.category_id=?"; $params[] = $active_cat; }

$order = match($sort) {
    'views'      => 'a.views_count DESC',
    'price_asc'  => 'a.price ASC',
    'price_desc' => 'a.price DESC',
    default      => 'a.is_boosted DESC, COALESCE(a.bumped_at, a.created_at) DESC',
};

$pagination = paginate(
    "SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
            u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
            ap.path AS main_photo
     FROM ads a
     JOIN categories c ON c.id=a.category_id
     JOIN users u ON u.id=a.user_id
     LEFT JOIN ad_photos ap ON ap.ad_id = a.id AND ap.is_primary = 1
     WHERE $where ORDER BY $order",
    "SELECT COUNT(*) as total FROM ads a WHERE $where",
    $params, 24
);

// Stories active (afișate doar pe mobil)
$stories = [];
if (setting_bool('feature_stories')) {
    $stories = DB::fetchAll(
        "SELECT s.user_id, u.name AS user_name, u.avatar AS user_avatar, MAX(s.created_at) AS latest
         FROM stories s JOIN users u ON u.id = s.user_id
         WHERE s.is_active=1 AND (s.expires_at IS NULL OR s.expires_at > NOW())
         GROUP BY s.user_id, u.name, u.avatar
         ORDER BY latest DESC LIMIT 12"
    );
}

$cat_map_icon = array_column($categories,'icon','id');
$cat_map_name = array_column($categories,'name','id');

// Hero — conținut editabil din admin (setting_group='hero')
$hero_eyebrow      = setting('hero_eyebrow_text', 'MARKETPLACE VIDEO');
$hero_subtitle     = setting('hero_subtitle', 'Descoperă produse și servicii prin video — ca pe TikTok, dar pentru cumpărături reale.');
$hero_search_ph    = setting('hero_search_placeholder', 'Caută mașini, apartamente, electronice...');
$hero_chips_label  = setting('hero_chips_label', 'Populare:');
$hero_chips_raw    = setting('hero_chips', 'BMW,apartament,iPhone,bicicletă');
$hero_chips        = array_filter(array_map('trim', explode(',', $hero_chips_raw)));
$hero_show_stats   = setting_bool('hero_show_stats');

// Statistici live pentru hero (social proof) — pulled doar dacă afișarea e activă
$hero_stats = ['ads'=>0,'users'=>0,'cities'=>0];
if ($hero_show_stats) {
    try {
        $hero_stats['ads']    = (int)(DB::fetch("SELECT COUNT(*) c FROM ads WHERE status='active'")['c'] ?? 0);
        $hero_stats['users']  = (int)(DB::fetch("SELECT COUNT(*) c FROM users WHERE is_active=1")['c'] ?? 0);
        $hero_stats['cities'] = (int)(DB::fetch("SELECT COUNT(DISTINCT city) c FROM ads WHERE status='active' AND city IS NOT NULL AND city<>''")['c'] ?? 0);
    } catch (Exception $e) {}
}

// 3 anunțuri pentru cards-urile float din hero — random din top 30 (boostate + cele mai vizionate)
// Asta dă varietate la fiecare reload, dar prioritizează anunțurile populare/promovate
$hero_card_ads = [];
if (!$active_cat && $pagination['page'] === 1) try {
    $hero_card_ads = DB::fetchAll("
        SELECT * FROM (
            SELECT a.id, a.title, a.slug, a.price, a.currency,
                   c.name AS cat_name,
                   a.video_thumb,
                   (SELECT path FROM ad_photos WHERE ad_id=a.id ORDER BY id ASC LIMIT 1) AS first_photo
            FROM ads a
            JOIN categories c ON c.id=a.category_id
            WHERE a.status='active'
            ORDER BY a.is_boosted DESC, a.views_count DESC
            LIMIT 30
        ) pool
        ORDER BY RAND()
        LIMIT 3
    ");
} catch (Exception $e) {}
function hero_num($n){
    if ($n >= 1000) { $v = $n/1000; return rtrim(rtrim(number_format($v,1,'.',''),'0'),'.').'K'; }
    return (string)$n;
}
if (!function_exists('vh_tint')) {
    function vh_tint($hex, $a = '.14') {
        $hx = ltrim((string)$hex, '#');
        if (strlen($hx) === 3) $hx = $hx[0].$hx[0].$hx[1].$hx[1].$hx[2].$hx[2];
        if (strlen($hx) !== 6 || !ctype_xdigit($hx)) return "rgba(232,56,13,$a)";
        return 'rgba('.hexdec(substr($hx,0,2)).','.hexdec(substr($hx,2,2)).','.hexdec(substr($hx,4,2)).",$a)";
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* ── Hero 2026 ───────────────────────────────────────────── */
.hero3{position:relative;max-width:1240px;margin:20px auto 8px;padding:0 24px}
.hero3-card{position:relative;overflow:hidden;border-radius:32px;
  background:
    radial-gradient(60% 80% at 100% 0%, rgba(255,138,0,.20), transparent 60%),
    radial-gradient(50% 70% at 0% 100%, rgba(124,92,255,.14), transparent 60%),
    var(--surface);
  border:1px solid var(--border-2);box-shadow:var(--shadow);
  display:grid;grid-template-columns:1.1fr .9fr;gap:24px;align-items:center;
  padding:56px 56px 56px 60px;min-height:460px}
.hero3-card::after{content:"";position:absolute;inset:0;pointer-events:none;
  background-image:radial-gradient(rgba(17,16,22,.07) 1px, transparent 1px);background-size:22px 22px;
  mask-image:linear-gradient(90deg,transparent 40%,#000 100%);-webkit-mask-image:linear-gradient(90deg,transparent 40%,#000 100%)}
[data-theme="dark"] .hero3-card::after{background-image:radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px)}
.hero3-left{position:relative;z-index:2}
.hero3-eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 14px 6px 8px;border-radius:999px;
  background:var(--surface-2);border:1px solid var(--border);font-size:12.5px;font-weight:700;color:var(--text-2);margin-bottom:22px}
.hero3-eyebrow b{background:var(--grad-ember);color:#fff;border-radius:999px;padding:2px 9px;font-size:11px;letter-spacing:.4px}
.hero3-title{font-size:clamp(2.4rem,4.6vw,3.9rem);font-weight:800;letter-spacing:-.045em;line-height:1.02;margin-bottom:18px}
.hero3-title .hl{background:var(--grad-text);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.hero3-sub{font-size:1.06rem;color:var(--text-2);max-width:500px;margin-bottom:28px;line-height:1.6}
.hero3-form{display:flex;align-items:center;gap:6px;max-width:560px;padding:6px;border-radius:20px;
  background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow-md)}
.hero3-form:focus-within{border-color:var(--brand);box-shadow:var(--brand-glow),var(--shadow-md)}
.hero3-form .ico{padding-left:12px;color:var(--text-3);display:flex}
.hero3-form input{flex:1;min-width:0;border:0;outline:0;background:none;height:48px;font-size:15.5px;color:var(--text)}
.hero3-form input::placeholder{color:var(--text-3)}
.hero3-form select{border:0;outline:0;background:var(--surface-2);height:48px;border-radius:14px;padding:0 12px;font-size:13.5px;font-weight:600;color:var(--text-2);max-width:170px;cursor:pointer}
.hero3-form button{height:48px;padding:0 24px;border:0;border-radius:14px;background:var(--grad-ember);color:#fff;font-weight:800;font-size:15px;cursor:pointer;box-shadow:var(--shadow-brand);transition:transform .2s var(--spring)}
.hero3-form button:hover{transform:translateY(-1px)}
.hero3-chips{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:18px}
.hero3-chips .lbl{font-size:13px;color:var(--text-3);font-weight:600;margin-right:2px}
.hero3-chips a{font-size:13px;font-weight:600;color:var(--text-2);padding:6px 13px;border-radius:999px;border:1px solid var(--border);background:var(--surface);transition:all .2s}
.hero3-chips a:hover{color:var(--brand);border-color:var(--border-brand)}
.hero3-stats{display:flex;gap:28px;margin-top:30px}
.hero3-stats b{display:block;font-family:var(--font-display);font-size:1.6rem;font-weight:800;letter-spacing:-.03em}
.hero3-stats span{font-size:12.5px;color:var(--text-3);font-weight:600}

/* Colaj video */
.hero3-right{position:relative;z-index:2;height:400px}
.v3{position:absolute;display:block;border-radius:26px;overflow:hidden;background:#141418 center/cover no-repeat;
  box-shadow:0 30px 60px -24px rgba(17,16,22,.55);border:4px solid var(--surface);color:#fff;text-decoration:none;
  transition:transform .5s var(--spring)}
.v3:hover{color:#fff}
.v3::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.05) 30%,rgba(0,0,0,.78))}
.v3-main{width:230px;height:380px;left:50%;top:10px;transform:translateX(-50%) rotate(-2deg);z-index:3}
.v3-a{width:170px;height:240px;left:2%;top:90px;transform:rotate(-9deg);z-index:2;opacity:.96}
.v3-b{width:170px;height:240px;right:2%;top:40px;transform:rotate(8deg);z-index:2;opacity:.96}
.hero3-right:hover .v3-main{transform:translateX(-50%) rotate(0) translateY(-6px)}
.hero3-right:hover .v3-a{transform:rotate(-12deg) translateX(-8px)}
.hero3-right:hover .v3-b{transform:rotate(11deg) translateX(8px)}
.v3-tag{position:absolute;top:12px;left:12px;z-index:2;font-size:10.5px;font-weight:800;letter-spacing:.4px;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.18);backdrop-filter:blur(8px)}
.v3-play{position:absolute;z-index:2;left:50%;top:44%;transform:translate(-50%,-50%);width:58px;height:58px;border-radius:50%;
  background:rgba(255,255,255,.95);display:grid;place-items:center;box-shadow:0 10px 26px rgba(0,0,0,.35)}
.v3-play::after{content:"";position:absolute;inset:-8px;border-radius:50%;border:2px solid rgba(255,255,255,.55);animation:v3ring 2.2s ease-out infinite}
@keyframes v3ring{0%{transform:scale(.85);opacity:1}100%{transform:scale(1.35);opacity:0}}
.v3-a .v3-play,.v3-b .v3-play{width:42px;height:42px}
.v3-foot{position:absolute;left:12px;right:12px;bottom:12px;z-index:2}
.v3-title{font-size:13px;font-weight:700;line-height:1.3;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.v3-price{display:inline-block;font-family:var(--font-display);font-weight:800;font-size:14px;color:#121116;background:#fff;border-radius:10px;padding:3px 10px}
.v3-float{position:absolute;z-index:4;display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:16px;background:var(--surface);
  border:1px solid var(--border-2);box-shadow:var(--shadow-lg);font-size:12.5px;font-weight:700;color:var(--text);white-space:nowrap;
  animation:v3float 6s ease-in-out infinite}
.v3-float small{display:block;font-weight:600;color:var(--text-3);font-size:11px}
.v3-float .ic{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;font-size:16px}
.v3-f1{left:-2%;bottom:24px}
.v3-f1 .ic{background:rgba(14,159,110,.12)}
.v3-f2{right:-1%;top:250px;animation-delay:-3s}
.v3-f2 .ic{background:var(--brand-light)}
@keyframes v3float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}

/* Hero compact pe mobil: doar căutare */
.hero3-mobile{display:none}
@media(max-width:1020px){
  .hero3-card{grid-template-columns:1fr;padding:44px 36px;min-height:0}
  .hero3-right{display:none}
}
@media(max-width:768px){
  .hero3{display:none}
  .hero3-mobile{display:block;padding:16px 16px 4px}
  .hero3-mobile h1{font-size:1.55rem;letter-spacing:-.04em;margin-bottom:12px;line-height:1.15}
  .hero3-mobile h1 .hl{background:var(--grad-text);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
  .hero3-mobile form{display:flex;align-items:center;gap:8px;padding:5px 5px 5px 14px;border-radius:16px;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow-sm)}
  .hero3-mobile input{flex:1;min-width:0;border:0;outline:0;background:none;height:42px;font-size:15px;color:var(--text)}
  .hero3-mobile button{height:42px;width:42px;border:0;border-radius:12px;background:var(--grad-ember);color:#fff;display:grid;place-items:center}
}
</style>

<!-- HERO -->
<?php if (!$active_cat && $pagination['page'] === 1): ?>
<?php
$hero_tagline = setting('site_tagline','Anunțuri video. Simplu. Rapid.');
$hero_tagline = str_ireplace('video', '<span class="hl">video</span>', h($hero_tagline));
$hero_slots = [];
foreach (array_slice($hero_card_ads, 0, 3) as $hc) {
    $hero_slots[] = [
        'bg'    => !empty($hc['first_photo']) ? photo_url($hc['first_photo']) : (!empty($hc['video_thumb']) ? thumb_url($hc['video_thumb']) : ''),
        'tag'   => mb_strtoupper($hc['cat_name']),
        'title' => $hc['title'],
        'price' => $hc['price'] > 0 ? format_price((float)$hc['price'], $hc['currency']) : 'Negociabil',
        'link'  => '/anunt/' . $hc['slug'],
    ];
}
$hero_fallback = [
    ['bg'=>'','tag'=>'AUTO / MOTO','title'=>'BMW Seria 5 520d xDrive 2022','price'=>'42.500 EUR','link'=>''],
    ['bg'=>'','tag'=>'IMOBILIARE','title'=>'Apartament 3 camere Floreasca','price'=>'125.000 EUR','link'=>''],
    ['bg'=>'','tag'=>'ELECTRONICE','title'=>'MacBook Pro M3 · sigilat','price'=>'9.800 RON','link'=>''],
];
while (count($hero_slots) < 3) $hero_slots[] = $hero_fallback[count($hero_slots)];
$v3_classes = ['v3-main', 'v3-a', 'v3-b'];
?>
<section class="hero3" aria-label="Căutare anunțuri">
  <div class="hero3-card">
    <div class="hero3-left">
      <?php if ($hero_eyebrow): ?>
      <span class="hero3-eyebrow"><b>NOU</b> <?= h($hero_eyebrow) ?></span>
      <?php endif; ?>
      <h1 class="hero3-title"><?= $hero_tagline ?></h1>
      <?php if ($hero_subtitle): ?>
      <p class="hero3-sub"><?= nl2br(h($hero_subtitle)) ?></p>
      <?php endif; ?>
      <form action="/cauta" method="GET" class="hero3-form" role="search">
        <span class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
        <input type="search" name="q" placeholder="<?= h($hero_search_ph) ?>" autocomplete="off" aria-label="Ce cauți?">
        <select name="cat" aria-label="Categorie">
          <option value="">Toate categoriile</option>
          <?php foreach ($categories as $hc): ?><option value="<?= (int)$hc['id'] ?>"><?= h($hc['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit">Caută</button>
      </form>
      <?php if ($hero_chips): ?>
      <div class="hero3-chips">
        <?php if ($hero_chips_label): ?><span class="lbl"><?= h($hero_chips_label) ?></span><?php endif; ?>
        <?php foreach ($hero_chips as $chip): ?>
          <a href="/cauta?q=<?= urlencode($chip) ?>"><?= h($chip) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($hero_show_stats): ?>
      <div class="hero3-stats">
        <div><b><?= hero_num($hero_stats['ads']) ?>+</b><span>anunțuri active</span></div>
        <div><b><?= hero_num($hero_stats['users']) ?>+</b><span>vânzători</span></div>
        <div><b><?= hero_num($hero_stats['cities']) ?></b><span>orașe</span></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="hero3-right" aria-hidden="true">
      <?php foreach ($hero_slots as $i => $sl):
          $tagOpen = $sl['link'] ? '<a href="' . h($sl['link']) . '" tabindex="-1"' : '<div';
          $tagClose = $sl['link'] ? '</a>' : '</div>';
          $bgStyle = $sl['bg'] ? ' style="background-image:url(\'' . h($sl['bg']) . '\')"' : ' style="background-image:linear-gradient(160deg,#2A2830,#121116)"';
      ?>
        <?= $tagOpen ?> class="v3 <?= $v3_classes[$i] ?>"<?= $bgStyle ?>>
          <span class="v3-tag"><?= h($sl['tag']) ?></span>
          <span class="v3-play"><svg width="18" height="18" viewBox="0 0 24 24" fill="#FF4A1C"><path d="M7 4v16l13-8z"/></svg></span>
          <span class="v3-foot"><span class="v3-title"><?= h($sl['title']) ?></span><span class="v3-price"><?= h($sl['price']) ?></span></span>
        <?= $tagClose ?>
      <?php endforeach; ?>
      <div class="v3-float v3-f1"><span class="ic">💬</span><span>Mesaj nou<small>„Mai este disponibil?”</small></span></div>
      <div class="v3-float v3-f2"><span class="ic">🎬</span><span>+3× vizualizări<small>pentru anunțurile video</small></span></div>
    </div>
  </div>
</section>

<div class="hero3-mobile">
  <h1><?= $hero_tagline ?></h1>
  <form action="/cauta" method="GET" role="search">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" style="color:var(--text-3)"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    <input type="search" name="q" placeholder="<?= h($hero_search_ph) ?>" autocomplete="off" aria-label="Ce cauți?">
    <button type="submit" aria-label="Caută"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
  </form>
</div>
<?php endif; ?>

<?php if (!empty($stories)): ?>
<!-- STORIES (mobil) -->
<div class="vh-stories vh-reveal">
  <?php if (is_logged()): ?>
  <a href="/stories" class="vh-story vh-add">
    <div class="vh-ring"><div class="vh-av">+</div></div>
    <span class="vh-story-name">Story nou</span>
  </a>
  <?php endif; ?>
  <?php foreach ($stories as $st): ?>
  <a href="/stories" class="vh-story">
    <div class="vh-ring"><div class="vh-av">
      <img src="<?= h(avatar_url($st['user_avatar'] ?? null, $st['user_name'] ?? '')) ?>" alt="<?= h($st['user_name']) ?>" loading="lazy">
    </div></div>
    <span class="vh-story-name"><?= h(mb_substr($st['user_name'] ?? '', 0, 12)) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Categorii — buton (mobil) deschide panou · pills inline (desktop) -->
<?php $cur_cat_label = $active_cat ? ($cat_map_name[$active_cat] ?? 'Categorie') : 'Toate'; ?>
<div class="vh-cat-block">
  <button type="button" class="vh-cat-trigger vh-reveal" id="vh-cat-trigger" aria-haspopup="true" aria-expanded="false">
    <span class="vh-ct-ico">📂</span>
    <span class="vh-ct-label">Categorii</span>
    <span class="vh-ct-cur"><b><?= h($cur_cat_label) ?></b>
      <svg class="vh-ct-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </span>
  </button>

  <div class="vh-cat-backdrop" id="vh-cat-backdrop"></div>

  <nav class="vh-cats" id="vh-cats" aria-label="Categorii">
    <div class="vh-cat-sheet-head">
      <div class="vh-cat-sheet-title">
        <h3>Alege categoria</h3>
        <button type="button" class="vh-cat-close" id="vh-cat-close" aria-label="Închide">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <a href="/" class="vh-pill <?= !$active_cat ? 'active' : '' ?>" style="--cc:#E8380D;--cct:<?= vh_tint('#E8380D') ?>">
      <span class="vh-p-ico">✦</span>
      <span class="vh-p-name">Toate categoriile</span>
      <svg class="vh-p-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    </a>
    <?php foreach ($categories as $cat): $cc = $cat['color'] ?: '#E8380D'; ?>
    <a href="/?cat=<?= (int)$cat['id'] ?>" class="vh-pill <?= $active_cat==$cat['id'] ? 'active' : '' ?>" style="--cc:<?= h($cc) ?>;--cct:<?= vh_tint($cc) ?>">
      <span class="vh-p-ico"><?= h($cat['icon']) ?></span>
      <span class="vh-p-name"><?= h($cat['name']) ?></span>
      <svg class="vh-p-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    </a>
    <?php endforeach; ?>
  </nav>
</div>

<!-- Anunțuri văzute recent (populat din localStorage) -->
<style>
.rv-section{max-width:1180px;margin:18px auto 0;padding:0 16px}
.rv-section h2{font-family:var(--font-display);font-size:1.15rem;font-weight:800;color:var(--text);margin-bottom:12px}
.rv-strip{display:flex;gap:12px;overflow-x:auto;padding-bottom:6px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.rv-strip::-webkit-scrollbar{display:none}
.rv-card{flex:0 0 142px;width:142px;text-decoration:none}
.rv-thumb{width:142px;height:106px;border-radius:12px;overflow:hidden;background:var(--surface-2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center}
.rv-thumb img{width:100%;height:100%;object-fit:cover}
.rv-noimg{font-size:28px;opacity:.4}
.rv-card:hover .rv-thumb{border-color:var(--border-brand)}
.rv-title{font-size:12.5px;font-weight:600;color:var(--text);margin-top:7px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.rv-price{font-family:var(--font-display);font-size:13px;font-weight:800;color:var(--brand);margin-top:3px}
</style>
<section class="rv-section" id="recent-viewed" style="display:none">
  <h2>Văzute recent</h2>
  <div class="rv-strip" id="rv-strip"></div>
</section>

<!-- Section label + sort -->
<div class="vh-sec vh-reveal">
  <h2>
    <?php if ($active_cat): ?>
      <?= h($cat_map_icon[$active_cat] ?? '') ?> <?= h($cat_map_name[$active_cat] ?? 'Anunțuri') ?>
    <?php else: ?>
      Anunțuri recente
    <?php endif; ?>
  </h2>
  <span class="vh-line"></span>
  <div class="vh-sort">
    <?php
    $sorts = ['recent'=>'Recente','views'=>'Populare','price_asc'=>'Preț ↑','price_desc'=>'Preț ↓'];
    foreach ($sorts as $k=>$lbl):
        $url = '?'.http_build_query(array_merge((array)$_GET,['sort'=>$k,'page'=>1]));
    ?>
    <a href="<?= h($url) ?>" class="<?= $sort===$k?'active':'' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Feed -->
<?php if (empty($pagination['items'])): ?>
<div class="vh-empty">
  <div class="vh-emoji">📭</div>
  <h3 style="margin-bottom:6px;font-family:var(--font-display);">Niciun anunț aici încă</h3>
  <p style="margin-bottom:16px;">Fii primul care postează în această categorie.</p>
  <a href="/anunt/nou" class="btn btn-primary">+ Postează primul anunț</a>
</div>
<?php else: ?>
<div class="vh-grid">
  <?php foreach ($pagination['items'] as $i => $ad):
      // Mobil: primul + fiecare al 7-lea = card mare. Desktop: grilă uniformă.
      $card_spotlight = ($i % 7 === 0);
      $card_eager     = ($i < 4);
      include __DIR__ . '/includes/ad-card.php';
  endforeach; ?>
</div>

<?php if ($pagination['pages'] > 1): ?>
<nav class="vh" style="display:flex;justify-content:center;gap:5px;margin:8px auto 40px;flex-wrap:wrap;">
  <?php if ($pagination['page'] > 1): ?>
  <a href="?<?= h(http_build_query(array_merge((array)$_GET,['page'=>$pagination['page']-1]))) ?>" class="page-btn">← Anterior</a>
  <?php endif; ?>
  <?php for ($p=max(1,$pagination['page']-2);$p<=min($pagination['pages'],$pagination['page']+2);$p++): ?>
  <a href="?<?= h(http_build_query(array_merge((array)$_GET,['page'=>$p]))) ?>"
     class="page-btn <?= $p==$pagination['page']?'active':'' ?>"><?= $p ?></a>
  <?php endfor; ?>
  <?php if ($pagination['page'] < $pagination['pages']): ?>
  <a href="?<?= h(http_build_query(array_merge((array)$_GET,['page'=>$pagination['page']+1]))) ?>" class="page-btn">Următor →</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<script type="application/ld+json"><?= json_ld(['@context'=>'https://schema.org','@type'=>'WebSite','name'=>setting('site_name'),'url'=>BASE_URL,'potentialAction'=>['@type'=>'SearchAction','target'=>BASE_URL.'/cauta?q={search_term_string}','query-input'=>'required name=search_term_string']]) ?></script>

<script>
(function(){
  // Category bottom-sheet (mobile)
  var trg = document.getElementById('vh-cat-trigger');
  var sheet = document.getElementById('vh-cats');
  var bd  = document.getElementById('vh-cat-backdrop');
  var cls = document.getElementById('vh-cat-close');
  if (trg && sheet && bd) {
    function openSheet(){
      sheet.classList.add('open'); bd.classList.add('open');
      trg.setAttribute('aria-expanded','true');
      document.body.style.overflow = 'hidden';
    }
    function closeSheet(){
      sheet.classList.remove('open'); bd.classList.remove('open');
      trg.setAttribute('aria-expanded','false');
      document.body.style.overflow = '';
    }
    trg.addEventListener('click', openSheet);
    bd.addEventListener('click', closeSheet);
    if (cls) cls.addEventListener('click', closeSheet);
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && sheet.classList.contains('open')) closeSheet();
    });
  }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
