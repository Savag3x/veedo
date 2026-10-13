/* ============================================
   VEEDO.RO - Main JavaScript
   ============================================ */

// ---- Temă (light / dark / auto) ----
// Preferința se salvează local + în contul utilizatorului (api/theme.php).
const VeedoTheme = {
  current() { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; },
  apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('veedo_theme', theme); } catch (e) {}
    document.querySelectorAll('.theme-label').forEach(el => {
      el.textContent = theme === 'dark' ? 'Temă luminoasă' : 'Temă întunecată';
    });
  },
  toggle() {
    const next = this.current() === 'dark' ? 'light' : 'dark';
    this.apply(next);
    fetch('/api/theme.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({theme: next}) }).catch(() => {});
  },
  init() {
    this.apply(this.current());
    document.querySelectorAll('.theme-toggle').forEach(btn => btn.addEventListener('click', () => this.toggle()));
  }
};
window.VeedoTheme = VeedoTheme;

// ---- Escape HTML (pentru orice text inserat cu innerHTML) ----
function escHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ---- AJAX helper cu CSRF automat ----
function getCsrfToken() {
  // Încearcă input hidden (în form-uri)
  const el = document.querySelector('[name=csrf_token]');
  if (el) return el.value;
  // Fallback: meta tag din <head> (disponibil pe toate paginile)
  const meta = document.querySelector('meta[name=csrf-token]');
  return meta ? meta.getAttribute('content') : '';
}

async function ajax(url, data = {}, method = 'POST') {
  const headers = {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  };
  // Injectează CSRF token automat pe POST/DELETE/PUT
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = getCsrfToken();
    if (!data.csrf_token) data.csrf_token = getCsrfToken();
  }
  const res = await fetch(url, {
    method,
    headers,
    credentials: 'same-origin',
    body: method !== 'GET' ? JSON.stringify(data) : undefined
  });
  if (!res.ok && res.status === 429) {
    alert('Prea multe cereri. Așteaptă un moment și încearcă din nou.');
    throw new Error('Rate limited');
  }
  return res.json();
}

// ---- Like button ----
function initLikes() {
  document.querySelectorAll('.like-btn').forEach(btn => {
    btn.addEventListener('click', async function(e) {
      e.preventDefault();
      if (!this.dataset.adId) return;
      if (this.classList.contains('loading')) return;
      this.classList.add('loading');
      try {
        const r = await ajax('/api/like.php', { ad_id: parseInt(this.dataset.adId) });
        if (r && r.success) {
          this.classList.toggle('liked', r.liked);
          // Actualizeaza fill SVG
          const path = this.querySelector('svg path');
          if (path) path.setAttribute('fill', r.liked ? 'currentColor' : 'none');
          const counter = this.querySelector('.like-count');
          if (counter) counter.textContent = r.count;
        } else if (r && r.redirect) {
          window.location.href = r.redirect;
        }
      } catch(err) {
        // Eroare retea sau server - ignora silentios
        console.warn('Like error:', err);
      }
      this.classList.remove('loading');
    });
  });
}

// ---- Favorite button ----
function initFavorites() {
  document.querySelectorAll('.fav-btn').forEach(btn => {
    btn.addEventListener('click', async function(e) {
      e.preventDefault();
      if (!this.dataset.adId) return;
      if (this.classList.contains('loading')) return;
      this.classList.add('loading');
      try {
        const r = await ajax('/api/favorite.php', { ad_id: parseInt(this.dataset.adId) });
        if (r && r.success) {
          this.classList.toggle('saved', r.saved);
          this.title = r.saved ? 'Eliminat din favorite' : 'Adăugat la favorite';
          const label = this.querySelector('.fav-label');
          if (label) label.textContent = r.saved ? 'Salvat' : 'Salvează';
          const path = this.querySelector('svg path');
          if (path) path.setAttribute('fill', r.saved ? 'currentColor' : 'none');
        } else if (r && r.redirect) {
          window.location.href = r.redirect;
        }
      } catch(err) {
        console.warn('Favorite error:', err);
      }
      this.classList.remove('loading');
    });
  });
}

// ---- Category pills filter ----
function initCatPills() {
  document.querySelectorAll('.cat-pill[data-cat]').forEach(pill => {
    pill.addEventListener('click', function() {
      document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
      this.classList.add('active');
      const url = new URL(window.location);
      url.searchParams.set('cat', this.dataset.cat);
      url.searchParams.delete('page');
      window.location.href = url.toString();
    });
  });
}

// ---- Video lazy play on hover ----
function initVideoCards() {
  document.querySelectorAll('.item-card[data-video]').forEach(card => {
    if (!card.dataset.video) return;
    const thumb = card.querySelector('.item-thumb');
    if (!thumb) return;
    let vid = null;
    card.addEventListener('mouseenter', () => {
      if (vid) return;
      vid = document.createElement('video');
      vid.src = card.dataset.video;
      vid.muted = true;
      vid.loop = true;
      vid.playsInline = true;
      vid.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;';
      thumb.appendChild(vid);
      vid.play().catch(() => {});
    });
    card.addEventListener('mouseleave', () => {
      if (vid) { vid.pause(); vid.remove(); vid = null; }
    });
  });
}

