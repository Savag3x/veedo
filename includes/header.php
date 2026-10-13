<?php
$siteName = setting('site_name', 'Veedo');
$u = is_logged() ? current_user() : null;
$seo_title ??= $siteName;
$seo_desc  ??= setting('site_description');

// GA
$ga_id = setting('google_analytics');
?>
<!DOCTYPE html>
<html lang="ro" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#FFFFFF">
<title><?= h($seo_title) ?> | <?= h($siteName) ?></title>
<?php if(!empty($seo_desc)): ?><meta name="description" content="<?= h($seo_desc) ?>"><?php endif; ?>
<meta name="robots" content="<?= h(setting('meta_robots','index,follow')) ?>">
<?php
// ── SEO: canonical + Open Graph + Twitter Card ──
$_seo_url = !empty($seo_url)
    ? $seo_url
    : rtrim(BASE_URL, '/') . ($_SERVER['REQUEST_URI'] ?? '/');
$_seo_ttl = ($seo_title ?? $siteName) . ' | ' . $siteName;
$_seo_dsc = $seo_desc ?? setting('site_description', '');
$_seo_img = !empty($seo_image)
    ? $seo_image
    : setting('og_default_image', setting('site_logo', ''));
if ($_seo_img && !preg_match('~^https?://~i', $_seo_img)) {
    $_seo_img = rtrim(BASE_URL, '/') . '/' . ltrim($_seo_img, '/');
}
$_seo_type = $seo_type ?? 'website';
?>
<link rel="canonical" href="<?= h($_seo_url) ?>">
<meta property="og:type" content="<?= h($_seo_type) ?>">
<meta property="og:site_name" content="<?= h($siteName) ?>">
<meta property="og:title" content="<?= h($_seo_ttl) ?>">
<?php if ($_seo_dsc): ?><meta property="og:description" content="<?= h($_seo_dsc) ?>"><?php endif; ?>
<meta property="og:url" content="<?= h($_seo_url) ?>">
<meta property="og:locale" content="ro_RO">
<?php if ($_seo_img): ?>
<meta property="og:image" content="<?= h($_seo_img) ?>">
<meta property="og:image:alt" content="<?= h($seo_title ?? $siteName) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= $_seo_img ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= h($_seo_ttl) ?>">
<?php if ($_seo_dsc): ?><meta name="twitter:description" content="<?= h($_seo_dsc) ?>"><?php endif; ?>
<?php if ($_seo_img): ?><meta name="twitter:image" content="<?= h($_seo_img) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="/css/style.css?v=<?= CSS_VER ?>">
<?php if($ga_id): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($ga_id) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= h($ga_id) ?>');</script>
<?php endif; ?>
<?php
$logo = setting('site_logo');
$fav  = setting('site_favicon');
if ($fav): ?>
<link rel="icon" href="<?= h($fav) ?>" type="image/svg+xml">
<?php else: ?>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/favicon.svg">
<?php endif; ?>

<!-- PWA — Add to Home Screen / install prompt -->
<link rel="manifest" href="/manifest.php">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/icon-180.png">
<link rel="apple-touch-icon" sizes="192x192" href="/assets/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h($siteName) ?>">
<meta name="application-name" content="<?= h($siteName) ?>">
<script>
// Notification polling (logged in users only)
<?php if($u): ?>
(function(){
  function pollNotifs(){
    var b=document.getElementById('notif-badge');
    if(!b) return;
    fetch('/api/notif-count.php',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        b.textContent=d.count>9?'9+':d.count;
        b.style.display=d.count>0?'flex':'none';
      }).catch(function(){});
  }
  setTimeout(function(){pollNotifs();setInterval(pollNotifs,30000);},5000);
})();
<?php endif; ?>
// Theme
(function(){

})();
</script>
  <meta name="csrf-token" content="<?= csrf_token() ?>">
<?php
$custom_css = setting('custom_css', '');
if ($custom_css):
    // Previne ieșirea din tag-ul <style> prin injecție (CSS exfiltration / XSS)
    $safe_css = str_ireplace(['</style', '</Style', '</STYLE'], '< /style', $custom_css);
?>
<style id="site-custom-css"><?= $safe_css ?></style>
<?php endif; ?>


<noscript><style>.vh-reveal{opacity:1!important;transform:none!important}</style></noscript>
</head>
<body>

