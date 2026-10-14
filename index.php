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
$_sorts_ok  = ['recent','views','price_asc','price_desc'];
$sort       = in_array($_GET['sort'] ?? '', $_sorts_ok, true) ? $_GET['sort']
            : (in_array(ui('ui_default_sort'), $_sorts_ok, true) ? ui('ui_default_sort') : 'recent');

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
    $params, max(6, min(60, (int)ui('ui_per_page') ?: 24))
);

// Stories active (afișate doar pe mobil)
$stories = [];
if (setting_bool('feature_stories') && ui_bool('ui_show_stories')) {
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
// Statistici pentru tile-urile bento (cache 5 min)
$hero_stats = cache_remember('home_stats', 300, function () {
    try {
        return [
            'ads'    => (int)(DB::fetch("SELECT COUNT(*) c FROM ads WHERE status='active'")['c'] ?? 0),
            'video'  => (int)(DB::fetch("SELECT COUNT(*) c FROM ads WHERE status='active' AND video_path IS NOT NULL")['c'] ?? 0),
            'users'  => (int)(DB::fetch("SELECT COUNT(*) c FROM users WHERE is_active=1")['c'] ?? 0),
            'cities' => (int)(DB::fetch("SELECT COUNT(DISTINCT city) c FROM ads WHERE status='active' AND city IS NOT NULL AND city<>''")['c'] ?? 0),
            'today'  => (int)(DB::fetch("SELECT COUNT(*) c FROM ads WHERE status='active' AND created_at >= CURDATE()")['c'] ?? 0),
        ];
    } catch (Exception $e) { return ['ads'=>0,'video'=>0,'users'=>0,'cities'=>0,'today'=>0]; }
});

// Anunț video recomandat pentru tile-ul „Acum pe Veedo" (rulează automat, fără sunet)
$hero_video = null;
$live_now   = 0;
if (!$active_cat && $pagination['page'] === 1) {
    $_hv_sql = "SELECT a.title, a.slug, a.price, a.currency, a.video_path, a.video_thumb, a.city, c.name AS cat_name
        FROM ads a JOIN categories c ON c.id=a.category_id
        WHERE a.status='active' AND a.video_path IS NOT NULL";
    // Anunțul ales din Admin → Personalizare; altfel cel mai vizionat anunț video
    if ((int)ui('ui_hero_ad_id') > 0) $hero_video = DB::fetch($_hv_sql . " AND a.id=?", [(int)ui('ui_hero_ad_id')]);
    if (!$hero_video) $hero_video = DB::fetch($_hv_sql . " ORDER BY a.is_boosted DESC, a.views_count DESC LIMIT 1");
    try { $live_now = (int)(DB::fetch("SELECT COUNT(*) c FROM live_streams WHERE status='live'")['c'] ?? 0); } catch (Exception $e) {}
}


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
/* ── Bento hero 2027 ─────────────────────────────────────── */
.bento{max-width:1320px;margin:22px auto 8px;padding:0 28px;display:grid;gap:14px;
  grid-template-columns:repeat(12,1fr);grid-template-rows:auto auto}
.bt{position:relative;overflow:hidden;border-radius:30px;background:var(--surface);border:1px solid var(--glass-border);
  backdrop-filter:blur(22px) saturate(1.4);-webkit-backdrop-filter:blur(22px) saturate(1.4);
  box-shadow:inset 0 1px 0 var(--glass-border),var(--shadow);padding:26px}
.bt-main{grid-column:span 8;grid-row:span 2;padding:46px 46px 40px;display:flex;flex-direction:column;justify-content:space-between;min-height:520px}
.bt-main::after{content:"";position:absolute;right:-120px;bottom:-160px;width:460px;height:460px;border-radius:50%;
  background:conic-gradient(from 120deg,#7357FF,#FF4FA3,#D4FF3F,#7357FF);filter:blur(80px);opacity:.35;pointer-events:none;animation:spin 18s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.bt-eyebrow{position:relative;z-index:1;display:inline-flex;align-items:center;gap:10px;font-family:var(--font-mono);font-size:12px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--text-2)}
.bt-eyebrow i{width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px rgba(212,255,63,.2)}
.bt-title{position:relative;z-index:1;font-size:clamp(2.6rem,5.6vw,5.4rem);font-weight:800;line-height:.95;letter-spacing:-.05em;margin:26px 0 22px}
.bt-sub{position:relative;z-index:1;font-size:1.05rem;color:var(--text-2);max-width:520px;margin-bottom:30px}
.bt-search{position:relative;z-index:1;display:flex;align-items:center;gap:8px;max-width:640px;padding:7px;border-radius:22px;
  background:var(--surface-solid);border:1px solid var(--glass-border);box-shadow:var(--shadow-md)}
.bt-search:focus-within{border-color:var(--brand);box-shadow:var(--brand-glow),var(--shadow-md)}
.bt-search .ico{padding-left:12px;color:var(--text-3);display:flex}
.bt-search input{flex:1;min-width:0;border:0;outline:0;background:none;height:50px;font-size:16px;color:var(--text)}
.bt-search input::placeholder{color:var(--text-3)}
.bt-search select{border:0;outline:0;background:var(--glass);height:50px;border-radius:15px;padding:0 12px;font-size:13.5px;color:var(--text-2);max-width:170px;cursor:pointer}
.bt-search button{height:50px;padding:0 26px;border:0;border-radius:15px;background:var(--accent);color:var(--on-accent);font-weight:700;font-size:15px;cursor:pointer;transition:transform .2s var(--spring)}
.bt-search button:hover{transform:translateY(-1px) scale(1.02)}
.bt-chips{position:relative;z-index:1;display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.bt-chips a{font-size:13px;color:var(--text-2);padding:7px 14px;border-radius:999px;border:1px solid var(--glass-border);background:var(--glass);transition:all .2s}
.bt-chips a:hover{color:var(--text);border-color:var(--glass-hi)}

.bt-video{grid-column:span 4;grid-row:span 2;padding:0;min-height:520px;background:#0C0C12;color:#fff}
.bt-video video,.bt-video img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.bt-video .ph{position:absolute;inset:0;background:radial-gradient(60% 50% at 30% 30%,rgba(115,87,255,.6),transparent 70%),radial-gradient(60% 50% at 80% 80%,rgba(255,79,163,.45),transparent 70%),#0C0C12}
.bt-video::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.35),transparent 30%,transparent 50%,rgba(0,0,0,.9))}
.bt-video .top{position:absolute;z-index:2;top:18px;left:18px;right:18px;display:flex;justify-content:space-between;align-items:center}
.bt-pill{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;background:rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.16);backdrop-filter:blur(10px);font-family:var(--font-mono);font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:#fff}
.bt-pill i{width:7px;height:7px;border-radius:50%;background:var(--live);animation:livePulse 1.8s infinite}
.bt-video .bot{position:absolute;z-index:2;left:20px;right:20px;bottom:20px}
.bt-video .bot h3{color:#fff;font-size:1.25rem;line-height:1.2;margin:10px 0 12px;font-weight:600}
.bt-video .price{display:inline-block;font-family:var(--font-mono);font-weight:600;font-size:16px;padding:5px 12px;border-radius:12px;background:var(--accent);color:var(--on-accent)}
.bt-video a.cover{position:absolute;inset:0;z-index:3}

.bt-stat{grid-column:span 3;display:flex;flex-direction:column;justify-content:space-between;min-height:190px}
.bt-stat .k{font-family:var(--font-mono);font-size:11px;letter-spacing:1.4px;text-transform:uppercase;color:var(--text-3)}
.bt-stat .n{font-family:var(--font-display);font-weight:800;font-size:clamp(2.4rem,4vw,3.6rem);letter-spacing:-.05em;line-height:1}
.bt-stat .s{font-size:13px;color:var(--text-2)}
.bt-stat .spark{position:absolute;right:20px;top:22px;display:flex;gap:4px;align-items:flex-end;height:46px}
.bt-stat .spark span{width:7px;border-radius:4px;background:var(--brand);opacity:.85}
.bt-stat .spark span:nth-child(odd){background:var(--accent)}
.bt-cta{grid-column:span 3;background:var(--accent);border-color:transparent;color:var(--on-accent);display:flex;flex-direction:column;justify-content:space-between;min-height:190px;transition:transform .35s var(--spring)}
.bt-cta:hover{transform:translateY(-4px) rotate(-.5deg);color:var(--on-accent)}
.bt-cta h3{color:var(--on-accent);font-size:1.5rem;line-height:1.05;letter-spacing:-.03em}
.bt-cta .arrow{width:48px;height:48px;border-radius:16px;background:var(--on-accent);color:var(--accent);display:grid;place-items:center;align-self:flex-end;transition:transform .3s var(--spring)}
.bt-cta:hover .arrow{transform:rotate(-45deg)}
.bt-cta small{font-family:var(--font-mono);font-size:11px;letter-spacing:1.2px;text-transform:uppercase;opacity:.7}
.bt-live{grid-column:span 3;min-height:190px;display:flex;flex-direction:column;justify-content:space-between;color:var(--text)}
.bt-live:hover{color:var(--text);border-color:var(--glass-hi)}
.bt-live h3{font-size:1.15rem}
.bt-live p{font-size:13px;color:var(--text-2)}
.bt-trust{grid-column:span 3;min-height:190px;display:flex;flex-direction:column;justify-content:space-between}
.bt-trust ul{list-style:none;display:grid;gap:9px;font-size:13.5px;color:var(--text-2)}
.bt-trust li{display:flex;gap:9px;align-items:center}
.bt-trust li b{width:22px;height:22px;border-radius:7px;background:var(--brand-light);color:var(--brand);display:grid;place-items:center;font-size:12px;flex-shrink:0}

/* Tema luminoasă: variantă sobră, profesională */
[data-theme="light"] .bt{backdrop-filter:none;-webkit-backdrop-filter:none;border-color:var(--border);box-shadow:var(--shadow)}
[data-theme="light"] .bt-main{background:linear-gradient(135deg,#FFFFFF 0%,#F4F7FF 100%);justify-content:center}
[data-theme="light"] .bt-main::after{background:radial-gradient(circle,rgba(37,99,235,.16),transparent 65%);filter:blur(30px);opacity:1;animation:none}
[data-theme="light"] .bt-title{font-size:clamp(2.4rem,4.4vw,4.2rem);font-weight:700;line-height:1.04;letter-spacing:-.035em}
[data-theme="light"] .bt-eyebrow{font-family:var(--font-main);font-size:12.5px;letter-spacing:.08em;color:var(--accent)}
[data-theme="light"] .bt-eyebrow i{box-shadow:0 0 0 4px var(--brand-light)}
[data-theme="light"] .bt-search{border-radius:14px;border-color:var(--border);box-shadow:var(--shadow)}
[data-theme="light"] .bt-search select{background:var(--surface-2);border-radius:10px}
[data-theme="light"] .bt-search button{border-radius:10px}
[data-theme="light"] .bt-search button:hover{transform:none;background:var(--accent-2)}
[data-theme="light"] .bt-chips a{background:var(--surface);border-color:var(--border)}
[data-theme="light"] .bt-chips a:hover{border-color:var(--accent);color:var(--accent)}
[data-theme="light"] .bt-video{background:#0F172A}
[data-theme="light"] .bt-video .ph{background:linear-gradient(160deg,#1E3A8A 0%,#0F172A 100%)}
[data-theme="light"] .bt-cta:hover{transform:translateY(-3px)}
[data-theme="light"] .bt-stat .k,[data-theme="light"] .bt-cta small{font-family:var(--font-main);letter-spacing:.08em}

/* Mobil: hero compact */
.hero-m{display:none}
@media(max-width:1100px){
  .bt-main{grid-column:span 12;grid-row:auto;min-height:0}
  .bt-video{grid-column:span 6;min-height:360px;grid-row:auto}
  .bt-stat,.bt-cta,.bt-live,.bt-trust{grid-column:span 6}
}
@media(max-width:768px){
  .bento{display:none}
  .hero-m{display:block;padding:18px 16px 6px}
  .hero-m h1{font-size:2rem;line-height:1;letter-spacing:-.05em;margin-bottom:14px}
  .hero-m .k{display:flex;align-items:center;gap:8px;font-family:var(--font-mono);font-size:11px;letter-spacing:1.3px;text-transform:uppercase;color:var(--text-3);margin-bottom:12px}
  .hero-m .k i{width:7px;height:7px;border-radius:50%;background:var(--accent)}
  .hero-m form.s{width:100%;display:flex;align-items:center;gap:10px;height:54px;padding:0 7px 0 16px;border-radius:18px;background:var(--surface);border:1px solid var(--glass-border);backdrop-filter:blur(16px);color:var(--text-3)}
  .hero-m form.s:focus-within{border-color:var(--brand);box-shadow:var(--brand-glow)}
  .hero-m form.s input{flex:1;min-width:0;border:0;outline:0;background:none;height:100%;font-size:15.5px;color:var(--text)}
  .hero-m form.s input::placeholder{color:var(--text-3)}
  .hero-m form.s button{flex:0 0 40px;height:40px;border:0;border-radius:13px;background:var(--accent);color:var(--on-accent);display:grid;place-items:center}
}
</style>

<!-- HERO BENTO -->
<?php if (!$active_cat && $pagination['page'] === 1 && ui_bool('ui_hero_on')):
  $_tiles = array_filter(['ui_tile_stats', 'ui_tile_cta', 'ui_tile_live', 'ui_tile_trust'], 'ui_bool');
  $_span  = $_tiles ? intdiv(12, count($_tiles)) : 12;
  $_tile_style = ' style="grid-column:span ' . $_span . '"';
  $_main_full = !ui_bool('ui_tile_video');
  $hv_thumb = $hero_video && $hero_video['video_thumb'] ? thumb_url($hero_video['video_thumb']) : '';
?>
<section class="bento" aria-label="Veedo">
  <div class="bt bt-main vh-reveal"<?= $_main_full ? ' style="grid-column:span 12;grid-row:auto;min-height:440px"' : '' ?>>
    <div>
      <span class="bt-eyebrow"><i></i><?= h(ui('hero_eyebrow_text') ?: 'Marketplace video') ?> · <?= hero_num($hero_stats['ads']) ?> anunțuri live</span>
      <h1 class="bt-title"><?= ui_highlight(ui('ui_hero_title')) ?></h1>
      <?php if (ui('hero_subtitle') !== ''): ?><p class="bt-sub"><?= h(ui('hero_subtitle')) ?></p><?php endif; ?>
    </div>
    <div>
      <form action="/cauta" method="GET" class="bt-search" role="search">
        <span class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
        <input type="search" name="q" placeholder="<?= h($hero_search_ph) ?>" autocomplete="off" aria-label="Ce cauți?">
        <select name="cat" aria-label="Categorie">
          <option value="">Toate categoriile</option>
          <?php foreach ($categories as $hc): ?><option value="<?= (int)$hc['id'] ?>"><?= h($hc['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit">Caută</button>
      </form>
      <?php if ($hero_chips): ?>
      <div class="bt-chips">
        <?php foreach ($hero_chips as $chip): ?><a href="/cauta?q=<?= urlencode($chip) ?>"><?= h($chip) ?></a><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (ui_bool('ui_tile_video')): ?>
  <div class="bt bt-video vh-reveal">
    <?php if ($hero_video): ?>
      <video src="<?= h(BASE_URL . '/uploads/videos/' . rawurlencode(basename($hero_video['video_path']))) ?>" <?= $hv_thumb ? 'poster="' . h($hv_thumb) . '"' : '' ?> autoplay muted loop playsinline preload="metadata"></video>
    <?php else: ?>
      <div class="ph"></div>
    <?php endif; ?>
    <div class="top"><span class="bt-pill"><i></i>Acum pe Veedo</span><?php if ($hero_video): ?><span class="bt-pill"><?= h($hero_video['cat_name']) ?></span><?php endif; ?></div>
    <div class="bot">
      <?php if ($hero_video): ?>
        <h3><?= h(mb_substr($hero_video['title'], 0, 70)) ?></h3>
        <span class="price"><?= $hero_video['price'] > 0 ? h(format_price((float)$hero_video['price'], $hero_video['currency'])) : 'Preț la cerere' ?></span>
      <?php else: ?>
        <h3>Primul anunț video poate fi al tău.</h3>
      <?php endif; ?>
    </div>
    <a class="cover" href="<?= $hero_video ? '/anunt/' . h($hero_video['slug']) : '/anunt/nou' ?>" aria-label="Deschide anunțul"></a>
  </div>
  <?php endif; ?>

  <?php if (ui_bool('ui_tile_stats')): ?>
  <div class="bt bt-stat vh-reveal"<?= $_tile_style ?>>
    <span class="k">Anunțuri video</span>
    <div class="spark" aria-hidden="true"><span style="height:30%"></span><span style="height:55%"></span><span style="height:40%"></span><span style="height:75%"></span><span style="height:60%"></span><span style="height:100%"></span></div>
    <div><div class="n"><?= hero_num($hero_stats['video']) ?></div><div class="s">din <?= hero_num($hero_stats['ads']) ?> anunțuri active · <?= (int)$hero_stats['cities'] ?> orașe</div></div>
  </div>

  <?php endif; ?>

  <?php if (ui_bool('ui_tile_cta')): ?>
  <a href="/anunt/nou" class="bt bt-cta vh-reveal"<?= $_tile_style ?>>
    <small><?= h(ui('ui_cta_kicker')) ?></small>
    <h3><?= implode('<br>', array_map('h', ui_lines('ui_cta_title'))) ?></h3>
    <span class="arrow"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
  </a>

  <?php endif; ?>

  <?php if (ui_bool('ui_tile_live')): ?>
  <a href="<?= setting_bool('feature_live') ? '/live' : '/explore' ?>" class="bt bt-live vh-reveal"<?= $_tile_style ?>>
    <span class="bt-pill" style="align-self:flex-start;background:var(--glass);color:var(--text);border-color:var(--glass-border)"><i></i><?= $live_now > 0 ? $live_now . ' live acum' : 'Live shopping' ?></span>
    <div><h3><?= h(ui('ui_live_title')) ?></h3><p><?= h(ui('ui_live_text')) ?></p></div>
  </a>
  <?php endif; ?>

  <?php if (ui_bool('ui_tile_trust')): ?>
  <div class="bt bt-trust vh-reveal"<?= $_tile_style ?>>
    <span class="k" style="font-family:var(--font-mono);font-size:11px;letter-spacing:1.4px;text-transform:uppercase;color:var(--text-3)">De ce Veedo</span>
    <ul>
      <?php foreach (array_slice(ui_lines('ui_trust_items'), 0, 4) as $_ti => $_tl): ?>
      <li><b><?= ['▶', '✓', '⚡', '★'][$_ti] ?></b> <?= h($_tl) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</section>

<div class="hero-m">
  <div class="k"><i></i><?= hero_num($hero_stats['ads']) ?> anunțuri live</div>
  <h1><?= ui_highlight(ui('ui_hero_title')) ?></h1>
  <form class="s" action="/cauta" method="GET" role="search"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg><input type="search" name="q" placeholder="<?= h($hero_search_ph) ?>" autocomplete="off" aria-label="Ce cauți?"><button type="submit" aria-label="Caută"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button></form>
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
<?php if (ui_bool('ui_show_categories')): ?>
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

<?php endif; /* categorii */ ?>

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
<section class="rv-section" id="<?= ui_bool('ui_show_recent') ? 'recent-viewed' : 'recent-viewed-off' ?>" style="display:none">
  <h2>Văzute recent</h2>
  <div class="rv-strip" id="rv-strip"></div>
</section>

<!-- Section label + sort -->
<div class="vh-sec vh-reveal">
  <h2><span class="vh-kicker"><?= $active_cat ? 'Categorie' : h(ui('ui_feed_kicker')) ?></span>
    <?php if ($active_cat): ?>
      <?= h($cat_map_icon[$active_cat] ?? '') ?> <?= h($cat_map_name[$active_cat] ?? 'Anunțuri') ?>
    <?php else: ?>
      <?= h(ui('ui_feed_title')) ?>
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
