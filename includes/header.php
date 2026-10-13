<?php
$siteName = setting('site_name', 'Veedo');
$u = is_logged() ? current_user() : null;
$seo_title ??= $siteName;
$seo_desc  ??= setting('site_description');

// GA (validat la salvare: doar ID-uri de forma G-XXXX / UA-XXXX)
$ga_id = setting('google_analytics');
if ($ga_id && !preg_match('/^[A-Z0-9-]{4,30}$/i', $ga_id)) $ga_id = '';

// Titlu: nu repetăm numele site-ului dacă e deja în titlu
$_page_title = str_contains($seo_title, $siteName) ? $seo_title : $seo_title . ' | ' . $siteName;

// Temă: preferința utilizatorului → tema implicită a site-ului → light
$_theme_pref = $u['theme_preference'] ?? setting('site_theme_default', 'light');
if (!in_array($_theme_pref, ['light', 'dark', 'auto'], true)) $_theme_pref = 'light';
$_theme_attr = $_theme_pref === 'dark' ? 'dark' : 'light';

// Culorile brandului din Admin → Aspect (înainte se salvau dar nu se aplicau pe site)
$_c1 = safe_color(setting('site_color_primary', ''), '');
$_c2 = safe_color(setting('site_color_secondary', ''), '');

// URL canonic: fără parametri de tracking (ref, utm_*, fbclid…)
$_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$_keep = array_intersect_key($_GET, array_flip(['q', 'cat', 'page', 'pagina', 'sort']));
$_seo_url = !empty($seo_url) ? $seo_url : rtrim(BASE_URL, '/') . $_path . ($_keep ? '?' . http_build_query($_keep) : '');
$_seo_dsc = $seo_desc ?? setting('site_description', '');
$_seo_img = !empty($seo_image) ? $seo_image : setting('og_default_image', setting('og_image', setting('site_logo', '')));
if ($_seo_img && !preg_match('~^https?://~i', $_seo_img)) {
    $_seo_img = rtrim(BASE_URL, '/') . '/' . ltrim($_seo_img, '/');
}
$_seo_type = $seo_type ?? 'website';
$_cur = $_path;
$_unread_notif = $u ? unread_notifications((int)$u['id']) : 0;
$_unread_msgs  = $u ? unread_messages((int)$u['id']) : 0;
$logo = safe_url(setting('site_logo'), true);
$fav  = safe_url(setting('site_favicon'), true);
?>
<!DOCTYPE html>
<html lang="ro" data-theme="<?= $_theme_attr ?>" data-theme-pref="<?= $_theme_pref ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#F6F5F2" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0C0C10" media="(prefers-color-scheme: dark)">
<title><?= h($_page_title) ?></title>
<?php if(!empty($seo_desc)): ?><meta name="description" content="<?= h(mb_substr($seo_desc, 0, 300)) ?>"><?php endif; ?>
<meta name="robots" content="<?= h(setting('meta_robots','index,follow')) ?>">
<link rel="canonical" href="<?= h($_seo_url) ?>">
<meta property="og:type" content="<?= h($_seo_type) ?>">
<meta property="og:site_name" content="<?= h($siteName) ?>">
<meta property="og:title" content="<?= h($_page_title) ?>">
<?php if ($_seo_dsc): ?><meta property="og:description" content="<?= h(mb_substr($_seo_dsc, 0, 300)) ?>"><?php endif; ?>
<meta property="og:url" content="<?= h($_seo_url) ?>">
<meta property="og:locale" content="ro_RO">
<?php if ($_seo_img): ?>
<meta property="og:image" content="<?= h($_seo_img) ?>">
<meta property="og:image:alt" content="<?= h($seo_title ?? $siteName) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $_seo_img ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= h($_page_title) ?>">
<?php if ($_seo_dsc): ?><meta name="twitter:description" content="<?= h(mb_substr($_seo_dsc, 0, 300)) ?>"><?php endif; ?>
<?php if ($_seo_img): ?><meta name="twitter:image" content="<?= h($_seo_img) ?>"><?php endif; ?>

