<?php
require_once __DIR__ . '/includes/bootstrap.php';

$offset = (int)($_GET['offset'] ?? 0);
$limit  = 10;

$ads = DB::fetchAll("
    SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business, u.id as uid,
           ap.path AS main_photo
    FROM ads a
    JOIN categories c ON c.id=a.category_id
    JOIN users u ON u.id=a.user_id
    LEFT JOIN ad_photos ap ON ap.ad_id = a.id AND ap.is_primary = 1
    WHERE a.status='active'
      AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
      AND (a.video_path IS NOT NULL OR ap.id IS NOT NULL)
    ORDER BY a.is_boosted DESC, a.is_featured DESC, a.created_at DESC
    LIMIT $limit OFFSET $offset
", []);

// AJAX infinite scroll
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $items = [];
    foreach ($ads as $a) {
        $has_video = !empty($a['video_path']);
        $items[] = [
            'id'          => $a['id'],
            'slug'        => $a['slug'],
            'title'       => $a['title'],
            'price'       => (float)($a['price'] ?? 0),
            'currency'    => $a['currency'] ?? 'RON',
            'city'        => $a['city'] ?? '',
            'cat_name'    => $a['cat_name'],
            'cat_icon'    => $a['cat_icon'],
            'cat_color'   => $a['cat_color'],
            'user_name'   => $a['user_name'],
            'user_avatar' => avatar_url($a['user_avatar'], $a['user_name']),
            'is_verified' => (bool)$a['is_verified_business'],
            'likes_count' => (int)$a['likes_count'],
            'views_count' => (int)$a['views_count'],
            'has_video'   => $has_video,
            'video_url'   => $has_video ? BASE_URL.'/uploads/videos/'.$a['video_path'] : null,
            'thumb_url'   => $has_video ? thumb_url($a['video_thumb'] ?? '') : ($a['main_photo'] ? photo_url($a['main_photo']) : null),
            'photo_url'   => $a['main_photo'] ? photo_url($a['main_photo']) : null,
            'url'         => BASE_URL.'/anunt/'.$a['slug'],
        ];
    }
    echo json_encode(['items' => $items, 'has_more' => count($ads) === $limit]);
    exit;
}

$u = current_user();
$seo_title = 'Explorează — Veedo';
include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Reset pentru pagina explore ─────────────────── */
body { overflow: hidden; }

/* ── Layout principal ────────────────────────────── */
.exp-page {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    background: #000;
    height: calc(100vh - 65px); /* sub header */
    overflow: hidden;
}

/* ── Feed (coloana centru) ───────────────────────── */
.exp-feed-col {
    width: 100%;
    max-width: 420px;
    height: 100%;
    position: relative;
    flex-shrink: 0;
}

.exp-feed {
    width: 100%;
    height: 100%;
    overflow-y: scroll;
    scroll-snap-type: y mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.exp-feed::-webkit-scrollbar { display: none; }

/* ── Fiecare item ────────────────────────────────── */
.exp-item {
    position: relative;
    width: 100%;
    height: 100%;
    scroll-snap-align: start;
    scroll-snap-stop: always;
    background: #111;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}

.exp-media {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.exp-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.2) 45%, transparent 70%);
    pointer-events: none;
}

/* ── Info jos stanga ────────────────────────────── */
.exp-info {
    position: absolute;
    bottom: 16px;
    left: 14px;
    right: 60px;
    color: #fff;
    z-index: 2;
    cursor: pointer;
}
.exp-cat-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,0.15);
    backdrop-filter: blur(6px);
    border-radius: 100px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 7px;
    border: 1px solid rgba(255,255,255,0.2);
}
.exp-title {
    font-size: 14px;
    font-weight: 700;
    line-height: 1.35;
    margin-bottom: 5px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-shadow: 0 1px 3px rgba(0,0,0,0.5);
}
.exp-price {
    font-size: 16px;
    font-weight: 800;
    margin-bottom: 5px;
    text-shadow: 0 1px 3px rgba(0,0,0,0.5);
}
.exp-user {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    opacity: 0.9;
}
.exp-user img {
    width: 24px; height: 24px;
    border-radius: 50%;
    border: 1.5px solid rgba(255,255,255,0.5);
    object-fit: cover;
}