<!-- HEADER -->
<header class="site-header">
  <div class="header-inner">

    <!-- Logo -->
    <a href="/" class="site-logo" style="flex-shrink:0;">
      <?php if($logo): ?>
        <img src="<?= h($logo) ?>" alt="<?= h($siteName) ?>" style="height:32px;width:auto;object-fit:contain;">
      <?php else: ?>
        <svg class="logo-mark" viewBox="0 0 120 120" width="30" height="30" aria-hidden="true"><defs><linearGradient id="vdv" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FF8A4C"/><stop offset=".5" stop-color="#E8380D"/><stop offset="1" stop-color="#C42409"/></linearGradient></defs><path d="M30 32 L60 86 L90 32" fill="none" stroke="url(#vdv)" stroke-width="25" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="logo-text">veedo</span>
      <?php endif; ?>
    </a>

    <!-- Search (desktop only) -->
    <div class="header-search" style="flex:1;max-width:420px;margin:0 12px;position:relative;">
      <form action="/cauta" method="GET">
        <span class="search-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
        <input type="search" name="q" placeholder="Caută mașini, apartamente..." autocomplete="off"
               value="<?= isset($_GET['q']) ? h($_GET['q']) : '' ?>"
               style="width:100%;padding:8px 12px 8px 36px;border:1.5px solid var(--border);border-radius:100px;background:var(--bg-2);font-size:14px;outline:none;">
      </form>
    </div>

    <!-- Right actions -->
    <div class="header-actions" style="display:flex;align-items:center;gap:2px;margin-left:auto;flex-shrink:0;">

      <!-- Mobile search toggle -->
      <button class="btn btn-ghost btn-sm header-mobile-search-btn" onclick="toggleMobileSearch()" aria-label="Caută" style="display:none;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      </button>

      <!-- Desktop links -->
      <?php if(setting_bool("feature_stories")): ?><a href="/stories" style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:var(--text-2);text-decoration:none;padding:6px 10px;border-radius:var(--radius);white-space:nowrap;" class="nav-link-desktop">📸 Stories</a><?php endif; ?>
      <?php if(setting_bool("feature_live")): ?><a href="/live" style="display:flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:var(--text-2);text-decoration:none;padding:6px 10px;border-radius:var(--radius);white-space:nowrap;" class="nav-link-desktop">🔴 LIVE</a><?php endif; ?>

      <?php if($u): ?>
        <!-- Feed -->
        <a href="/feed" class="btn btn-ghost btn-sm desktop-only-action" title="Feed-ul tău" aria-label="Feed">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </a>

        <!-- Notifications -->
        <a href="/notificari" class="btn btn-ghost btn-sm" style="position:relative;" aria-label="Notificări" id="notif-bell">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php $unread_notif = unread_notifications($u['id']); ?>
          <span id="notif-badge" style="position:absolute;top:2px;right:2px;background:var(--brand);color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:<?= $unread_notif>0?'flex':'none' ?>;align-items:center;justify-content:center;padding:0 3px;line-height:1;"><?= $unread_notif>9?'9+':($unread_notif?:0) ?></span>
        </a>

        <!-- Messages -->
        <a href="/mesaje" class="btn btn-ghost btn-sm desktop-only-action" aria-label="Mesaje" style="position:relative;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <?php $unread_msgs_h = unread_messages($u['id']); ?>
          <?php if($unread_msgs_h>0): ?>
          <span style="position:absolute;top:2px;right:2px;background:var(--brand);color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?= $unread_msgs_h>9?'9+':$unread_msgs_h ?></span>
          <?php endif; ?>
        </a>

        <!-- User menu -->
        <div class="desktop-only-action" style="position:relative;">
          <button class="btn btn-ghost btn-sm" style="gap:6px;padding:5px 8px;border-radius:100px;" onclick="toggleUserMenu(event)" aria-label="Meniu cont">
            <img src="<?= h(avatar_url($u['avatar']??null,$u['name'])) ?>" width="28" height="28" style="border-radius:50%;object-fit:cover;" alt="">
          </button>
          <div id="user-menu" class="hidden" style="position:absolute;right:0;top:calc(100% + 6px);background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,0.18);min-width:210px;z-index:9999;overflow:hidden;">
            <div style="padding:12px 16px;border-bottom:1px solid var(--border-2);">
              <div style="font-weight:700;font-size:14px;"><?= h($u['name']) ?></div>
              <div style="font-size:12px;color:var(--text-3);"><?= h($u['email']) ?></div>
            </div>
            <a href="/cont/dashboard" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">📊 Dashboard</a>
            <a href="/cont/anunturile-mele" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">📋 Anunțurile mele</a>
            <a href="/cont/favorite" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">❤️ Favorite</a>
            <a href="/cont/setari" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">⚙️ Setări</a>
            <?php if(setting_bool("feature_afiliere")): ?><a href="/cont/afiliere" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">💰 Program Afiliere</a><?php endif; ?>
            <a href="/cont/tranzactii" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">💳 Tranzacții</a>
            <?php if(setting_bool("feature_alerte")): ?><a href="/alerte" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">🔔 Alerte căutare</a><?php endif; ?>
            <a href="/cont/abonamente" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--text-2);text-decoration:none;" class="dropdown-item">⭐ Planuri & Prețuri</a>
            <?php if(is_admin()): ?>
            <div style="border-top:1px solid var(--border-2);"></div>
            <a href="/admin" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--brand);text-decoration:none;font-weight:600;" class="dropdown-item">🛡️ Admin Panel</a>
            <?php endif; ?>
            <div style="border-top:1px solid var(--border-2);"></div>
            <a href="/logout" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:14px;color:var(--danger);text-decoration:none;" class="dropdown-item">🚪 Ieși din cont</a>
          </div>
        </div>

      <?php else: ?>
        <a href="/login" class="btn btn-ghost btn-sm">Intră în cont</a>
      <?php endif; ?>

      <!-- Post ad button -->
      <a href="/anunt/nou" class="btn btn-primary btn-sm <?= $u ? 'desktop-only-action' : '' ?>" style="white-space:nowrap;display:flex;align-items:center;gap:5px;flex-shrink:0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span class="post-btn-text">+ Postează</span>
      </a>

    </div>
  </div>

  <!-- Mobile search bar (hidden by default) -->
  <div id="mobile-search-bar" style="display:none;padding:8px 16px 12px;border-top:1px solid var(--border);">
    <form action="/cauta" method="GET">
      <input type="search" name="q" placeholder="Caută orice anunț..." autocomplete="off"
             value="<?= isset($_GET['q']) ? h($_GET['q']) : '' ?>"
             style="width:100%;padding:10px 16px;border:1.5px solid var(--border);border-radius:100px;background:var(--bg-2);font-size:15px;outline:none;"
             autofocus id="mobile-search-input">
    </form>
  </div>
