<?php
// Cache 5 min (APCu) — interogările rulau la fiecare afișare de pagină
$pages = cache_remember('footer_pages', 300, fn() => DB::fetchAll("SELECT title, slug FROM pages WHERE is_active=1 AND in_footer=1 ORDER BY sort_order"));
$fb = setting('social_facebook');
$ig = setting('social_instagram');
$tt = setting('social_tiktok');
$yr = date('Y');
?>
</main><!-- /main -->

<footer class="site-footer" role="contentinfo">
  <?php if (!is_logged() || !str_starts_with(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), '/anunt/nou')): ?>
  <div class="footer-cta">
    <div>
      <h3>Filmează. Postează. Vinde.<br>Totul în mai puțin de un minut.</h3>
      <p>Anunțurile cu video primesc mai multe vizualizări și mesaje. Postarea e gratuită.</p>
    </div>
    <a href="/anunt/nou" class="btn btn-primary btn-lg btn-pill">Adaugă anunț gratuit →</a>
  </div>
  <?php endif; ?>

  <div class="footer-grid">
    <div class="footer-col">
      <?php $logo = safe_url(setting('site_logo'), true); ?>
      <div class="footer-brand">
        <?php if ($logo): ?>
          <img src="<?= h($logo) ?>" alt="<?= h(setting('site_name','Veedo')) ?>" style="height:32px;width:auto;max-width:160px;object-fit:contain;" loading="lazy">
        <?php else: ?>
          <svg viewBox="0 0 120 120" width="32" height="32" aria-hidden="true"><defs><linearGradient id="vdvf" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7357FF"/><stop offset="1" stop-color="#FF4FA3"/></linearGradient></defs><rect x="2" y="2" width="116" height="116" rx="38" fill="url(#vdvf)"/><path d="M36 38 L60 84 L84 38" fill="none" stroke="#D4FF3F" stroke-width="16" stroke-linecap="round" stroke-linejoin="round"/></svg>
          veedo
        <?php endif; ?>
      </div>
      <p><?= h(setting('site_tagline','Anunțuri video. Simplu. Rapid.')) ?> <?= h(setting('site_description', '')) ?></p>
      <?php if ($fb || $ig || $tt): ?>
      <div class="footer-social">
        <?php if ($fb = safe_url($fb)): ?><a href="<?= h($fb) ?>" target="_blank" rel="noopener" aria-label="Facebook"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M14 8h3V4h-3c-2.8 0-4 1.8-4 4.3V10H7v4h3v8h4v-8h3l1-4h-4V8.6c0-.4.3-.6.6-.6z"/></svg></a><?php endif; ?>
        <?php if ($ig = safe_url($ig)): ?><a href="<?= h($ig) ?>" target="_blank" rel="noopener" aria-label="Instagram"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg></a><?php endif; ?>
        <?php if ($tt = safe_url($tt)): ?><a href="<?= h($tt) ?>" target="_blank" rel="noopener" aria-label="TikTok"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M16.5 3c.4 2.3 1.8 3.8 4 4v3.2c-1.5 0-2.9-.4-4-1.2v6.6A6.1 6.1 0 1 1 10.4 9.5v3.3a2.9 2.9 0 1 0 2.9 2.8V3z"/></svg></a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="footer-col">
      <h4>Explorează</h4>
      <ul class="footer-links">
        <li><a href="/cauta">Toate anunțurile</a></li>
        <li><a href="/cauta?video=1">Anunțuri video</a></li>
        <li><a href="/cauta?verified=1">Vânzători verificați</a></li>
        <?php if(setting_bool("feature_explore")): ?><li><a href="/explore">Explore</a></li><?php endif; ?>
        <?php if(setting_bool("feature_stories")): ?><li><a href="/stories">Stories</a></li><?php endif; ?>
        <?php if(setting_bool("feature_live")): ?><li><a href="/live">LIVE Shopping</a></li><?php endif; ?>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Categorii</h4>
      <ul class="footer-links">
        <?php
        $cats = cache_remember('footer_cats', 300, fn() => DB::fetchAll("SELECT id, name, slug, icon FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order LIMIT 6"));
        foreach ($cats as $c): ?>
          <li><a href="/categorie/<?= h($c['slug']) ?>"><?= h($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Veedo</h4>
      <ul class="footer-links">
        <?php foreach ($pages as $p): ?>
          <li><a href="/pagina/<?= h($p['slug']) ?>"><?= h($p['title']) ?></a></li>
        <?php endforeach; ?>
        <li><a href="/contact">Contact</a></li>
        <?php if(setting_bool("feature_afiliere")): ?><li><a href="/afiliere">Program afiliere</a></li><?php endif; ?>
        <?php if(setting_bool("feature_alerte")): ?><li><a href="/alerte">Alerte căutare</a></li><?php endif; ?>
        <li><button type="button" class="theme-toggle">◐ <span class="theme-label">Temă luminoasă</span></button></li>
      </ul>
    </div>
  </div>

  <div class="footer-wordmark" aria-hidden="true">veedo</div>

  <div class="footer-bottom">
    <span><?= h(setting('footer_text', "© $yr Veedo.ro - Toate drepturile rezervate")) ?></span>
    <nav aria-label="Legal">
      <a href="/pagina/termeni-si-conditii">Termeni și condiții</a>
      <a href="/pagina/politica-confidentialitate">Confidențialitate</a>
      <a href="/pagina/politica-cookies">Cookies</a>
      <a href="/sitemap.xml">Sitemap</a>
    </nav>
  </div>
</footer>

<?php
// Scripturile de upload se încarcă doar pe paginile care au formulare de upload
$_sp = $_SERVER['SCRIPT_NAME'] ?? '';
$_needs_upload = (bool)preg_match('~/(anunt-nou|anunt-editeaza|stories|mesaje|live-start)\.php$|/cont/setari\.php$~', $_sp);
$_v = '20261013';
?>
<?php if ($_needs_upload): ?>
<script src="/js/image-compress.js?v=<?= $_v ?>" defer></script>
<script src="/js/video-upload-progress.js?v=<?= $_v ?>" defer></script>
<?php endif; ?>
<script src="/js/recently-viewed.js?v=<?= $_v ?>" defer></script>
<script src="/js/app.js?v=<?= $_v ?>" defer></script>
<?= $extra_js ?? '' ?>

<?php if (is_logged() && defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY): ?>
<script>
// ── Web Push ──────────────────────────────────────────────
(function() {
    const VAPID_PUBLIC_KEY = <?= js_json(VAPID_PUBLIC_KEY) ?>;

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    // Register Service Worker
    navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(reg => {
            window._swReg = reg;
            return reg.update();
        })
        .then(() => checkSubscription())
        .catch(err => console.warn('SW register error:', err));

    async function checkSubscription() {
        const reg = window._swReg;
        if (!reg) return;

        const sub = await reg.pushManager.getSubscription();

        if (sub) {
            // Deja subscris - actualizam pe server
            await saveSubscription(sub);
            window._pushSubscribed = true;
        }
        // Daca nu e subscris, asteptam interactia utilizatorului
    }

    // Subscribe la push (apelat din UI)
    window.requestPushPermission = async function() {
        try {
            const reg = window._swReg || await navigator.serviceWorker.ready;
            const perm = await Notification.requestPermission();

            if (perm !== 'granted') {
                console.log('Push permission denied');
                return false;
            }

            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
            });

            await saveSubscription(sub);
            window._pushSubscribed = true;

            // Ascundem prompt-ul
            const prompt = document.getElementById('push-prompt');
            if (prompt) prompt.style.display = 'none';

            return true;
        } catch(e) {
            console.warn('Push subscribe error:', e);
            return false;
        }
    };

    // Unsubscribe
    window.disablePush = async function() {
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) {
                await fetch('/api/push-subscribe.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({action:'unsubscribe', endpoint: sub.endpoint}),
                    credentials: 'same-origin'
                });
                await sub.unsubscribe();
                window._pushSubscribed = false;
            }
        } catch(e) {}
    };

    async function saveSubscription(sub) {
        try {
            const data = sub.toJSON();
            await fetch('/api/push-subscribe.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify(data),
                credentials: 'same-origin'
            });
        } catch(e) {}
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw     = window.atob(base64);
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }

    // Arata prompt dupa 10 secunde daca nu e subscris si nu a fost refuzat
    setTimeout(async () => {
        if (window._pushSubscribed) return;
        if (Notification.permission === 'denied') return;
        if (Notification.permission === 'granted') { await window.requestPushPermission(); return; }
        if (localStorage.getItem('push_dismissed')) return;

        const prompt = document.getElementById('push-prompt');
        if (prompt) prompt.style.display = 'flex';
    }, 10000);
})();
</script>