/* ── Actiuni dreapta ────────────────────────────── */
.exp-actions {
    position: absolute;
    right: 8px;
    bottom: 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    z-index: 2;
}
.exp-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    background: none;
    border: none;
    color: #fff;
    cursor: pointer;
    padding: 0;
    filter: drop-shadow(0 1px 3px rgba(0,0,0,0.6));
}
.exp-btn svg { width: 26px; height: 26px; }
.exp-btn span { font-size: 10px; font-weight: 600; }
.exp-btn.liked svg { fill: #E8380D; stroke: #E8380D; }

/* ── Video badge ───────────────────────────────── */
.exp-vid-badge {
    position: absolute;
    top: 10px; left: 10px;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    z-index: 2;
}

/* ── Sageti navigare desktop ───────────────────── */
.exp-nav-arrows {
    display: none;
    flex-direction: column;
    gap: 12px;
    margin-left: 16px;
    margin-top: auto;
    margin-bottom: auto;
    padding: 20px 0;
}
.exp-arrow {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    backdrop-filter: blur(6px);
    transition: background 0.15s;
}
.exp-arrow:hover { background: rgba(255,255,255,0.25); }

/* ── Desktop: video ca la TikTok ──────────────── */
@media (min-width: 769px) {
    .exp-page { gap: 0; }
    .exp-feed-col { max-width: 480px; border-radius: 0; }
    .exp-nav-arrows { display: flex; }
}

/* ── Mobile: video între header și meniul de jos ── */
@media (max-width: 768px) {
    .exp-page {
        height: calc(100vh - 145px);
        height: calc(100dvh - 145px);
    }
    .exp-feed-col { max-width: 100%; }
    .exp-info { bottom: 20px; right: 70px; }
    .exp-actions { bottom: 24px; right: 10px; }
}

/* ── Loader ───────────────────────────────────── */
.exp-loader-item {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: rgba(255,255,255,0.4);
    font-size: 14px;
    background: #000;
}
</style>

<div class="exp-page">

    <!-- Feed centru -->
    <div class="exp-feed-col">
        <div class="exp-feed" id="exp-feed">

            <?php foreach ($ads as $a):
                $has_video = !empty($a['video_path']);
                $media_src = $has_video
                    ? BASE_URL.'/uploads/videos/'.$a['video_path']
                    : ($a['main_photo'] ? photo_url($a['main_photo']) : '');
                $thumb = $has_video
                    ? thumb_url($a['video_thumb'] ?? '')
                    : ($a['main_photo'] ? photo_url($a['main_photo']) : '');
                $price = (float)($a['price'] ?? 0);
                if (!$media_src) continue;
            ?>
            <div class="exp-item" data-id="<?= $a['id'] ?>" data-idx="0">

                <?php if ($has_video): ?>
                <video class="exp-media" src="<?= h($media_src) ?>"
                       poster="<?= h($thumb) ?>" loop muted playsinline preload="none"></video>
                <div class="exp-vid-badge">▶ Video</div>
                <?php else: ?>
                <img class="exp-media" src="<?= h($media_src) ?>" alt="<?= h($a['title']) ?>" loading="lazy">
                <?php endif; ?>

                <div class="exp-overlay"></div>

                <div class="exp-info" onclick="location.href='<?= h(BASE_URL.'/anunt/'.$a['slug']) ?>'">
                    <div class="exp-cat-badge"><?= h($a['cat_icon']) ?> <?= h($a['cat_name']) ?></div>
                    <div class="exp-title"><?= h($a['title']) ?></div>
                    <?php if ($price > 0): ?>
                    <div class="exp-price"><?= h(format_price($price, $a['currency'] ?? 'RON')) ?></div>
                    <?php endif; ?>
                    <div class="exp-user">
                        <img src="<?= h(avatar_url($a['user_avatar'] ?? null, $a['user_name'] ?? '')) ?>" alt="">
                        <span><?= h(mb_substr($a['user_name'] ?? '', 0, 22)) ?><?= $a['is_verified_business'] ? ' ✓' : '' ?></span>
                        <?php if ($a['city']): ?><span style="opacity:.7">· <?= h($a['city']) ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="exp-actions">
                    <button class="exp-btn like-btn" data-ad-id="<?= $a['id'] ?>" onclick="event.stopPropagation()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                        <span class="like-count"><?= (int)$a['likes_count'] ?></span>
                    </button>
                    <button class="exp-btn" onclick="event.stopPropagation();location.href='<?= h(BASE_URL.'/anunt/'.$a['slug']) ?>'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <span><?= number_format((int)$a['views_count']) ?></span>
                    </button>
                    <button class="exp-btn" onclick="event.stopPropagation();shareAd('<?= h(BASE_URL.'/anunt/'.$a['slug']) ?>','<?= h(addslashes($a['title'])) ?>')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                        </svg>
                        <span>Share</span>
                    </button>
                    <button class="exp-btn" onclick="event.stopPropagation();location.href='<?= h(BASE_URL.'/anunt/'.$a['slug']) ?>'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 8 16 12 12 16"/>
                            <line x1="8" y1="12" x2="16" y2="12"/>
                        </svg>
                        <span>Vezi</span>
                    </button>
                </div>

            </div>
            <?php endforeach; ?>

            <div class="exp-item" id="exp-loader">
                <div class="exp-loader-item">Se încarcă...</div>
            </div>

        </div>
    </div>

    <!-- Sageti desktop (dreapta feed) -->
    <div class="exp-nav-arrows" id="exp-arrows">
        <button class="exp-arrow" onclick="expNav(-1)" title="Anterior">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
        </button>
        <button class="exp-arrow" onclick="expNav(1)" title="Următor">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
    </div>

</div>

<script>
(function() {

const feed    = document.getElementById('exp-feed');
let   loading = false;
let   noMore  = false;
let   offset  = <?= count($ads) ?>;
let   curIdx  = 0;

// ── Autoplay video cu IntersectionObserver ─────────────
const io = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        const el = entry.target;
        const video = el.querySelector('video');
        if (video) {
            if (entry.isIntersecting) { video.play().catch(()=>{}); }
            else { video.pause(); video.currentTime = 0; }
        }
        // Infinite scroll trigger
        if (el.id === 'exp-loader' && entry.isIntersecting) loadMore();
    });
}, { root: feed, threshold: 0.6 });