</header>
<script>
function toggleUserMenu(e) {
    e.stopPropagation();
    document.getElementById('user-menu').classList.toggle('hidden');
}
document.addEventListener('click', function() {
    var m = document.getElementById('user-menu');
    if (m) m.classList.add('hidden');
});
</script>

<!-- Mobile bottom nav -->
<?php if($u): ?>
<nav class="mobile-bottom-nav">
  <a href="/" class="mob-nav-item <?= ($_SERVER['REQUEST_URI']==='/')?'active':'' ?>">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    <span>Acasă</span>
  </a>
  <?php if(setting_bool("feature_explore")): ?><a href="/explore" class="mob-nav-item <?= strpos($_SERVER['REQUEST_URI'],'/explore')===0?'active':'' ?>">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
    <span>Explorează</span>
  </a><?php endif; ?>
  <a href="/anunt/nou" class="mob-nav-item mob-nav-post" aria-label="Postează un anunț">
    <div class="post-fab">
      <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.6" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    </div>
  </a>
  <a href="/mesaje" class="mob-nav-item <?= strpos($_SERVER['REQUEST_URI'],'/mesaje')===0?'active':'' ?>" style="position:relative;">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <?php if($unread_msgs_h>0): ?><span style="position:absolute;top:4px;right:18px;background:var(--brand);color:#fff;font-size:9px;min-width:14px;height:14px;border-radius:7px;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?= $unread_msgs_h ?></span><?php endif; ?>
    <span>Mesaje</span>
  </a>
  <a href="/cont/dashboard" class="mob-nav-item <?= strpos($_SERVER['REQUEST_URI'],'/cont')===0?'active':'' ?>">
    <img src="<?= h(avatar_url($u['avatar']??null,$u['name'])) ?>" width="24" height="24" style="border-radius:50%;object-fit:cover;" alt="">
    <span>Cont</span>
  </a>
</nav>
<?php else: ?>
<nav class="mobile-bottom-nav">
  <a href="/" class="mob-nav-item">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
    <span>Acasă</span>
  </a>
  <a href="/explore" class="mob-nav-item">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
    <span>Explorează</span>
  </a>
  <a href="/cauta" class="mob-nav-item">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <span>Caută</span>
  </a>
  <a href="/login" class="mob-nav-item">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    <span>Cont</span>
  </a>
</nav>
<?php endif; ?>

<script>
// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#user-menu') && !e.target.closest('[onclick*="user-menu"]')) {
        document.getElementById('user-menu')?.classList.add('hidden');
    }
});

// Mobile search toggle
function toggleMobileSearch() {
    var bar = document.getElementById('mobile-search-bar');
    if (bar.style.display === 'none') {
        bar.style.display = 'block';
        document.getElementById('mobile-search-input')?.focus();
    } else {
        bar.style.display = 'none';
    }
}

// Close dropdown on item click
document.querySelectorAll('.dropdown-item').forEach(function(item) {
    item.addEventListener('click', function() {
        document.getElementById('user-menu')?.classList.add('hidden');
    });
});
</script>

<?php if (isset($extra_head)) echo $extra_head; ?>
<main id="main-content">