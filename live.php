<?php
// live.php — LIVE Shopping viewer + listing
require_once __DIR__ . '/includes/bootstrap.php';

if (!setting('live_enabled', '1')) {
    $seo_title = 'LIVE Shopping — În curând';
    include __DIR__ . '/includes/header.php';
    echo '<div style="text-align:center;padding:80px 20px;color:var(--text-3);">
        <div style="font-size:4rem;margin-bottom:16px;">📡</div>
        <h2>LIVE Shopping</h2>
        <p style="margin-top:10px;">Funcționalitatea LIVE va fi disponibilă în curând!</p>
        <a href="/explore" class="btn btn-primary" style="display:inline-flex;margin-top:20px;">🔥 Explorează anunțuri</a>
    </div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$u  = current_user();
$id = (int)($_GET['id'] ?? 0);

// ── Vizionare stream specific ─────────────────────────────
if ($id) {
    $stream = DB::fetch("
        SELECT ls.*, u.name as user_name, u.avatar as user_avatar,
               u.is_verified_business, u.id as uid
        FROM live_streams ls JOIN users u ON u.id=ls.user_id
        WHERE ls.id=?
    ", [$id]);

    if (!$stream) { redirect('/live'); }

    // Produse in stream
    $products = DB::fetchAll("
        SELECT a.id, a.title, a.slug, a.price, a.currency, a.city,
               c.name as cat_name, c.icon as cat_icon, c.color as cat_color,
               (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo
        FROM live_stream_products lsp
        JOIN ads a ON a.id=lsp.ad_id
        JOIN categories c ON c.id=a.category_id
        WHERE lsp.stream_id=? AND a.status='active'
        ORDER BY lsp.featured_at DESC
    ", [$id]);

    // Streams recomandate (altele live)
    $other_live = DB::fetchAll("
        SELECT ls.id, ls.title, ls.viewers_count, u.name as user_name, u.avatar as user_avatar
        FROM live_streams ls JOIN users u ON u.id=ls.user_id
        WHERE ls.status='live' AND ls.id!=?
        ORDER BY ls.viewers_count DESC LIMIT 5
    ", [$id]);

    $is_owner = $u && $u['id'] == $stream['uid'];
    $seo_title = h($stream['title']) . ' — LIVE Veedo';
    include __DIR__ . '/includes/header.php';
    ?>

<style>
/* ── Layout LIVE viewer ──────────────────────────── */
.live-wrap {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 0;
    height: calc(100vh - 65px);
    background: #0a0a0a;
    overflow: hidden;
}

/* ── Player (stanga) ─────────────────────────────── */
.live-player-col {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.live-player-area {
    flex: 1;
    position: relative;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.live-embed {
    width: 100%; height: 100%;
    border: none; display: block;
}

/* Placeholder cand nu e URL embed */
.live-placeholder {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: rgba(255,255,255,0.5);
    font-size: 14px; gap: 12px; text-align: center;
    padding: 40px;
}
.live-placeholder-icon { font-size: 4rem; }

/* Badge LIVE */
.live-badge-wrap {
    position: absolute; top: 14px; left: 14px;
    display: flex; align-items: center; gap: 8px; z-index: 2;
}
.live-badge {
    background: #EF4444; color: #fff;
    font-size: 11px; font-weight: 800;
    padding: 3px 9px; border-radius: 4px;
    display: flex; align-items: center; gap: 4px;
    animation: livePulse 2s infinite;
}
.live-badge::before {
    content: ''; width: 6px; height: 6px;
    background: #fff; border-radius: 50%;
}
@keyframes livePulse { 0%,100%{opacity:1} 50%{opacity:.7} }

.live-viewers {
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    color: #fff; font-size: 12px; font-weight: 600;
    padding: 3px 10px; border-radius: 4px;
}

/* Stream info bar */
.live-info-bar {
    background: #111; padding: 12px 16px;
    display: flex; align-items: center; gap: 12px;
    border-top: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
}
.live-host-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    object-fit: cover; border: 2px solid #EF4444;
    flex-shrink: 0;
}
.live-host-name { color: #fff; font-weight: 600; font-size: 14px; }
.live-stream-title { color: rgba(255,255,255,0.6); font-size: 12px; }

/* ── Sidebar (dreapta) ───────────────────────────── */
.live-sidebar {
    display: flex; flex-direction: column;
    background: var(--surface);
    border-left: 1px solid var(--border);
    overflow: hidden;
}

/* Tabs sidebar */
.live-tabs {
    display: flex; border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.live-tab {
    flex: 1; padding: 11px 6px;
    text-align: center; font-size: 12px; font-weight: 600;
    color: var(--text-3); cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.15s; background: none; border-top: none;
    border-left: none; border-right: none;
}
.live-tab.active { color: var(--brand); border-bottom-color: var(--brand); }

/* Panoul chat */
.live-chat-panel { display: flex; flex-direction: column; flex: 1; overflow: hidden; }
.live-products-panel { display: none; flex-direction: column; flex: 1; overflow: hidden; }

/* Chat messages */
.live-messages {
    flex: 1; overflow-y: auto; padding: 12px;
    display: flex; flex-direction: column; gap: 8px;
    scrollbar-width: thin;
}
.live-msg {
    display: flex; gap: 8px; align-items: flex-start;
}
.live-msg-avatar {
    width: 26px; height: 26px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
}
.live-msg-body { flex: 1; min-width: 0; }
.live-msg-name {
    font-size: 11px; font-weight: 700;
    color: var(--brand); margin-bottom: 1px;
}
.live-msg-name.owner { color: #EF4444; }
.live-msg-text {
    font-size: 13px; color: var(--text);
    line-height: 1.4; word-break: break-word;
}
.live-msg-time { font-size: 10px; color: var(--text-3); }
.live-msg.pinned {
    background: rgba(232,56,13,0.08);
    border-radius: 8px; padding: 8px;
    border: 1px solid rgba(232,56,13,0.2);
}

/* Chat input */
.live-chat-input-wrap {
    padding: 10px 12px;
    border-top: 1px solid var(--border);
    display: flex; gap: 8px; flex-shrink: 0;
}
.live-chat-input {
    flex: 1; padding: 8px 12px;
    border: 1.5px solid var(--border);
    border-radius: 20px; font-size: 13px;
    background: var(--bg-2); color: var(--text);
    outline: none; min-width: 0;
}
.live-chat-input:focus { border-color: var(--brand); }
.live-send-btn {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--brand); border: none;
    color: #fff; cursor: pointer; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: opacity 0.15s;
}
.live-send-btn:hover { opacity: 0.85; }

/* Produse in sidebar */
.live-products-list {
    flex: 1; overflow-y: auto; padding: 10px;
    display: flex; flex-direction: column; gap: 8px;
}
.live-product-card {
    display: flex; gap: 10px; align-items: center;
    background: var(--bg-2); border-radius: 10px;
    padding: 10px; text-decoration: none; color: var(--text);
    border: 1px solid var(--border); transition: border-color 0.15s;
}
.live-product-card:hover { border-color: var(--brand); }
.live-product-img {
    width: 52px; height: 52px; border-radius: 8px;
    object-fit: cover; flex-shrink: 0; background: var(--bg-3);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
}
.live-product-title {
    font-size: 12px; font-weight: 600; line-height: 1.3;
    display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow: hidden;
}
.live-product-price {
    font-size: 13px; font-weight: 800; color: var(--brand);
    margin-top: 2px;
}
.live-product-cta {
    margin-left: auto; flex-shrink: 0;
    background: var(--brand); color: #fff;
    font-size: 11px; font-weight: 700;
    padding: 5px 10px; border-radius: 6px;
    text-decoration: none;
}

/* Ended overlay */
.live-ended {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.85);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    color: #fff; text-align: center; gap: 12px;
    z-index: 5;
}

/* Mobile */
@media (max-width: 768px) {
    .live-wrap {
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr;
        height: calc(100vh - 65px - 60px); /* header + mobile bottom nav */
    }
    .live-player-area { min-height: 200px; max-height: 240px; }
    .live-sidebar { height: auto; flex: 1; min-height: 0; }
    .live-chat-input-wrap {
        padding-bottom: 12px; /* extra spatiu */
    }
    .live-info-bar {
        padding: 8px 12px; /* mai compact pe mobil */
    }
    .live-host-name { font-size: 13px; }
    .live-stream-title { font-size: 11px; }
}
</style>

<div class="live-wrap">

    <!-- Player stanga -->
    <div class="live-player-col">
        <div class="live-player-area" id="player-area">

            <?php if ($stream['status'] === 'ended'): ?>
            <div class="live-ended">
                <div style="font-size:3rem;">📺</div>
                <h3>Stream-ul s-a încheiat</h3>
                <p style="opacity:.7;font-size:14px;">Mulțumim că ai urmărit!</p>
                <a href="/live" class="btn btn-secondary" style="margin-top:8px;">← Alte streamuri</a>
            </div>
            <?php elseif ($stream['stream_url']): ?>
                <?php
                // Detectam tipul de embed
                $url = $stream['stream_url'];
                $embed_url = null;

                // YouTube
                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m)) {
                    $embed_url = 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1&mute=0';
                }
                // YouTube Live direct
                elseif (preg_match('/youtube\.com\/live\/([a-zA-Z0-9_-]+)/', $url, $m)) {
                    $embed_url = 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1';
                }
                // Twitch
                elseif (preg_match('/twitch\.tv\/([a-zA-Z0-9_]+)/', $url, $m)) {
                    $embed_url = 'https://player.twitch.tv/?channel=' . $m[1] . '&parent=' . parse_url(BASE_URL, PHP_URL_HOST) . '&autoplay=true';
                }
                ?>
                <?php if ($embed_url): ?>
                <iframe class="live-embed" src="<?= h($embed_url) ?>"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen></iframe>
                <?php else: ?>
                <div class="live-placeholder">
                    <div class="live-placeholder-icon">📡</div>
                    <div>
                        <a href="<?= h($url) ?>" target="_blank" class="btn btn-primary">▶ Deschide streamul extern</a>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
            <div class="live-placeholder">
                <div class="live-placeholder-icon">📡</div>
                <p>Stream-ul va începe în curând.<br>Stai pe fază!</p>
                <?php if ($stream['scheduled_at']): ?>
                <div style="margin-top:8px;font-size:16px;font-weight:700;color:#E8380D;" id="countdown">
                    ⏱ Se calculează...
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Badge LIVE / SCHEDULED -->
            <?php if ($stream['status'] === 'live'): ?>
            <div class="live-badge-wrap">
                <div class="live-badge">LIVE</div>
                <div class="live-viewers" id="viewers-badge">
                    👁 <span id="viewers-count"><?= $stream['viewers_count'] ?></span>
                </div>
            </div>
            <?php elseif ($stream['status'] === 'scheduled'): ?>
            <div class="live-badge-wrap">
                <div style="background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:4px;">
                    📅 PROGRAMAT
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Info bar -->
        <div class="live-info-bar">
            <img class="live-host-avatar"
                 src="<?= h(avatar_url($stream['user_avatar'], $stream['user_name'])) ?>"
                 alt="">
            <div style="flex:1;min-width:0;">
                <div class="live-host-name">
                    <?= h($stream['user_name']) ?>
                    <?= $stream['is_verified_business'] ? '<span style="color:#3B82F6;font-size:12px;"> ✓</span>' : '' ?>
                </div>
                <div class="live-stream-title"><?= h(mb_substr($stream['title'],0,60)) ?></div>
            </div>
            <?php if ($is_owner && $stream['status'] === 'live'): ?>
            <form method="POST" action="/live/start" style="display:inline;" onsubmit="return confirm('Oprești stream-ul?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="end">
                <input type="hidden" name="stream_id" value="<?= $stream['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">⏹ Oprește</button>
            </form>
            <?php elseif ($is_owner): ?>
            <a href="/live/start" class="btn btn-secondary btn-sm">⚙️ Gestionează</a>
            <?php endif; ?>
            <a href="/live" class="btn btn-secondary btn-sm" style="flex-shrink:0;">← Live-uri</a>
        </div>
    </div>

    <!-- Sidebar dreapta -->
    <div class="live-sidebar">

        <!-- Tabs -->
        <div class="live-tabs">
            <button class="live-tab active" onclick="switchTab('chat', this)">
                💬 Chat
            </button>
            <button class="live-tab" onclick="switchTab('products', this)">
                🛒 Produse <?= count($products) > 0 ? '('.count($products).')' : '' ?>
            </button>
            <?php if (!empty($other_live)): ?>
            <button class="live-tab" onclick="switchTab('others', this)">
                📺 Live-uri
            </button>
            <?php endif; ?>
        </div>

        <!-- Chat panel -->
        <div class="live-chat-panel" id="panel-chat">
            <div class="live-messages" id="chat-messages">
                <!-- Mesaj sistem -->
                <div style="text-align:center;padding:8px;font-size:12px;color:var(--text-3);">
                    Bun venit! Alătură-te conversației 👋
                </div>
            </div>

            <?php if ($stream['status'] !== 'ended'): ?>
            <div class="live-chat-input-wrap">
                <?php if ($u): ?>
                <input type="text" class="live-chat-input" id="chat-input"
                       placeholder="Scrie un mesaj..." maxlength="300"
                       onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMsg();}">
                <button class="live-send-btn" onclick="sendMsg()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                    </svg>
                </button>
                <?php else: ?>
                <a href="/login" class="btn btn-secondary" style="flex:1;justify-content:center;font-size:13px;">
                    Conectează-te pentru a comenta
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="padding:12px;text-align:center;font-size:13px;color:var(--text-3);">
                Streamul s-a încheiat
            </div>
            <?php endif; ?>
        </div>

        <!-- Produse panel -->
        <div class="live-products-panel" id="panel-products">
            <div class="live-products-list">
                <?php if (empty($products)): ?>
                <div style="text-align:center;padding:40px 20px;color:var(--text-3);font-size:13px;">
                    <div style="font-size:2rem;margin-bottom:8px;">🛒</div>
                    Niciun produs adăugat în stream.
                </div>
                <?php else: ?>
                <?php foreach ($products as $p): ?>
                <a href="/anunt/<?= h($p['slug']) ?>" class="live-product-card" target="_blank">
                    <?php if ($p['main_photo']): ?>
                    <img class="live-product-img" src="<?= h(photo_url($p['main_photo'])) ?>" alt="">
                    <?php else: ?>
                    <div class="live-product-img"><?= h($p['cat_icon']) ?></div>
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div class="live-product-title"><?= h($p['title']) ?></div>
                        <div class="live-product-price">
                            <?= (float)$p['price'] > 0 ? h(format_price((float)$p['price'], $p['currency'])) : 'Negociabil' ?>
                        </div>
                    </div>
                    <span class="live-product-cta">Vezi →</span>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alte live-uri panel -->
        <?php if (!empty($other_live)): ?>
        <div style="display:none;flex-direction:column;flex:1;overflow:hidden;" id="panel-others">
            <div style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($other_live as $ol): ?>
                <a href="/live/<?= $ol['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg-2);border-radius:10px;text-decoration:none;border:1px solid var(--border);">
                    <img src="<?= h(avatar_url($ol['user_avatar'], $ol['user_name'])) ?>"
                         style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #EF4444;" alt="">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr($ol['title'],0,30)) ?></div>
                        <div style="font-size:11px;color:var(--text-3);">👁 <?= $ol['viewers_count'] ?> urmăritori</div>
                    </div>
                    <div class="live-badge" style="font-size:10px;padding:2px 6px;">LIVE</div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
const STREAM_ID = <?= $id ?>;
const STREAM_STATUS = '<?= $stream['status'] ?>';
const IS_OWNER = <?= $is_owner ? 'true' : 'false' ?>;
const OWNER_ID = <?= $stream['uid'] ?>;
<?php if ($u): ?>
const CURRENT_USER_ID = <?= $u['id'] ?>;
<?php else: ?>
const CURRENT_USER_ID = null;
<?php endif; ?>

let lastMsgId = 0;
let pollInterval = null;

// ── Tab switching ─────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.live-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    ['chat','products','others'].forEach(p => {
        const el = document.getElementById('panel-' + p);
        if (el) el.style.display = p === name ? 'flex' : 'none';
    });
    if (name === 'chat') scrollChat();
}

// ── Chat polling ──────────────────────────────────
async function pollChat() {
    try {
        const r = await fetch(`/api/live-chat.php?stream_id=${STREAM_ID}&since=${lastMsgId}`, {
            credentials: 'same-origin'
        });
        const d = await r.json();

        // Update viewers
        if (d.viewers !== undefined) {
            const vc = document.getElementById('viewers-count');
            if (vc) vc.textContent = d.viewers;
        }

        // Stream ended
        if (d.status === 'ended' && STREAM_STATUS !== 'ended') {
            document.getElementById('player-area').innerHTML = `
                <div class="live-ended">
                    <div style="font-size:3rem;">📺</div>
                    <h3>Stream-ul s-a încheiat</h3>
                    <a href="/live" class="btn btn-secondary" style="margin-top:8px;">← Alte streamuri</a>
                </div>`;
            clearInterval(pollInterval);
            return;
        }

        // Mesaje noi
        if (d.messages && d.messages.length) {
            d.messages.forEach(addMessage);
            lastMsgId = d.messages[d.messages.length - 1].id;
        }
    } catch(e) {}
}

function addMessage(m) {
    // Asiguram ca mesajele sunt in panoul chat vizibil
    const chatPanel = document.getElementById('panel-chat');
    if (chatPanel && chatPanel.style.display === 'none') chatPanel.style.display = 'flex';
    const list = document.getElementById('chat-messages');
    const isOwner = m.user_id == OWNER_ID;
    const div = document.createElement('div');
    div.className = 'live-msg' + (m.is_pinned ? ' pinned' : '');
    div.id = 'msg-' + m.id;
    div.innerHTML = `
        <img class="live-msg-avatar" src="${m.avatar_url}" alt="">
        <div class="live-msg-body">
            <div class="live-msg-name${isOwner ? ' owner' : ''}">
                ${esc(m.user_name)}${isOwner ? ' 🎙' : ''}
                <span class="live-msg-time">${m.time}</span>
            </div>
            <div class="live-msg-text">${esc(m.message)}</div>
        </div>
        ${IS_OWNER && !m.is_pinned ? `<button onclick="pinMsg(${m.id})" style="background:none;border:none;color:var(--text-3);cursor:pointer;font-size:12px;padding:2px 4px;" title="Pinuiește">📌</button>` : ''}
        ${IS_OWNER ? `<button onclick="deleteMsg(${m.id})" style="background:none;border:none;color:var(--text-3);cursor:pointer;font-size:12px;padding:2px 4px;" title="Șterge">✕</button>` : ''}
    `;
    list.appendChild(div);
    scrollChat();
}

function scrollChat() {
    const list = document.getElementById('chat-messages');
    if (list) list.scrollTop = list.scrollHeight;
}

// ── Send message ──────────────────────────────────
async function sendMsg() {
    const input = document.getElementById('chat-input');
    if (!input) return;
    const msg = input.value.trim();
    if (!msg) return;
    input.value = '';
    input.disabled = true;

    // Asiguram ca panel-ul chat e vizibil
    const chatPanel = document.getElementById('panel-chat');
    if (chatPanel) chatPanel.style.display = 'flex';

    try {
        const r = await fetch(`/api/live-chat.php?stream_id=${STREAM_ID}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: msg })
        });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { console.error('Chat response:', text); input.disabled = false; return; }
        if (d.ok && d.message) {
            addMessage(d.message);
            lastMsgId = Math.max(lastMsgId, d.message.id);
        } else if (d.error) {
            // Afisam eroarea inline, nu alert
            const list = document.getElementById('chat-messages');
            const err = document.createElement('div');
            err.style.cssText = 'text-align:center;color:#EF4444;font-size:12px;padding:4px;';
            err.textContent = d.error;
            list.appendChild(err);
            setTimeout(() => err.remove(), 3000);
            input.value = msg; // restauram textul
        }
    } catch(e) {}
    finally { input.disabled = false; input.focus(); }
}

// ── Pin / Delete ──────────────────────────────────
async function pinMsg(id) {
    await fetch(`/api/live-chat.php?stream_id=${STREAM_ID}`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pin_id: id })
    });
    // Refetch
    lastMsgId = 0;
    document.getElementById('chat-messages').innerHTML = '';
    pollChat();
}

async function deleteMsg(id) {
    if (!confirm('Ștergi mesajul?')) return;
    document.getElementById('msg-' + id)?.remove();
    await fetch(`/api/live-chat.php?stream_id=${STREAM_ID}`, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ delete_id: id })
    });
}

// ── Viewers heartbeat ─────────────────────────────
async function heartbeat() {
    try {
        await fetch('/api/live-viewers.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ stream_id: STREAM_ID })
        });
    } catch(e) {}
}

// ── Countdown pentru scheduled ────────────────────
<?php if ($stream['status'] === 'scheduled' && $stream['scheduled_at']): ?>
(function() {
    const target = new Date('<?= $stream['scheduled_at'] ?>').getTime();
    const el = document.getElementById('countdown');
    function tick() {
        const diff = target - Date.now();
        if (diff <= 0) { if(el) el.textContent = '🔴 Începe acum!'; return; }
        const h = Math.floor(diff/3600000);
        const m = Math.floor((diff%3600000)/60000);
        const s = Math.floor((diff%60000)/1000);
        if(el) el.textContent = `⏱ Începe în: ${h>0?h+'h ':''} ${m}m ${s}s`;
    }
    tick(); setInterval(tick, 1000);
})();
<?php endif; ?>

// ── Helper ────────────────────────────────────────
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Start polling ─────────────────────────────────
if (STREAM_STATUS !== 'ended') {
    pollChat(); // fetch initial
    pollInterval = setInterval(pollChat, 3000); // poll la 3 secunde
    setInterval(heartbeat, 30000); // heartbeat la 30 secunde
    heartbeat(); // primul heartbeat imediat
}

// Ascunde footer si mobile bottom nav pe pagina live
const ft = document.querySelector('.site-footer');
if (ft) ft.style.display = 'none';
const mbn = document.querySelector('.mobile-bottom-nav');
if (mbn) mbn.style.display = 'none';

// Corecteaza inaltimea
function fixH() {
    const h = document.querySelector('.site-header')?.getBoundingClientRect().height || 65;
    document.querySelector('.live-wrap').style.height = `calc(100vh - ${h}px)`;
}
fixH();
window.addEventListener('resize', fixH);
</script>

    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ── Listing: toate streamurile ────────────────────────────
$live_now = DB::fetchAll("
    SELECT ls.*, u.name as user_name, u.avatar as user_avatar, u.is_verified_business,
           (SELECT COUNT(*) FROM live_stream_products WHERE stream_id=ls.id) as products_count
    FROM live_streams ls JOIN users u ON u.id=ls.user_id
    WHERE ls.status='live'
    ORDER BY ls.viewers_count DESC LIMIT 12
", []);

$upcoming = DB::fetchAll("
    SELECT ls.*, u.name as user_name, u.avatar as user_avatar, u.is_verified_business
    FROM live_streams ls JOIN users u ON u.id=ls.user_id
    WHERE ls.status='scheduled' AND ls.scheduled_at > NOW()
    ORDER BY ls.scheduled_at ASC LIMIT 8
", []);

$recent_ended = DB::fetchAll("
    SELECT ls.*, u.name as user_name, u.avatar as user_avatar, u.is_verified_business
    FROM live_streams ls JOIN users u ON u.id=ls.user_id
    WHERE ls.status='ended'
    ORDER BY ls.ended_at DESC LIMIT 6
", []);

$my_live = $u ? DB::fetch("SELECT id FROM live_streams WHERE user_id=? AND status='live'", [$u['id']]) : null;

$seo_title = 'LIVE Shopping — Veedo';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:28px 20px;max-width:1200px;">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;">
        <div>
            <h1 style="font-size:1.4rem;font-weight:800;display:flex;align-items:center;gap:8px;">
                🔴 LIVE Shopping
                <?php if (!empty($live_now)): ?>
                <span style="background:#EF4444;color:#fff;font-size:11px;padding:2px 8px;border-radius:4px;animation:livePulse 2s infinite;"><?= count($live_now) ?> LIVE</span>
                <?php endif; ?>
            </h1>
            <p style="color:var(--text-3);font-size:14px;margin-top:4px;">Urmărește și cumpără în timp real</p>
        </div>
        <?php if ($u && ($u['is_verified_business'] || $u['role'] === 'admin' || $u['role'] === 'business')): ?>
        <div style="display:flex;gap:8px;">
            <?php if ($my_live): ?>
            <a href="/live/<?= $my_live['id'] ?>" class="btn btn-danger">🔴 Streamul meu</a>
            <?php endif; ?>
            <a href="/live/start" class="btn btn-primary">+ Pornește LIVE</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($live_now) && empty($upcoming)): ?>
    <!-- Niciun stream -->
    <div style="text-align:center;padding:80px 20px;color:var(--text-3);">
        <div style="font-size:4rem;margin-bottom:16px;">📡</div>
        <h2 style="font-size:1.2rem;margin-bottom:8px;">Niciun stream activ momentan</h2>
        <p style="font-size:14px;">Urmăritorii tăi vor fi notificați când pornești un LIVE.</p>
        <?php if ($u && ($u['is_verified_business'] || $u['role'] === 'admin')): ?>
        <a href="/live/start" class="btn btn-primary" style="margin-top:20px;display:inline-flex;">🔴 Pornește primul LIVE</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Live acum -->
    <?php if (!empty($live_now)): ?>
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;">🔴 Live acum</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:32px;">
        <?php foreach ($live_now as $ls): ?>
        <a href="/live/<?= $ls['id'] ?>" style="text-decoration:none;display:block;background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:transform 0.2s,box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.1)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
            <!-- Thumbnail -->
            <div style="position:relative;aspect-ratio:16/9;background:#111;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                <?php if ($ls['thumbnail']): ?>
                <img src="<?= h($ls['thumbnail']) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
                <?php else: ?>
                <div style="font-size:3rem;opacity:.3;">📺</div>
                <?php endif; ?>
                <div style="position:absolute;top:10px;left:10px;background:#EF4444;color:#fff;font-size:11px;font-weight:800;padding:3px 9px;border-radius:4px;display:flex;align-items:center;gap:4px;">
                    <span style="width:6px;height:6px;background:#fff;border-radius:50%;display:inline-block;animation:livePulse 2s infinite;"></span> LIVE
                </div>
                <div style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.6);color:#fff;font-size:11px;padding:3px 8px;border-radius:4px;">
                    👁 <?= $ls['viewers_count'] ?>
                </div>
                <?php if ($ls['products_count'] > 0): ?>
                <div style="position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,0.7);color:#fff;font-size:11px;padding:3px 8px;border-radius:4px;">
                    🛒 <?= $ls['products_count'] ?> produse
                </div>
                <?php endif; ?>
            </div>
            <!-- Info -->
            <div style="padding:12px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                    <img src="<?= h(avatar_url($ls['user_avatar'], $ls['user_name'])) ?>"
                         style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid #EF4444;" alt="">
                    <div>
                        <div style="font-size:12px;font-weight:700;color:var(--text);">
                            <?= h($ls['user_name']) ?>
                            <?= $ls['is_verified_business'] ? '<span style="color:#3B82F6;font-size:11px;"> ✓</span>' : '' ?>
                        </div>
                    </div>
                </div>
                <div style="font-size:13px;font-weight:600;color:var(--text);line-height:1.3;">
                    <?= h(mb_substr($ls['title'],0,55)) ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Programate -->
    <?php if (!empty($upcoming)): ?>
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;">📅 Programate</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:32px;">
        <?php foreach ($upcoming as $ls): ?>
        <a href="/live/<?= $ls['id'] ?>" style="text-decoration:none;display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;">
            <img src="<?= h(avatar_url($ls['user_avatar'], $ls['user_name'])) ?>"
                 style="width:44px;height:44px;border-radius:50%;object-fit:cover;" alt="">
            <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr($ls['title'],0,40)) ?></div>
                <div style="font-size:11px;color:var(--text-3);"><?= h($ls['user_name']) ?></div>
                <div style="font-size:11px;color:var(--brand);font-weight:600;margin-top:2px;">
                    📅 <?= date('d M, H:i', strtotime($ls['scheduled_at'])) ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Recente -->
    <?php if (!empty($recent_ended)): ?>
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;color:var(--text-3);">🕐 Stream-uri recente</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;">
        <?php foreach ($recent_ended as $ls): ?>
        <a href="/live/<?= $ls['id'] ?>" style="text-decoration:none;display:flex;align-items:center;gap:10px;background:var(--bg-2);border:1px solid var(--border);border-radius:10px;padding:12px;opacity:.8;">
            <img src="<?= h(avatar_url($ls['user_avatar'], $ls['user_name'])) ?>"
                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;" alt="">
            <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr($ls['title'],0,35)) ?></div>
                <div style="font-size:11px;color:var(--text-3);"><?= time_ago($ls['ended_at'] ?? $ls['created_at']) ?></div>
            </div>
            <span style="font-size:10px;background:var(--bg-3);color:var(--text-3);padding:2px 6px;border-radius:4px;">Ended</span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<style>
@keyframes livePulse { 0%,100%{opacity:1} 50%{opacity:.6} }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>