feed.querySelectorAll('.exp-item').forEach(el => io.observe(el));

// ── Navigare cu sageti desktop ────────────────────────
window.expNav = function(dir) {
    const items = feed.querySelectorAll('.exp-item[data-id]');
    curIdx = Math.max(0, Math.min(curIdx + dir, items.length - 1));
    items[curIdx].scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// Keyboard
document.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); expNav(1); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); expNav(-1); }
});

// Urmarire index curent la scroll
feed.addEventListener('scroll', () => {
    const items = feed.querySelectorAll('.exp-item[data-id]');
    const h = feed.clientHeight;
    items.forEach((el, i) => {
        const top = el.getBoundingClientRect().top - feed.getBoundingClientRect().top;
        if (top >= -h/2 && top < h/2) curIdx = i;
    });
});

// ── Infinite scroll ───────────────────────────────────
async function loadMore() {
    if (loading || noMore) return;
    loading = true;
    try {
        const r = await fetch(`/explore?ajax=1&offset=${offset}`, { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.items || !d.items.length) {
            noMore = true;
            document.querySelector('#exp-loader .exp-loader-item').textContent = 'Ai văzut tot! 🎉';
            return;
        }
        const trigger = document.getElementById('exp-loader');
        d.items.forEach(a => {
            const div = document.createElement('div');
            div.className = 'exp-item';
            div.dataset.id = a.id;
            const mediaHtml = a.has_video
                ? `<video class="exp-media" src="${a.video_url}" poster="${a.thumb_url||''}" loop muted playsinline preload="none"></video><div class="exp-vid-badge">▶ Video</div>`
                : `<img class="exp-media" src="${a.photo_url||''}" alt="${e(a.title)}" loading="lazy">`;
            const priceHtml = a.price > 0 ? `<div class="exp-price">${fmt(a.price, a.currency)}</div>` : '';
            const cityHtml  = a.city ? `<span style="opacity:.7">· ${e(a.city)}</span>` : '';
            div.innerHTML = `
                ${mediaHtml}
                <div class="exp-overlay"></div>
                <div class="exp-info" onclick="location.href='${a.url}'">
                    <div class="exp-cat-badge">${a.cat_icon} ${e(a.cat_name)}</div>
                    <div class="exp-title">${e(a.title)}</div>
                    ${priceHtml}
                    <div class="exp-user">
                        <img src="${a.user_avatar}" alt="">
                        <span>${e(a.user_name)}${a.is_verified?' ✓':''}</span>
                        ${cityHtml}
                    </div>
                </div>
                <div class="exp-actions">
                    <button class="exp-btn like-btn" data-ad-id="${a.id}" onclick="event.stopPropagation()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <span class="like-count">${a.likes_count}</span>
                    </button>
                    <button class="exp-btn" onclick="event.stopPropagation();location.href='${a.url}'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>${a.views_count}</span>
                    </button>
                    <button class="exp-btn" onclick="event.stopPropagation();shareAd('${a.url}','${e(a.title)}')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                        <span>Share</span>
                    </button>
                    <button class="exp-btn" onclick="event.stopPropagation();location.href='${a.url}'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 8 16 12 12 16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <span>Vezi</span>
                    </button>
                </div>`;
            feed.insertBefore(div, trigger);
            io.observe(div);
        });
        offset += d.items.length;
        if (!d.has_more) { noMore = true; document.querySelector('#exp-loader .exp-loader-item').textContent = 'Ai văzut tot! 🎉'; }
    } catch(err) { console.warn(err); }
    finally { loading = false; }
}

// ── Like ──────────────────────────────────────────────
if (typeof initLikes === 'function') initLikes();

// ── Share ─────────────────────────────────────────────
window.shareAd = (url, title) => {
    if (navigator.share) navigator.share({ title, url }).catch(()=>{});
    else navigator.clipboard.writeText(url).then(() => alert('Link copiat!'));
};

// ── Helpers ───────────────────────────────────────────
function e(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmt(p, cur) { return new Intl.NumberFormat('ro-RO').format(p) + ' ' + (cur||'RON'); }

// Ascunde footer pe explore
const ft = document.querySelector('.site-footer');
if (ft) ft.style.display = 'none';

// Incadreaza videoul exact intre header si meniul de jos (mobil) / sub header (desktop)
function fixHeight() {
    const page = document.querySelector('.exp-page');
    if (!page) return;
    const hdr = document.querySelector('.site-header');
    const hh = hdr ? hdr.getBoundingClientRect().height : 56;
    const isMobile = window.innerWidth <= 768;
    const navSpace = isMobile ? 84 : 0;            // bara de jos: 12 + 64 + 8 gol
    const h = Math.max(320, window.innerHeight - hh - navSpace);
    page.style.height = h + 'px';
    // Desktop: latimea coloanei = un video 9:16 care umple toata inaltimea
    const col = document.querySelector('.exp-feed-col');
    if (col) {
        if (!isMobile) {
            col.style.width = Math.min(h * 9 / 16, 560) + 'px';
            col.style.maxWidth = 'none';
        } else {
            col.style.width = '';
            col.style.maxWidth = '';
        }
    }
}
fixHeight();
window.addEventListener('resize', fixHeight);
window.addEventListener('orientationchange', function () { setTimeout(fixHeight, 250); });

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>