<script>
/* Temă înainte de randare (fără „flash" de culoare) */
(function(){
  var d=document.documentElement,p=d.getAttribute('data-theme-pref'),s=null;
  try{s=localStorage.getItem('veedo_theme');}catch(e){}
  var t=s||p;
  if(t==='auto'){t=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}
  d.setAttribute('data-theme',t==='dark'?'dark':'light');
})();
</script>

<!-- Fonturi: preconnect + display=swap (înainte: @import în CSS, care bloca randarea) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&display=swap">
<link rel="stylesheet" href="/css/style.css?v=<?= CSS_VER ?>">
<?php if ($_c1 || $_c2): ?>
<style>:root{<?= $_c1 ? '--brand:'.$_c1.';--flame:'.$_c1.';' : '' ?><?= $_c2 ? '--brand2:'.$_c2.';' : '' ?>--grad-ember:linear-gradient(135deg,<?= $_c2 ?: '#FF7A2F' ?> 0%,<?= $_c1 ?: '#FF4A1C' ?> 60%);--grad-text:linear-gradient(100deg,<?= $_c1 ?: '#FF4A1C' ?>,<?= $_c2 ?: '#FF8A00' ?>);}</style>
<?php endif; ?>
<?php if($ga_id): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($ga_id) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= js_json($ga_id) ?>,{anonymize_ip:true});</script>
<?php endif; ?>
<?php if ($fav): ?>
<link rel="icon" href="<?= h($fav) ?>">
<?php else: ?>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<?php endif; ?>

<!-- PWA -->
<link rel="manifest" href="/manifest.php">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/icon-180.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h($siteName) ?>">
<meta name="application-name" content="<?= h($siteName) ?>">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<script>
/* CSRF automat: atașează tokenul la toate cererile same-origin care modifică date
   (fetch, XMLHttpRequest și formulare POST), inclusiv cele construite dinamic. */
(function(){
  var T=document.querySelector('meta[name="csrf-token"]').content;
  function same(u){try{return new URL(u,location.href).origin===location.origin;}catch(e){return false;}}
  function unsafe(m){return !/^(GET|HEAD|OPTIONS)$/i.test(m||'GET');}
  var of=window.fetch;
  if(of){window.fetch=function(input,init){
    init=init||{};
    var url=typeof input==='string'?input:(input&&input.url)||'';
    var m=init.method||(input&&input.method)||'GET';
    if(unsafe(m)&&same(url)){
      var h=new Headers(init.headers||(input&&input.headers)||{});
      if(!h.has('X-CSRF-Token'))h.set('X-CSRF-Token',T);
      init.headers=h;
      if(!init.credentials)init.credentials='same-origin';
    }
    return of.call(this,input,init);
  };}
  var xo=XMLHttpRequest.prototype.open,xs=XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open=function(m,u){this._vm=m;this._vu=u;return xo.apply(this,arguments);};
  XMLHttpRequest.prototype.send=function(){
    if(unsafe(this._vm)&&same(this._vu)){try{this.setRequestHeader('X-CSRF-Token',T);}catch(e){}}
    return xs.apply(this,arguments);
  };
  function arm(f){
    if(!f||!/^post$/i.test(f.method||'')||!same(f.action||location.href))return;
    if(!f.querySelector('input[name="csrf_token"]')){
      var i=document.createElement('input');i.type='hidden';i.name='csrf_token';i.value=T;f.appendChild(i);
    }
  }
  document.addEventListener('submit',function(e){arm(e.target);},true);
  var fs=HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit=function(){arm(this);return fs.apply(this,arguments);};
  window.VEEDO_CSRF=T;
})();
</script>
<?php if ($u): ?>
<script>
/* Polling notificări (doar când tab-ul e vizibil) */
(function(){
  function poll(){
    if(document.hidden)return;
    var b=document.getElementById('notif-badge');if(!b)return;
    fetch('/api/notif-count.php',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      b.textContent=d.count>9?'9+':d.count;b.style.display=d.count>0?'flex':'none';
    }).catch(function(){});
  }
  setTimeout(function(){poll();setInterval(poll,45000);},8000);
})();
</script>
<?php endif; ?>
<?php
$custom_css = setting('custom_css', '');
if ($custom_css):
    // Previne ieșirea din tag-ul <style> prin injecție
    $safe_css = str_ireplace(['</style', '<script'], '', $custom_css);
