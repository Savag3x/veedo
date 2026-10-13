<?php
require_once __DIR__ . '/includes/bootstrap.php';

$profile_id = (int)($_GET['id'] ?? 0);
$tab = in_array($_GET['tab'] ?? '', ['anunturi','video','recenzii','despre']) ? $_GET['tab'] : 'anunturi';

$profile = DB::fetch("
    SELECT id,name,email,avatar,bio,city,county,role,
           is_verified_business,business_verified_until,
           rating_avg,rating_count,ads_count,created_at,last_login,
           followers_count,following_count,
           business_name,business_desc,business_banner,
           business_website,business_phone,business_category,
           business_schedule,business_address
    FROM users WHERE id=? AND is_active=1
", [$profile_id]);

if (!$profile) {
    http_response_code(404);
    include __DIR__ . '/includes/header.php';
    echo '<div class="container" style="text-align:center;padding:80px 20px;">
        <div style="font-size:4rem;">😕</div>
        <h2 style="margin:16px 0 8px;">Utilizatorul nu a fost găsit</h2>
        <a href="/" class="btn btn-primary">← Acasă</a>
    </div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$cu = current_user();
$is_following = $cu && $cu['id'] !== $profile_id &&
    (bool)DB::fetch("SELECT id FROM follows WHERE follower_id=? AND following_id=?", [$cu['id'], $profile_id]);

$ads_all   = DB::fetchAll("
    SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
           ap.path AS main_photo
    FROM ads a
    JOIN categories c ON c.id=a.category_id
    JOIN users u ON u.id=a.user_id
    LEFT JOIN ad_photos ap ON ap.ad_id = a.id AND ap.is_primary = 1
    WHERE a.user_id=? AND a.status='active'
      AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
    ORDER BY a.is_boosted DESC, a.created_at DESC
", [$profile_id]);
$ads_video = array_filter($ads_all, fn($a) => !empty($a['video_path']));
$ads       = $tab === 'video' ? $ads_video : $ads_all;

$reviews = DB::fetchAll("
    SELECT r.*, u.name AS reviewer_name, u.avatar AS reviewer_avatar
    FROM reviews r JOIN users u ON u.id=r.reviewer_id
    WHERE r.reviewed_id=? AND r.is_approved=1
    ORDER BY r.created_at DESC
", [$profile_id]);
$rating_breakdown = [5=>0,4=>0,3=>0,2=>0,1=>0];
foreach ($reviews as $r) $rating_breakdown[(int)$r['rating']]++;

$can_review = $cu && $cu['id'] !== $profile_id &&
    !DB::fetch("SELECT id FROM reviews WHERE reviewer_id=? AND reviewed_id=?", [$cu['id']??0, $profile_id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_review && csrf_verify()) {
    $rating  = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $comment = sanitize($_POST['comment'] ?? '');
    DB::insert('reviews', ['reviewer_id'=>$cu['id'],'reviewed_id'=>$profile_id,'rating'=>$rating,'comment'=>$comment,'is_approved'=>1]);
    $avg = DB::fetch("SELECT AVG(rating) as a, COUNT(*) as c FROM reviews WHERE reviewed_id=? AND is_approved=1", [$profile_id]);
    DB::update('users', ['rating_avg'=>round($avg['a'],2),'rating_count'=>$avg['c']], 'id=?', [$profile_id]);
    try { DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
        [$profile_id,'new_review',$cu['name'].' ți-a lăsat o recenzie ⭐',mb_substr($comment,0,100),'/utilizator/'.$profile_id.'?tab=recenzii']); } catch(Exception $e) {}
    flash('success','Recenzia a fost adăugată!');
    redirect('/utilizator/'.$profile_id.'?tab=recenzii');
}

$total_views  = (int)DB::fetch("SELECT COALESCE(SUM(views_count),0) as v FROM ads WHERE user_id=?", [$profile_id])['v'];
$total_likes  = (int)DB::fetch("SELECT COALESCE(SUM(likes_count),0) as v FROM ads WHERE user_id=?", [$profile_id])['v'];
$is_business  = $profile['is_verified_business'] && in_array($profile['role'], ['business','admin']);
$display_name = ($is_business && $profile['business_name']) ? $profile['business_name'] : $profile['name'];
$stars_avg    = (int)round($profile['rating_avg'] ?? 0);
$live_stream  = DB::fetch("SELECT id FROM live_streams WHERE user_id=? AND status='live'", [$profile_id]);

$seo_title = h($display_name) . ' — Profil Veedo';
include __DIR__ . '/includes/header.php';
?>
<style>
.prf-cover {
    height: 180px;
    background: linear-gradient(135deg,#E8380D 0%,#FF6B35 55%,#FFB347 100%);
    overflow: hidden; position: relative;
}
.prf-cover img { width:100%;height:100%;object-fit:cover;display:block; }

.prf-bar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}
.prf-bar-inner {
    max-width: 960px; margin: 0 auto;
    padding: 0 24px;
}

/* Avatar + butoane pe acelasi rand */
.prf-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    padding-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}
.prf-avatar {
    width: 96px; height: 96px;
    border-radius: 50%;
    border: 4px solid var(--surface);
    object-fit: cover;
    display: block;
    margin-top: -48px;
    background: var(--bg-2);
    flex-shrink: 0;
    position: relative;
}
.prf-avatar-wrap { position: relative; flex-shrink: 0; }
.prf-live-badge {
    position: absolute;
    bottom: 4px; right: 0;
    background: #EF4444; color: #fff;
    font-size: 9px; font-weight: 800;
    padding: 2px 6px; border-radius: 8px;
    border: 2px solid var(--surface);
    text-decoration: none;
    white-space: nowrap;
    animation: blink 2s infinite;
}
@keyframes blink { 0%,100%{opacity:1}50%{opacity:.65} }

.prf-btns { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding-bottom: 4px; }

/* Info block */
.prf-info-block { padding: 4px 0 16px; }
.prf-name {
    font-size: 1.35rem; font-weight: 800;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 5px;
}
.badge-biz { background:#EFF6FF;color:#1D4ED8;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px; }
.badge-adm { background:#FEF2F2;color:#B91C1C;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px; }

/* Stats */
.prf-stats {
    display: flex;
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    background: var(--bg-2);
    margin: 12px 0;
}
.prf-stat { flex:1; text-align:center; padding:10px 4px; border-right:1px solid var(--border); }
.prf-stat:last-child { border-right:none; }
.prf-stat b { display:block; font-size:1.1rem; font-weight:800; color:var(--text); }
.prf-stat small { display:block; font-size:10px; color:var(--text-3); margin-top:1px; }

/* Tabs */
.prf-tabs {
    display: flex; overflow-x:auto; scrollbar-width:none;
    border-top: 1px solid var(--border);
}
.prf-tabs::-webkit-scrollbar{display:none}
.prf-tab {
    padding: 11px 16px; font-size:13px; font-weight:600;
    color:var(--text-3); white-space:nowrap; text-decoration:none;
    border-bottom: 2px solid transparent; transition: all .15s;
}
.prf-tab.active { color:var(--brand); border-bottom-color:var(--brand); }
.prf-tab:hover  { color:var(--text); }

/* Content */
.prf-content { max-width:960px; margin:0 auto; padding:24px; }

/* Reviews */
.rating-row { display:flex;align-items:center;gap:8px;margin-bottom:5px; }
.rating-bar { flex:1;height:6px;background:var(--bg-3);border-radius:3px;overflow:hidden; }
.rating-fill { height:100%;background:#F59E0B;border-radius:3px; }
.review-card { background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:10px; }

@media(max-width:600px){
    .prf-cover{height:120px;}
    .prf-bar-inner{padding:0 14px;}
    .prf-avatar{width:76px;height:76px;margin-top:-38px;}
    .prf-name{font-size:1.1rem;}
    .prf-content{padding:14px;}
    .prf-tab{padding:10px 12px;font-size:12px;}
    .prf-stat b{font-size:.95rem;}
}
@media(max-width:480px){
    .prf-head{flex-direction:column;align-items:flex-start;}
    .prf-btns{padding-bottom:0;}
}
</style>

<!-- Cover -->
<div class="prf-cover">
    <?php if ($is_business && $profile['business_banner']): ?>
    <img src="<?= h($profile['business_banner']) ?>" alt="">
    <?php endif; ?>
</div>

<div class="prf-bar">
<div class="prf-bar-inner">

    <!-- Avatar + butoane -->
    <div class="prf-head">
        <div class="prf-avatar-wrap">
            <img class="prf-avatar"
                 src="<?= h(avatar_url($profile['avatar'], $profile['name'])) ?>"
                 alt="<?= h($display_name) ?>">
            <?php if ($live_stream): ?>
            <a href="/live/<?= $live_stream['id'] ?>" class="prf-live-badge">🔴 LIVE</a>
            <?php endif; ?>
        </div>

        <div class="prf-btns">
            <?php if ($cu && $cu['id'] !== $profile_id): ?>
                <button id="follow-btn" onclick="toggleFollow(<?= $profile_id ?>)"
                        class="btn <?= $is_following ? 'btn-secondary' : 'btn-primary' ?>">
                    <?= $is_following ? '✓ Urmărești' : '+ Urmărește' ?>
                </button>
                <form action="/api/start-chat.php" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="profile_id" value="<?= $profile_id ?>">
                    <button type="submit" class="btn btn-secondary">💬 Mesaj</button>
                </form>
                <?php if ($is_business && $profile['business_phone']): ?>
                <a href="tel:<?= h(preg_replace('/\s+/','',$profile['business_phone'])) ?>" class="btn btn-secondary">📞 Sună</a>
                <?php endif; ?>
                <?php if ($is_business && $profile['business_website']): ?>
                <a href="<?= h($profile['business_website']) ?>" target="_blank" rel="noopener" class="btn btn-secondary">🌐 Site</a>
                <?php endif; ?>
            <?php elseif ($cu && $cu['id'] === $profile_id): ?>
                <a href="/cont/setari" class="btn btn-secondary">⚙️ Editează</a>
                <a href="/cont/dashboard" class="btn btn-secondary">📊 Dashboard</a>
            <?php else: ?>
                <a href="/login" class="btn btn-primary">+ Urmărește</a>
            <?php endif; ?>
            <button class="btn btn-secondary" onclick="shareProfil()" title="Share">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                    <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
                    <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Info -->
    <div class="prf-info-block">
        <div class="prf-name">
            <?= h($display_name) ?>
            <?php if ($is_business): ?><span class="badge-biz">✓ Business verificat</span><?php endif; ?>
            <?php if ($profile['role'] === 'admin'): ?><span class="badge-adm">Admin</span><?php endif; ?>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:var(--text-3);margin-bottom:6px;">
            <?php if ($profile['city']): ?><span>📍 <?= h($profile['city']) ?><?= $profile['county'] ? ', '.h($profile['county']) : '' ?></span><?php endif; ?>
            <?php if ($profile['last_login']): ?><span>🟢 Activ <?= time_ago($profile['last_login']) ?></span><?php endif; ?>
            <span>📅 Membru din <?= date('M Y', strtotime($profile['created_at'])) ?></span>
        </div>
        <?php if ($profile['rating_count'] > 0): ?>
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
            <span style="color:#F59E0B;font-size:15px;"><?= str_repeat('★',$stars_avg).str_repeat('☆',5-$stars_avg) ?></span>
            <span style="font-size:13px;font-weight:700;"><?= number_format($profile['rating_avg'],1) ?></span>
            <span style="font-size:12px;color:var(--text-3);">(<?= $profile['rating_count'] ?> recenzii)</span>
        </div>
        <?php endif; ?>
        <?php if ($profile['bio'] || ($is_business && $profile['business_desc'])): ?>
        <div style="font-size:13px;color:var(--text-2);line-height:1.6;max-width:520px;">
            <?= h($is_business && $profile['business_desc'] ? mb_substr($profile['business_desc'],0,200) : mb_substr($profile['bio'],0,200)) ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="prf-stats">
            <div class="prf-stat"><b><?= count($ads_all) ?></b><small>Anunțuri</small></div>
            <div class="prf-stat"><b><?= number_format($total_views) ?></b><small>Vizualizări</small></div>
            <div class="prf-stat"><b><?= number_format($total_likes) ?></b><small>Like-uri</small></div>
            <div class="prf-stat"><b><?= number_format($profile['followers_count']??0) ?></b><small>Urmăritori</small></div>
            <div class="prf-stat"><b><?= count($reviews) ?></b><small>Recenzii</small></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="prf-tabs">
        <a href="?id=<?= $profile_id ?>&tab=anunturi" class="prf-tab <?= $tab==='anunturi'?'active':'' ?>">📋 Anunțuri (<?= count($ads_all) ?>)</a>
        <?php if (!empty($ads_video)): ?>
        <a href="?id=<?= $profile_id ?>&tab=video" class="prf-tab <?= $tab==='video'?'active':'' ?>">🎬 Video (<?= count($ads_video) ?>)</a>
        <?php endif; ?>
        <a href="?id=<?= $profile_id ?>&tab=recenzii" class="prf-tab <?= $tab==='recenzii'?'active':'' ?>">⭐ Recenzii (<?= count($reviews) ?>)</a>
        <a href="?id=<?= $profile_id ?>&tab=despre" class="prf-tab <?= $tab==='despre'?'active':'' ?>">ℹ️ Despre</a>
    </div>

</div>
</div>

<!-- Tab content -->
<div class="prf-content">

<?php if ($tab === 'anunturi' || $tab === 'video'): ?>
    <?php if (empty($ads)): ?>
    <div style="text-align:center;padding:60px;color:var(--text-3);">
        <div style="font-size:3rem;margin-bottom:12px;"><?= $tab==='video'?'🎬':'📭' ?></div>
        <p><?= $tab==='video'?'Niciun anunț cu video.':'Niciun anunț activ.' ?></p>
    </div>
    <?php else: ?>
    <div class="items-grid">
        <?php foreach ($ads as $ad): include __DIR__ . '/includes/ad-card.php'; endforeach; ?>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'recenzii'): ?>
<div style="display:grid;grid-template-columns:260px 1fr;gap:20px;align-items:start;">

    <div>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;text-align:center;margin-bottom:14px;">
            <div style="font-size:3rem;font-weight:900;line-height:1;"><?= number_format($profile['rating_avg']??0,1) ?></div>
            <div style="color:#F59E0B;font-size:1.4rem;margin:6px 0;"><?= str_repeat('★',$stars_avg).str_repeat('☆',5-$stars_avg) ?></div>
            <div style="font-size:12px;color:var(--text-3);"><?= $profile['rating_count'] ?> recenzii</div>
            <div style="margin-top:14px;text-align:left;">
                <?php foreach ([5,4,3,2,1] as $s):
                    $pct = $profile['rating_count']>0 ? round($rating_breakdown[$s]/$profile['rating_count']*100) : 0; ?>
                <div class="rating-row">
                    <span style="font-size:11px;color:var(--text-3);width:10px;"><?= $s ?></span>
                    <span style="color:#F59E0B;font-size:11px;">★</span>
                    <div class="rating-bar"><div class="rating-fill" style="width:<?= $pct ?>%"></div></div>
                    <span style="font-size:11px;color:var(--text-3);width:20px;text-align:right;"><?= $rating_breakdown[$s] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($can_review): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;">
            <h3 style="font-size:.9rem;font-weight:700;margin-bottom:12px;">Lasă o recenzie</h3>
            <form method="POST">
                <?= csrf_field() ?>
                <div style="display:flex;gap:4px;font-size:1.8rem;cursor:pointer;margin-bottom:10px;" id="star-select">
                    <?php for ($i=1;$i<=5;$i++): ?>
                    <span data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)" style="opacity:.25;color:#F59E0B;user-select:none;">★</span>
                    <?php endfor; ?>
                    <input type="hidden" name="rating" id="rating-val" value="5">
                </div>
                <textarea name="comment" class="form-control" rows="3" placeholder="Cum a fost experiența?" style="margin-bottom:10px;"></textarea>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Trimite ⭐</button>
            </form>
        </div>
        <?php elseif (!$cu): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:14px;text-align:center;font-size:13px;color:var(--text-3);">
            <a href="/login" style="color:var(--brand);">Conectează-te</a> pentru recenzie.
        </div>
        <?php endif; ?>
    </div>

    <div>
        <?php if (empty($reviews)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-3);">
            <div style="font-size:2rem;margin-bottom:8px;">⭐</div><p>Nicio recenzie.</p>
        </div>
        <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
        <div class="review-card">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <img src="<?= h(avatar_url($rv['reviewer_avatar'],$rv['reviewer_name'])) ?>"
                     style="width:38px;height:38px;border-radius:50%;object-fit:cover;" alt="">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:13px;"><?= h($rv['reviewer_name']) ?></div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="color:#F59E0B;font-size:12px;"><?= str_repeat('★',(int)$rv['rating']).str_repeat('☆',5-(int)$rv['rating']) ?></span>
                        <span style="font-size:11px;color:var(--text-3);"><?= time_ago($rv['created_at']) ?></span>
                    </div>
                </div>
            </div>
            <?php if ($rv['comment']): ?><p style="font-size:13px;color:var(--text-2);line-height:1.6;margin:0;"><?= h($rv['comment']) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
<style>@media(max-width:680px){.prf-content>[style*="grid-template-columns:260px"]{grid-template-columns:1fr!important;}}</style>

<?php elseif ($tab === 'despre'): ?>
<div style="max-width:560px;">
    <?php if ($is_business): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:14px;">
        <h3 style="font-size:.9rem;font-weight:700;margin-bottom:14px;">🏢 Informații business</h3>
        <?php
        $brows = [
            ['Nume firmă',$profile['business_name'],null],
            ['Domeniu',$profile['business_category'],null],
            ['Adresă',$profile['business_address'],null],
            ['Telefon',$profile['business_phone'],'tel:'.preg_replace('/\s+/','',$profile['business_phone']??'')],
            ['Website',$profile['business_website'],$profile['business_website']],
            ['Program',$profile['business_schedule'],null],
        ];
        ?>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <?php foreach ($brows as [$l,$v,$h]): if (!$v) continue; ?>
        <tr>
            <td style="color:var(--text-3);padding:9px 0;border-bottom:1px solid var(--border-2);width:38%;"><?= $l ?></td>
            <td style="padding:9px 0;border-bottom:1px solid var(--border-2);font-weight:500;">
                <?php if ($h): ?><a href="<?= htmlspecialchars($h) ?>" target="<?= str_starts_with($h,'http')?'_blank':'' ?>" style="color:var(--brand);"><?= h($v) ?></a>
                <?php else: echo h($v); endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </table>
        <?php if ($profile['business_verified_until']): ?>
        <div style="margin-top:12px;padding:9px 14px;background:#EFF6FF;border-radius:8px;font-size:12px;color:#1D4ED8;">
            ✓ Business verificat · Valabil până <?= date('d.m.Y',strtotime($profile['business_verified_until'])) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;">
        <h3 style="font-size:.9rem;font-weight:700;margin-bottom:14px;">👤 Informații cont</h3>
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <?php
        $irows = [
            ['Tip cont',ucfirst($profile['role'])],
            ['Locație',$profile['city'] ? $profile['city'].($profile['county']?', '.$profile['county']:'') : null],
            ['Anunțuri active',count($ads_all)],
            ['Urmăritori',number_format($profile['followers_count']??0)],
            ['Urmărește',number_format($profile['following_count']??0)],
            ['Vizualizări totale',number_format($total_views)],
            ['Membru din',date('d.m.Y',strtotime($profile['created_at']))],
        ];
        $irows = array_filter($irows, fn($r)=>$r[1]!==null&&$r[1]!=='');
        $irows = array_values($irows);
        foreach ($irows as $i=>[$l,$v]):
            $last = $i===count($irows)-1;
        ?>
        <tr>
            <td style="color:var(--text-3);padding:9px 0;<?= !$last?'border-bottom:1px solid var(--border-2);':'' ?>;width:45%;"><?= $l ?></td>
            <td style="padding:9px 0;<?= !$last?'border-bottom:1px solid var(--border-2);':'' ?>;font-weight:500;"><?= h((string)$v) ?></td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>

</div>

<?= csrf_field() ?>
<script>
async function toggleFollow(userId) {
    const btn = document.getElementById('follow-btn');
    if (!btn) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('user_id', userId);
    fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
    try {
        const r = await fetch('/api/follow.php', {method:'POST',body:fd,credentials:'same-origin'});
        const d = await r.json();
        if (d.error) { alert(d.error); return; }
        btn.textContent = d.following ? '✓ Urmărești' : '+ Urmărește';
        btn.className   = 'btn ' + (d.following ? 'btn-secondary' : 'btn-primary');
    } catch(e) {} finally { btn.disabled = false; }
}
function shareProfil() {
    const url = window.location.href;
    if (navigator.share) navigator.share({title:'<?= h(addslashes($display_name)) ?> pe Veedo', url}).catch(()=>{});
    else navigator.clipboard.writeText(url).then(()=>alert('Link copiat!'));
}
function setRating(val) {
    document.getElementById('rating-val').value = val;
    document.querySelectorAll('#star-select span').forEach((s,i)=>{ s.style.opacity = i<val?'1':'0.25'; });
}
setRating(5);
document.querySelectorAll('#star-select span').forEach((s,i,arr)=>{
    s.addEventListener('mouseover',()=>arr.forEach((x,j)=>x.style.opacity=j<=i?'1':'0.25'));
    s.addEventListener('mouseleave',()=>setRating(+document.getElementById('rating-val').value));
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>