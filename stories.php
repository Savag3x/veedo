<?php
// stories.php — Stories 24h (vizualizare & postare)
require_once __DIR__ . '/includes/bootstrap.php';

$u = current_user();

// Sterge stories expirate
DB::query("UPDATE stories SET is_active=0 WHERE expires_at < NOW() AND is_active=1");

// Fetch stories active grupate pe utilizator (urmariti + proprii)
// Dacă user e logat: stories de la urmăriți + proprii
// Dacă nu e logat: toate stories publice recente
if ($u) {
    $following_ids = array_column(
        DB::fetchAll("SELECT following_id FROM follows WHERE follower_id=?", [$u['id']]),
        'following_id'
    );
    $following_ids[] = $u['id']; // include proprii
    if (empty($following_ids)) $following_ids[] = 0;

    $placeholders = implode(',', array_fill(0, count($following_ids), '?'));
    $story_users = DB::fetchAll("
        SELECT u.id, u.name, u.avatar, u.is_verified_business,
               COUNT(s.id) as story_count,
               MAX(s.created_at) as latest_story,
               MIN(s.id) as first_story_id
        FROM stories s
        JOIN users u ON u.id = s.user_id
        WHERE s.is_active = 1 AND s.expires_at > NOW()
          AND s.user_id IN ($placeholders)
        GROUP BY u.id
        ORDER BY latest_story DESC
        LIMIT 30
    ", $following_ids);
} else {
    // Guest: arată toate stories active (descoperire)
    $story_users = DB::fetchAll("
        SELECT u.id, u.name, u.avatar, u.is_verified_business,
               COUNT(s.id) as story_count,
               MAX(s.created_at) as latest_story,
               MIN(s.id) as first_story_id
        FROM stories s
        JOIN users u ON u.id = s.user_id
        WHERE s.is_active = 1 AND s.expires_at > NOW()
        GROUP BY u.id
        ORDER BY latest_story DESC
        LIMIT 30
    ");
}

$seo_title = 'Stories';
include __DIR__ . '/includes/header.php';
?>

<style>
.stories-bar {
    display: flex;
    gap: 14px;
    padding: 16px 20px;
    overflow-x: auto;
    scrollbar-width: none;
    background: var(--surface);
    border-bottom: 1px solid var(--border-2);
    -webkit-overflow-scrolling: touch;
}
.stories-bar::-webkit-scrollbar { display: none; }

.story-thumb {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    flex-shrink: 0;
    width: 64px;
}
.story-ring {
    width: 62px; height: 62px;
    border-radius: 50%;
    padding: 2px;
    background: linear-gradient(135deg, #E8380D, #FF6B35, #F59E0B);
    flex-shrink: 0;
}
.story-ring.seen { background: var(--border-2); }
.story-ring img {
    width: 100%; height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--surface);
    display: block;
}
.story-name {
    font-size: 11px;
    color: var(--text-2);
    text-align: center;
    max-width: 62px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Story viewer overlay */
.story-viewer {
    position: fixed;
    inset: 0;
    background: #000;
    z-index: 10000;
    visibility: hidden;
    opacity: 0;
    pointer-events: none;
    display: flex;
    flex-direction: column;
    transition: opacity 0.2s;
}
.story-viewer.open {
    visibility: visible;
    opacity: 1;
    pointer-events: auto;
}
.story-media {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    max-height: calc(100vh - 80px);
}
.story-media img, .story-media video {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.story-progress-bar {
    display: flex;
    gap: 4px;
    padding: 12px 16px 8px;
}
.story-progress-seg {
    flex: 1;
    height: 3px;
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
    overflow: hidden;
}
.story-progress-fill {
    height: 100%;
    background: #fff;
    border-radius: 2px;
    transition: width linear;
}
.story-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    position: absolute;
    top: 48px;
    left: 0; right: 0;
    z-index: 2;
}
.story-caption {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    padding: 40px 16px 16px;
    background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
    color: #fff;
    font-size: 15px;
}
.story-nav {
    position: absolute;
    top: 0; bottom: 0;
    width: 40%;
    z-index: 3;
    cursor: pointer;
}
.story-nav.left { left: 0; }
.story-nav.right { right: 0; }
</style>

<div class="container" style="padding:0;max-width:640px;">

  <!-- Stories bar -->
  <div class="stories-bar">
    <?php if ($u): ?>
    <!-- Add story button -->
    <div class="story-thumb" onclick="openAddStory()">
      <div style="width:62px;height:62px;border-radius:50%;background:var(--bg-3);border:2px dashed var(--brand);display:flex;align-items:center;justify-content:center;font-size:1.5rem;">+</div>
      <span class="story-name">Story nou</span>
    </div>
    <?php endif; ?>

    <?php foreach ($story_users as $i => $su): ?>
    <div class="story-thumb" onclick="openStoryViewer(<?= $su['id'] ?>)">
      <div class="story-ring" id="ring-<?= $su['id'] ?>">
        <img src="<?= h(avatar_url($su['avatar'], $su['name'])) ?>" alt="">
      </div>
      <span class="story-name"><?= h(explode(' ', $su['name'])[0]) ?></span>
    </div>
    <?php endforeach; ?>

    <?php if (empty($story_users)): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:8px;color:var(--text-3);font-size:13px;">
      <span>📖</span> Nicio story disponibilă. <?= $u ? '' : '<a href="/login">Conectează-te</a> pentru a vedea stories de la persoanele urmărite.' ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Instructiuni -->
  <div style="padding:20px;">
    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;text-align:center;">
      <div style="font-size:2.5rem;margin-bottom:10px;">📸</div>
      <h2 style="font-size:1.1rem;margin-bottom:8px;">Stories 24h</h2>
      <p style="font-size:14px;color:var(--text-3);margin-bottom:16px;">Postează oferte flash, produse noi sau promoții. Dispar automat după 24 de ore.</p>
      <?php if ($u): ?>
        <button onclick="openAddStory()" class="btn btn-primary">+ Adaugă story</button>
      <?php else: ?>
        <a href="/login" class="btn btn-primary">Conectează-te pentru a posta</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Story Viewer -->
<div class="story-viewer" id="story-viewer">
  <div class="story-progress-bar" id="story-progress"></div>
  <div class="story-media" id="story-media">
    <div class="story-header" id="story-header"></div>
    <div class="story-nav left" onclick="storyPrev()"></div>
    <div class="story-nav right" onclick="storyNext()"></div>
    <div class="story-caption" id="story-caption"></div>
  </div>
  <div style="display:flex;justify-content:flex-end;padding:12px 16px;">
    <button onclick="closeViewer()" style="background:rgba(255,255,255,0.2);color:#fff;border:none;border-radius:20px;padding:8px 20px;cursor:pointer;font-size:14px;">✕ Închide</button>
  </div>
</div>

<!-- Add Story Modal -->
<?php if ($u): ?>
<div id="add-story-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:10001;align-items:center;justify-content:center;">
  <div style="background:var(--surface);border-radius:var(--radius-lg);padding:28px;max-width:420px;width:90%;max-height:90vh;overflow-y:auto;">
    <h2 style="font-size:1.1rem;margin-bottom:20px;">📸 Adaugă Story</h2>
    <form method="POST" action="/api/stories.php" enctype="multipart/form-data" id="story-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label">Media * (foto sau video, max 30MB)</label>
        <div class="upload-zone" style="padding:20px;" onclick="document.getElementById('story-media-input').click()">
          <input type="file" name="media" id="story-media-input" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" style="display:none;" onchange="previewStoryMedia(this)" required>
          <div id="story-media-preview" style="font-size:2rem;">📷</div>
          <p style="font-size:13px;margin-top:6px;">Apasă pentru a selecta</p>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Caption (opțional)</label>
        <textarea name="caption" class="form-control" rows="2" placeholder="Ofertă limitată! Produs nou disponibil..." maxlength="500"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Link (opțional)</label>
        <input type="url" name="link_url" class="form-control" placeholder="https://... sau /anunt/slug">
      </div>
      <div class="form-group">
        <label class="form-label">Text buton link</label>
        <input type="text" name="link_text" class="form-control" placeholder="Vezi anunțul →" maxlength="50">
      </div>
      <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary">📤 Publică story</button>
        <button type="button" onclick="closeAddStory()" class="btn btn-secondary">Anulează</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
const storyUsersData = <?= json_encode(array_map(fn($su) => ['id'=>$su['id'],'name'=>$su['name'],'avatar'=>avatar_url($su['avatar'],$su['name']),'story_count'=>$su['story_count']], $story_users), JSON_UNESCAPED_UNICODE) ?>;

let currentUserIdx = 0;
let currentStoryIdx = 0;
let currentStories = [];
let storyTimer = null;

async function openStoryViewer(userId) {
    const viewer = document.getElementById('story-viewer');
    viewer.classList.add('open');
    document.body.style.overflow = 'hidden';
    currentUserIdx = storyUsersData.findIndex(u => u.id === userId);
    await loadUserStories(userId);
}

async function loadUserStories(userId) {
    const r = await fetch('/api/stories.php?user_id='+userId);
    const d = await r.json();
    currentStories = d.stories || [];
    currentStoryIdx = 0;
    renderStory();
}

function renderStory() {
    if (!currentStories.length) { closeViewer(); return; }
    const s = currentStories[currentStoryIdx];
    if (!s) { closeViewer(); return; }

    // Progress bars
    const pb = document.getElementById('story-progress');
    pb.innerHTML = currentStories.map((_, i) =>
        `<div class="story-progress-seg"><div class="story-progress-fill" id="prog-${i}" style="width:${i < currentStoryIdx ? '100%' : '0%'}"></div></div>`
    ).join('');

    // Media
    const sm = document.getElementById('story-media');
    // Remove old media
    sm.querySelectorAll('img,video').forEach(el => el.remove());

    const user = storyUsersData[currentUserIdx];
    document.getElementById('story-header').innerHTML = `
        <img src="${user.avatar}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #fff;" alt="">
        <div>
          <div style="color:#fff;font-weight:700;font-size:14px;">${user.name}</div>
          <div style="color:rgba(255,255,255,0.7);font-size:12px;">${s.time_ago}</div>
        </div>`;

    if (s.media_type === 'video') {
        const vid = document.createElement('video');
        vid.src = s.media_url;
        vid.autoplay = true;
        vid.muted = false;
        vid.playsInline = true;
        vid.style.cssText = 'max-width:100%;max-height:calc(100vh - 200px);';
        sm.insertBefore(vid, sm.querySelector('.story-nav'));
        vid.onloadedmetadata = () => startProgress(Math.min(vid.duration, 30) * 1000);
    } else {
        const img = document.createElement('img');
        img.src = s.media_url;
        img.style.cssText = 'max-width:100%;max-height:calc(100vh - 200px);object-fit:contain;';
        sm.insertBefore(img, sm.querySelector('.story-nav'));
        startProgress(5000);
    }

    const cap = document.getElementById('story-caption');
    cap.innerHTML = `${s.caption ? `<div style="margin-bottom:10px;">${s.caption}</div>` : ''}
        ${s.link_url ? `<a href="${s.link_url}" style="display:inline-block;background:var(--brand);color:#fff;padding:8px 20px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;">${s.link_text || 'Vedere →'}</a>` : ''}`;

    // Mark as viewed
    fetch('/api/stories.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'view', story_id: s.id})});
}

function startProgress(durationMs) {
    clearTimeout(storyTimer);
    const fill = document.getElementById(`prog-${currentStoryIdx}`);
    if (!fill) return;
    const start = Date.now();
    const animate = () => {
        const elapsed = Date.now() - start;
        const pct = Math.min(100, (elapsed / durationMs) * 100);
        fill.style.width = pct + '%';
        if (pct < 100) { storyTimer = setTimeout(animate, 50); }
        else { storyNext(); }
    };
    storyTimer = setTimeout(animate, 50);
}

function storyNext() {
    clearTimeout(storyTimer);
    currentStoryIdx++;
    if (currentStoryIdx >= currentStories.length) {
        // Next user
        currentUserIdx++;
        if (currentUserIdx >= storyUsersData.length) { closeViewer(); return; }
        loadUserStories(storyUsersData[currentUserIdx].id);
    } else {
        renderStory();
    }
}

function storyPrev() {
    clearTimeout(storyTimer);
    currentStoryIdx = Math.max(0, currentStoryIdx - 1);
    renderStory();
}

function closeViewer() {
    clearTimeout(storyTimer);
    document.getElementById('story-viewer').classList.remove('open');
    document.body.style.overflow = '';
}

// Add story
function openAddStory() {
    const m = document.getElementById('add-story-modal');
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeAddStory() {
    const m = document.getElementById('add-story-modal');
    if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
}

function previewStoryMedia(input) {
    const file = input.files[0];
    if (!file) return;
    const prev = document.getElementById('story-media-preview');
    const reader = new FileReader();
    reader.onload = e => {
        if (file.type.startsWith('video/')) {
            prev.innerHTML = `<video src="${e.target.result}" style="max-width:100%;max-height:120px;" muted></video>`;
        } else {
            prev.innerHTML = `<img src="${e.target.result}" style="max-width:100%;max-height:120px;border-radius:8px;">`;
        }
    };
    reader.readAsDataURL(file);
}

document.getElementById('story-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true; btn.textContent = '📤 Se publică...';
    const fd = new FormData(this);
    // compresie imagine inainte de upload (videoclipurile raman neschimbate)
    const mediaInput = document.getElementById('story-media-input');
    const mf = mediaInput && mediaInput.files[0];
    if (mf && /^image\//.test(mf.type) && window.VeedoCompress) {
        try {
            const c = await window.VeedoCompress.compress(mf, {maxDim:1600, quality:0.85});
            fd.set('media', c, c.name || 'story.jpg');
        } catch(err) {}
    }
    const r = await fetch('/api/stories.php', {method:'POST', body: fd});
    const d = await r.json();
    if (d.ok) { closeAddStory(); window.location.reload(); }
    else { alert(d.error || 'Eroare'); btn.disabled = false; btn.textContent = '📤 Publică story'; }
});

// Keyboard navigation
document.addEventListener('keydown', e => {
    if (!document.getElementById('story-viewer').classList.contains('open')) return;
    if (e.key === 'ArrowRight') storyNext();
    if (e.key === 'ArrowLeft') storyPrev();
    if (e.key === 'Escape') closeViewer();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