?>
<style id="site-custom-css"><?= $safe_css ?></style>
<?php endif; ?>
<noscript><style>.vh-reveal{opacity:1!important;transform:none!important}</style></noscript>
<?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body>
<a href="#main-content" class="skip-link">Sari la conținut</a>

<header class="site-header">
  <div class="header-inner">

    <a href="/" class="site-logo" aria-label="<?= h($siteName) ?> — Acasă">
      <?php if ($logo): ?>
        <img src="<?= h($logo) ?>" alt="<?= h($siteName) ?>" height="32">
      <?php else: ?>
        <svg class="logo-mark" viewBox="0 0 120 120" width="30" height="30" aria-hidden="true"><defs><linearGradient id="vdv" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FF8A00"/><stop offset=".55" stop-color="#FF4A1C"/><stop offset="1" stop-color="#E0360B"/></linearGradient></defs><rect x="4" y="4" width="112" height="112" rx="34" fill="url(#vdv)"/><path d="M38 40 L60 82 L82 40" fill="none" stroke="#fff" stroke-width="15" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="logo-text">veedo</span>
      <?php endif; ?>
    </a>

    <div class="header-search">
      <form action="/cauta" method="GET" role="search">
        <span class="search-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></span>
        <input type="search" name="q" class="search-auto" placeholder="Caută mașini, apartamente, telefoane…" autocomplete="off"
               aria-label="Caută anunțuri" value="<?= isset($_GET['q']) && is_string($_GET['q']) ? h($_GET['q']) : '' ?>">
        <div class="search-dropdown" role="listbox"></div>
        <button type="submit" class="search-submit">Caută</button>
      </form>
    </div>

    <div class="header-actions">
      <a href="/cauta" class="icon-btn header-mobile-search-btn" aria-label="Caută">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      </a>

      <?php if (setting_bool('feature_stories')): ?><a href="/stories" class="nav-chip nav-link-desktop">Stories</a><?php endif; ?>
      <?php if (setting_bool('feature_live')): ?><a href="/live" class="nav-chip nav-link-desktop"><span class="live-dot"></span>LIVE</a><?php endif; ?>

      <?php if ($u): ?>
        <a href="/feed" class="icon-btn desktop-only-action" title="Feed-ul tău" aria-label="Feed">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>
        </a>
        <a href="/notificari" class="icon-btn" aria-label="Notificări" id="notif-bell">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span id="notif-badge" class="badge-dot" style="display:<?= $_unread_notif > 0 ? 'flex' : 'none' ?>"><?= $_unread_notif > 9 ? '9+' : (int)$_unread_notif ?></span>
        </a>
        <a href="/mesaje" class="icon-btn desktop-only-action" aria-label="Mesaje">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <?php if ($_unread_msgs > 0): ?><span class="badge-dot"><?= $_unread_msgs > 9 ? '9+' : (int)$_unread_msgs ?></span><?php endif; ?>
        </a>

        <div class="desktop-only-action" style="position:relative;">
          <button type="button" class="avatar-btn" onclick="toggleUserMenu(event)" aria-label="Meniu cont" aria-haspopup="true" aria-expanded="false" id="user-menu-btn">
            <img src="<?= h(avatar_url($u['avatar'] ?? null, $u['name'])) ?>" width="32" height="32" alt="">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div id="user-menu" class="user-menu hidden" role="menu">
            <div class="user-menu-head">
              <strong><?= h($u['name']) ?></strong>
              <span><?= h($u['email']) ?></span>
            </div>
            <a href="/cont/dashboard" class="dropdown-item"><span class="di-ico">📊</span> Dashboard</a>
            <a href="/cont/anunturile-mele" class="dropdown-item"><span class="di-ico">📋</span> Anunțurile mele</a>
            <a href="/cont/favorite" class="dropdown-item"><span class="di-ico">♡</span> Favorite</a>
            <a href="/mesaje" class="dropdown-item"><span class="di-ico">💬</span> Mesaje<?php if ($_unread_msgs > 0): ?> <span class="badge badge-danger" style="margin-left:auto"><?= (int)$_unread_msgs ?></span><?php endif; ?></a>
            <?php if (setting_bool('feature_alerte')): ?><a href="/alerte" class="dropdown-item"><span class="di-ico">🔔</span> Alerte căutare</a><?php endif; ?>
            <a href="/cont/tranzactii" class="dropdown-item"><span class="di-ico">💳</span> Tranzacții</a>
            <?php if (setting_bool('feature_afiliere')): ?><a href="/cont/afiliere" class="dropdown-item"><span class="di-ico">💰</span> Program afiliere</a><?php endif; ?>
            <a href="/cont/abonamente" class="dropdown-item"><span class="di-ico">⭐</span> Planuri & prețuri</a>
            <a href="/cont/setari" class="dropdown-item"><span class="di-ico">⚙️</span> Setări</a>
            <button type="button" class="dropdown-item theme-toggle-row" style="width:100%;border:0;background:none;cursor:pointer;text-align:left" onclick="VeedoTheme.toggle()">
              <span class="di-ico">◐</span> <span class="theme-label">Temă întunecată</span>
            </button>
            <?php if (is_admin()): ?>
            <div class="sep"></div>
            <a href="/admin" class="dropdown-item accent"><span class="di-ico">🛡️</span> Panou admin</a>
            <?php endif; ?>
            <div class="sep"></div>
            <a href="/logout" class="dropdown-item danger"><span class="di-ico">↪</span> Ieși din cont</a>
          </div>
        </div>
      <?php else: ?>
        <a href="/login" class="btn btn-ghost btn-sm guest-login">Intră în cont</a>
      <?php endif; ?>

      <a href="/anunt/nou" class="btn btn-primary btn-pill <?= $u ? 'desktop-only-action' : '' ?>" style="margin-left:4px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span class="post-btn-text">Adaugă <span class="post-btn-text-long">anunț</span></span>
      </a>
    </div>
  </div>