<!-- Push prompt banner -->
<div id="push-prompt" style="display:none;position:fixed;bottom:80px;left:50%;transform:translateX(-50%);
     background:var(--surface);border:1px solid var(--border);border-radius:14px;
     box-shadow:0 8px 32px rgba(0,0,0,0.15);padding:14px 18px;
     display:none;align-items:center;gap:12px;z-index:9000;max-width:340px;width:calc(100% - 32px);">
    <div style="font-size:1.6rem;flex-shrink:0;">🔔</div>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:700;margin-bottom:2px;">Activează notificările</div>
        <div style="font-size:12px;color:var(--text-3);">Fii primul care află când primești mesaje sau oferte noi.</div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
        <button onclick="requestPushPermission()" class="btn btn-primary btn-sm" style="font-size:12px;padding:6px 12px;">Da, activează</button>
        <button onclick="localStorage.setItem('push_dismissed','1');this.closest('#push-prompt').style.display='none'" 
                style="background:none;border:none;font-size:11px;color:var(--text-3);cursor:pointer;text-align:center;">Nu acum</button>
    </div>
</div>
<?php endif; ?>

<script>
/* Global reveal-on-scroll for .vh-reveal elements (home feed, similar ads, etc.) */
(function(){
  var els = document.querySelectorAll('.vh-reveal:not(.in)');
  if (!els.length) return;
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(e){
        if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
      });
    }, { threshold: 0.08 });
    els.forEach(function(el,i){
      el.style.transitionDelay = (i % 5 * 55) + 'ms';
      io.observe(el);
    });
  } else {
    els.forEach(function(el){ el.classList.add('in'); });
  }
  // failsafe — never leave content hidden
  setTimeout(function(){
    document.querySelectorAll('.vh-reveal:not(.in)').forEach(function(el){ el.classList.add('in'); });
  }, 2500);
})();
</script>

</body>
</html>