// ---- Flash messages auto-dismiss ----
function initAlerts() {
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    setTimeout(() => { el.style.transition = 'opacity .5s'; el.style.opacity = '0'; }, 4000);
    setTimeout(() => el.remove(), 4600);
  });
  // Toast-uri (mesaje flash): dispar după 5s; erorile rămân până la închidere
  document.querySelectorAll('.toast[data-auto-dismiss]:not(.toast-error)').forEach(el => {
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 5000);
  });
}

// ---- Upload zone drag & drop ----
function initUploadZone() {
  document.querySelectorAll('.upload-zone').forEach(zone => {
    const input = zone.querySelector('input[type=file]');
    zone.addEventListener('click', () => input?.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag');
      if (input && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        input.dispatchEvent(new Event('change'));
      }
    });
  });
}

// ---- Video preview before upload ----
function initVideoPreview() {
  const input = document.getElementById('video-input');
  const preview = document.getElementById('video-preview');
  if (!input || !preview) return;
  input.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    preview.src = url;
    preview.style.display = 'block';
    preview.parentElement.style.display = 'block';
  });
}

// ---- Chat (simple polling) ----
const Chat = {
  convId: null,
  lastId: 0,
  poll: null,

  init(convId) {
    this.convId = convId;
    this.scrollBottom();
    this.startPolling();
    this.initSend();
  },

  startPolling() {
    this.poll = setInterval(() => this.fetchNew(), 4000);
  },

  async fetchNew() {
    if (!this.convId) return;
    const r = await ajax(`/api/chat.php?conv_id=${this.convId}&last_id=${this.lastId}`, {}, 'GET');
    if (r.messages && r.messages.length) {
      r.messages.forEach(m => this.appendMessage(m));
      this.scrollBottom();
    }
  },

  appendMessage(m) {
    const wrap = document.getElementById('chat-messages');
    if (!wrap) return;
    if (document.getElementById('msg-' + m.id)) return;
    const isMine = m.sender_id == window.VEEDO_USER_ID;
    const div = document.createElement('div');
    div.id = 'msg-' + m.id;
    div.className = 'msg ' + (isMine ? 'mine' : 'theirs');
    div.innerHTML = `<div class="msg-bubble">${escHtml(m.body)}</div><div class="msg-time">${escHtml(m.time_ago)}</div>`;
    wrap.appendChild(div);
    if (m.id > this.lastId) this.lastId = m.id;
  },

  scrollBottom() {
    const wrap = document.getElementById('chat-messages');
    if (wrap) wrap.scrollTop = wrap.scrollHeight;
  },

  initSend() {
    const form = document.getElementById('chat-form');
    if (!form) return;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const inp = form.querySelector('input[name=message]');
      const msg = inp.value.trim();
      if (!msg) return;
      inp.value = '';
      const r = await ajax('/api/chat.php', { conv_id: this.convId, message: msg });
      if (r.message) this.appendMessage(r.message);
      this.scrollBottom();
    });
  },

  escHtml(s) { return escHtml(s); }
};

// ---- Admin: confirm delete ----
function initConfirmDelete() {
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm || 'Esti sigur?')) e.preventDefault();
    });
  });
}

// ---- Admin: settings live color preview ----
function initColorPickers() {
  document.querySelectorAll('input[type=color][data-css-var]').forEach(inp => {
    inp.addEventListener('input', function() {
      document.documentElement.style.setProperty(this.dataset.cssVar, this.value);
    });
  });
}

// ---- Search autocomplete ----
function initSearchAuto() {
  const inputs = document.querySelectorAll('.search-auto');
  inputs.forEach(inp => {
    let timer;
    const dropdown = inp.nextElementSibling;
    inp.addEventListener('input', () => {
      clearTimeout(timer);
      const q = inp.value.trim();
      if (q.length < 2) { if(dropdown) dropdown.style.display='none'; return; }
      timer = setTimeout(async () => {
        const r = await ajax(`/api/search.php?q=${encodeURIComponent(q)}`, {}, 'GET');
        if (dropdown && r.results) {
          dropdown.innerHTML = r.results.map(item =>
            `<a href="/anunt/${encodeURIComponent(item.slug)}" class="search-suggestion"><span>${escHtml(item.title)}</span> <b>${escHtml(item.price)}</b></a>`
          ).join('');
          dropdown.style.display = r.results.length ? 'block' : 'none';
        }
      }, 300);
    });
    document.addEventListener('click', e => {
      if (!inp.contains(e.target) && dropdown) dropdown.style.display = 'none';
    });
  });
}

// ---- Init all ----
document.addEventListener('DOMContentLoaded', () => {
  VeedoTheme.init();
  initLikes();
  initFavorites();
  initCatPills();
  initVideoCards();
  initAlerts();
  initUploadZone();
  initVideoPreview();
  initConfirmDelete();
  initColorPickers();
  initSearchAuto();
});