</header>

<?php
$_nav = function (string $prefix) use ($_cur): string {
    return ($prefix === '/' ? $_cur === '/' : str_starts_with($_cur, $prefix)) ? ' active' : '';
};
?>
<nav class="mobile-bottom-nav" aria-label="Navigare principală">
  <a href="/" class="mob-nav-item<?= $_nav('/') ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg>
    <span>Acasă</span>
  </a>
  <a href="<?= setting_bool('feature_explore') ? '/explore' : '/cauta' ?>" class="mob-nav-item<?= $_nav(setting_bool('feature_explore') ? '/explore' : '/cauta') ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polygon points="16 8 13.5 13.5 8 16 10.5 10.5 16 8"/></svg>
    <span>Explorează</span>
  </a>
  <a href="/anunt/nou" class="mob-nav-item mob-nav-post" aria-label="Adaugă un anunț">
    <span class="post-fab"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.8" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
  </a>
  <?php if ($u): ?>
  <a href="/mesaje" class="mob-nav-item<?= $_nav('/mesaje') ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <?php if ($_unread_msgs > 0): ?><span class="badge-dot"><?= $_unread_msgs > 9 ? '9+' : (int)$_unread_msgs ?></span><?php endif; ?>
    <span>Mesaje</span>
  </a>
  <a href="/cont/dashboard" class="mob-nav-item<?= $_nav('/cont') ?>">
    <img src="<?= h(avatar_url($u['avatar'] ?? null, $u['name'])) ?>" width="25" height="25" alt="">
    <span>Cont</span>
  </a>
  <?php else: ?>
  <a href="/cauta" class="mob-nav-item<?= $_nav('/cauta') ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <span>Caută</span>
  </a>
  <a href="/login" class="mob-nav-item<?= $_nav('/login') ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    <span>Cont</span>
  </a>
  <?php endif; ?>
</nav>

<script>
function toggleUserMenu(e) {
  e.stopPropagation();
  var m = document.getElementById('user-menu'), b = document.getElementById('user-menu-btn');
  var open = m.classList.toggle('hidden') === false;
  if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', function (e) {
  var m = document.getElementById('user-menu');
  if (m && !e.target.closest('#user-menu')) m.classList.add('hidden');
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') { var m = document.getElementById('user-menu'); if (m) m.classList.add('hidden'); }
});
</script>

<main id="main-content">
<?php flash_render(); ?>
