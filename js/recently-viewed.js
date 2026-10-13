/* Veedo — Anunțuri văzute recent (stocare locală, fără server) */
(function () {
  'use strict';
  var KEY = 'veedo_recent_views';
  var MAX = 12;

  function read() {
    try {
      var v = JSON.parse(localStorage.getItem(KEY));
      return Array.isArray(v) ? v : [];
    } catch (e) { return []; }
  }

  function write(list) {
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
  }

  /* Înregistrează un anunț vizitat (apelat din pagina de anunț). */
  function recordView(ad) {
    if (!ad || !ad.id) return;
    var list = read().filter(function (x) { return x.id !== ad.id; });
    list.unshift({
      id: ad.id,
      slug: ad.slug || '',
      title: ad.title || '',
      img: ad.img || '',
      price: ad.price || ''
    });
    if (list.length > MAX) list = list.slice(0, MAX);
    write(list);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Populează strip-ul de pe homepage, dacă există. */
  function renderStrip() {
    var strip = document.getElementById('rv-strip');
    if (!strip) return;
    var section = document.getElementById('recent-viewed');
    var current = window.__veedoCurrentAdId || null;
    var list = read().filter(function (x) { return x.id !== current; });

    if (!list.length) {
      if (section) section.style.display = 'none';
      return;
    }

    var html = '';
    list.forEach(function (ad) {
      var href = '/anunt/' + encodeURIComponent(ad.slug || ad.id);
      var thumb = ad.img
        ? '<img src="' + esc(ad.img) + '" alt="" loading="lazy">'
        : '<div class="rv-noimg">📷</div>';
      html += '<a class="rv-card" href="' + href + '">'
        + '<div class="rv-thumb">' + thumb + '</div>'
        + '<div class="rv-title">' + esc(ad.title) + '</div>'
        + '<div class="rv-price">' + esc(ad.price) + '</div>'
        + '</a>';
    });
    strip.innerHTML = html;
    if (section) section.style.display = '';
  }

  window.Veedo = window.Veedo || {};
  window.Veedo.recordView = recordView;
  window.Veedo.renderRecentlyViewed = renderStrip;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderStrip);
  } else {
    renderStrip();
  }
})();
