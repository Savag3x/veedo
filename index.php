<?php
require_once __DIR__ . '/includes/bootstrap.php';

$seo_title = setting('site_name') . ' — ' . setting('site_tagline','Anunțuri video. Simplu. Rapid.');
$seo_desc  = setting('site_description');

$categories = DB::fetchAll("SELECT * FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order");
$active_cat = (int)($_GET['cat'] ?? 0);
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
try {
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
/* ── Hero (desktop only) ── */
.hero2{position:relative;overflow:hidden;border-bottom:1px solid var(--border);
  background:linear-gradient(180deg,#FFFFFF 0%,#FFF6F1 100%);}
.hero2-bg{position:absolute;inset:0;pointer-events:none;overflow:hidden}
.h2-glow{position:absolute;border-radius:50%;filter:blur(70px);opacity:.5}
.h2-glow-a{width:440px;height:440px;left:-130px;top:-180px;
  background:radial-gradient(circle,#FF6B35,transparent 68%);
  animation:h2drift 17s ease-in-out infinite}
.h2-glow-b{width:380px;height:380px;right:-110px;bottom:-170px;
  background:radial-gradient(circle,#FFB627,transparent 70%);opacity:.4;
  animation:h2drift 21s ease-in-out infinite reverse}
@keyframes h2drift{0%,100%{transform:translate(0,0) scale(1)}
  50%{transform:translate(34px,26px) scale(1.12)}}

.hero2-inner{position:relative;max-width:1180px;margin:0 auto;
  padding:56px 24px 60px;display:grid;grid-template-columns:1.05fr .95fr;
  gap:40px;align-items:center}

.hero2-eyebrow{display:inline-flex;align-items:center;gap:7px;
  background:#FFE7DE;color:#D4330B;font-size:11.5px;font-weight:800;
  letter-spacing:1.4px;padding:6px 13px;border-radius:100px;margin-bottom:18px}
.hero2-eyebrow .dot{width:7px;height:7px;border-radius:50%;background:#E8380D;
  animation:h2pulse 2s ease-in-out infinite}
@keyframes h2pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.hero2-title{font-family:var(--font-display);
  font-size:clamp(2.1rem,3.4vw,3.25rem);font-weight:800;line-height:1.08;
  letter-spacing:-1.4px;color:#15141A;margin-bottom:14px}
.hero2-title .hl{color:#E8380D}
.hero2-sub{font-size:16px;line-height:1.55;color:#6A6873;
  margin-bottom:24px;max-width:440px}
.hero2-form{display:flex;align-items:center;max-width:480px;background:#fff;
  border:1.6px solid var(--border);border-radius:15px;overflow:hidden;
  box-shadow:0 12px 32px -12px rgba(232,56,13,.28);
  transition:border-color .16s,box-shadow .16s}
.hero2-form:focus-within{border-color:#E8380D;
  box-shadow:0 12px 36px -10px rgba(232,56,13,.4)}
.hero2-form .h2-ico{padding-left:15px;color:#9A9098;display:flex;flex-shrink:0}
.hero2-form input{flex:1;padding:15px 14px;border:none;outline:none;
  font-size:15px;color:#15141A;background:transparent;font-family:var(--font-main)}
.hero2-form button{padding:0 28px;align-self:stretch;background:var(--grad-ember);
  color:#fff;border:none;font-size:14.5px;font-weight:700;cursor:pointer;
  white-space:nowrap;font-family:var(--font-main);transition:filter .15s}
.hero2-form button:hover{filter:brightness(1.08)}
.hero2-chips{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:14px}
.hero2-chips .lbl{font-size:12.5px;color:#9A9098;font-weight:600}
.hero2-chips a{font-size:12.5px;font-weight:600;color:#5A5862;
  background:#fff;border:1px solid var(--border);border-radius:100px;
  padding:5px 12px;text-decoration:none;transition:all .15s}
.hero2-chips a:hover{color:#E8380D;border-color:#F5B9A8;transform:translateY(-1px)}
.hero2-stats{display:flex;gap:26px;margin-top:30px}
.hero2-stats .st{display:flex;flex-direction:column}
.hero2-stats .st b{font-family:var(--font-display);font-size:26px;
  font-weight:800;color:#15141A;line-height:1}
.hero2-stats .st span{font-size:12px;color:#8A8089;margin-top:4px;font-weight:500}
.hero2-stats .st+.st{padding-left:26px;border-left:1px solid var(--border)}

/* ── Cluster carduri video ── */
.hero2-right{position:relative;display:flex;justify-content:center;align-items:center}
.vcluster{position:relative;width:400px;height:370px}
.vc-slot{position:absolute;left:50%;top:50%}
.vc-slot-a{transform:translate(-50%,-50%) translate(-104px,18px) rotate(-10deg);z-index:1}
.vc-slot-c{transform:translate(-50%,-50%) translate(108px,8px) rotate(11deg);z-index:2}
.vc-slot-b{transform:translate(-50%,-50%) translate(4px,-8px) rotate(2.5deg);z-index:3}
.vcard{width:172px;height:240px;border-radius:20px;overflow:hidden;
  position:relative;box-shadow:0 22px 44px -16px rgba(20,12,8,.5);
  border:3px solid #fff;animation:vfloat 6s ease-in-out infinite}
.vc-slot-c .vcard{animation-delay:-2s;animation-duration:7s}
.vc-slot-a .vcard{animation-delay:-4s;animation-duration:6.6s}
@keyframes vfloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-13px)}}
.vcard::after{content:"";position:absolute;inset:0;
  background:radial-gradient(circle at 32% 26%,rgba(255,255,255,.22),transparent 60%)}
.vc-a{background:linear-gradient(158deg,#3C4B66,#212C40)}
.vc-b{background:linear-gradient(158deg,#E8380D,#A6280A)}
.vc-c{background:linear-gradient(158deg,#3A2F28,#191512)}
.vc-tag{position:absolute;top:11px;left:11px;font-size:9.5px;font-weight:800;
  letter-spacing:.4px;color:#fff;background:rgba(255,255,255,.22);
  backdrop-filter:blur(4px);padding:4px 9px;border-radius:100px;z-index:2}
.vc-play{position:absolute;left:50%;top:46%;transform:translate(-50%,-50%);
  width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.96);
  display:grid;place-items:center;z-index:2;
  box-shadow:0 6px 18px rgba(0,0,0,.34)}
.vc-play svg{margin-left:3px}
.vc-play::before{content:"";position:absolute;inset:-9px;border-radius:50%;
  border:2px solid rgba(255,255,255,.6);animation:vpulse 2.4s ease-out infinite}
@keyframes vpulse{0%{transform:scale(.78);opacity:.85}100%{transform:scale(1.5);opacity:0}}
.vc-foot{position:absolute;left:0;right:0;bottom:0;padding:30px 12px 12px;z-index:2;
  background:linear-gradient(transparent,rgba(0,0,0,.6))}
.vc-title{font-size:11.5px;font-weight:700;color:#fff;line-height:1.3;
  margin-bottom:5px;display:-webkit-box;-webkit-line-clamp:2;
  -webkit-box-orient:vertical;overflow:hidden}
.vc-price{display:inline-block;font-family:var(--font-display);font-size:13px;
  font-weight:800;color:#fff;background:rgba(232,56,13,.92);
  padding:3px 9px;border-radius:7px}
.vc-c .vc-price,.vc-a .vc-price{background:rgba(255,255,255,.96);color:#15141A}

/* Cards cu imagine reală — overlay-uri mai puternice pentru legibilitate text + hover */
a.vcard{display:block;text-decoration:none;cursor:pointer;transition:transform .2s ease}
a.vcard:hover{transform:translateY(-4px) scale(1.02)}
.vcard[style*="background-image"]{background-color:#1a1a1a;background-size:cover!important;background-position:center!important}
.vcard[style*="background-image"]::after{
  background:linear-gradient(180deg,rgba(0,0,0,.05) 0%,rgba(0,0,0,0) 30%,rgba(0,0,0,0) 60%,rgba(0,0,0,.5) 100%)
}
.vcard[style*="background-image"] .vc-foot{
  background:linear-gradient(transparent 0%,rgba(0,0,0,.85) 100%);padding-top:42px
}
.vcard[style*="background-image"] .vc-price{background:rgba(255,255,255,.96);color:#15141A}

@media(prefers-reduced-motion:reduce){
  .h2-glow,.vcard,.vc-play::before,.hero2-eyebrow .dot{animation:none!important}
}
@media(max-width:980px){
  .hero2-inner{grid-template-columns:1fr;text-align:center}
  .hero2-sub{margin-left:auto;margin-right:auto}
  .hero2-form{margin-left:auto;margin-right:auto}
  .hero2-eyebrow,.hero2-chips,.hero2-stats{justify-content:center}
  .hero2-right{display:none}
}
@media(max-width:768px){ .hero2{display:none} }
</style>

<!-- HERO (desktop) -->
<?php
$hero_tagline = setting('site_tagline','Anunțuri video. Simplu. Rapid.');
$hero_tagline = str_ireplace('video', '<span class="hl">video</span>', h($hero_tagline));
?>
<section class="hero2">
  <div class="hero2-bg"><span class="h2-glow h2-glow-a"></span><span class="h2-glow h2-glow-b"></span></div>
  <div class="hero2-inner">
    <div class="hero2-left">
      <?php if ($hero_eyebrow): ?>
      <span class="hero2-eyebrow"><span class="dot"></span> <?= h($hero_eyebrow) ?></span>
      <?php endif; ?>
      <h1 class="hero2-title"><?= $hero_tagline ?></h1>
      <?php if ($hero_subtitle): ?>
      <p class="hero2-sub"><?= nl2br(h($hero_subtitle)) ?></p>
      <?php endif; ?>
      <form action="/cauta" method="GET" class="hero2-form">
        <span class="h2-ico"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg></span>
        <input type="search" name="q" placeholder="<?= h($hero_search_ph) ?>" autocomplete="off">
        <button type="submit">Caută</button>
      </form>
      <?php if ($hero_chips): ?>
      <div class="hero2-chips">
        <?php if ($hero_chips_label): ?><span class="lbl"><?= h($hero_chips_label) ?></span><?php endif; ?>
        <?php foreach ($hero_chips as $chip): ?>
          <a href="/cauta?q=<?= urlencode($chip) ?>"><?= h($chip) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($hero_show_stats): ?>
      <div class="hero2-stats">
        <div class="st"><b><?= hero_num($hero_stats['ads']) ?>+</b><span>anunțuri active</span></div>
        <div class="st"><b><?= hero_num($hero_stats['users']) ?>+</b><span>vânzători</span></div>
        <div class="st"><b><?= hero_num($hero_stats['cities']) ?></b><span>orașe</span></div>
      </div>
      <?php endif; ?>
    </div>
    <div class="hero2-right">
      <div class="vcluster">
        <?php
        // Daca avem ≥ 3 anunturi active, le folosim. Altfel, fallback la placeholders existente
        $use_real = count($hero_card_ads) >= 3;
        $slots = ['vc-slot-a', 'vc-slot-c', 'vc-slot-b']; // ordine slot-uri vizuale
        $cards = $use_real
            ? [
                ['ad' => $hero_card_ads[0], 'cls' => 'vc-a'],
                ['ad' => $hero_card_ads[1], 'cls' => 'vc-c'],
                ['ad' => $hero_card_ads[2], 'cls' => 'vc-b'],
              ]
            : [
                ['ad' => null, 'cls' => 'vc-a', 'tag' => 'IMOBILIARE',   'title' => 'Apartament 3 camere Floreasca', 'price' => '125.000 €'],
                ['ad' => null, 'cls' => 'vc-c', 'tag' => 'ELECTRONICE', 'title' => 'MacBook Pro M3 · sigilat',     'price' => '9.800 lei'],
                ['ad' => null, 'cls' => 'vc-b', 'tag' => 'AUTO / MOTO', 'title' => 'BMW Seria 5 520d xDrive 2022', 'price' => '42.500 €'],
              ];

        foreach ($cards as $i => $c):
            $slot = $slots[$i];
            if ($c['ad']) {
                $ad = $c['ad'];
                // Prioritate: prima poză din anunț, apoi video_thumb ca fallback
                $bg = !empty($ad['first_photo'])
                      ? photo_url($ad['first_photo'])
                      : (!empty($ad['video_thumb']) ? thumb_url($ad['video_thumb']) : '');
                $tag    = mb_strtoupper($ad['cat_name']);
                $title  = $ad['title'];
                $price  = $ad['price'] > 0 ? format_price($ad['price'], $ad['currency']) : 'Negociabil';
                $link   = '/anunt/' . $ad['slug'];
            } else {
                $bg = '';
                $tag = $c['tag'];
                $title = $c['title'];
                $price = $c['price'];
                $link = null;
            }
            $card_attrs = $bg ? ' style="background-image:url(' . h($bg) . ');background-size:cover;background-position:center"' : '';
            $wrap_open  = $link ? '<a href="' . h($link) . '" class="vcard ' . $c['cls'] . '"' . $card_attrs . '>' : '<div class="vcard ' . $c['cls'] . '"' . $card_attrs . '>';
            $wrap_close = $link ? '</a>' : '</div>';
        ?>
        <div class="vc-slot <?= $slot ?>">
          <?= $wrap_open ?>
            <span class="vc-tag"><?= h($tag) ?></span>
            <span class="vc-play"><svg width="17" height="17" viewBox="0 0 24 24" fill="<?= $c['cls']==='vc-b'?'#E8380D':'#15141A' ?>"><path d="M7 4v16l13-8z"/></svg></span>
            <div class="vc-foot"><div class="vc-title"><?= h($title) ?></div><div class="vc-price"><?= h($price) ?></div></div>
          <?= $wrap_close ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

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

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebSite","name":"<?= h(setting('site_name')) ?>","url":"<?= h(BASE_URL) ?>","potentialAction":{"@type":"SearchAction","target":"<?= h(BASE_URL) ?>/cauta?q={search_term_string}","query-input":"required name=search_term_string"}}
</script>

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
