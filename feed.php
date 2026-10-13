<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$uid = current_user()['id'];

// Get following IDs
$following = [];
try {
    $rows = DB::fetchAll("SELECT following_id FROM follows WHERE follower_id=?", [$uid]);
    $following = array_column($rows, 'following_id');
} catch (Exception $e) {}

$has_following = !empty($following);
$pagination = ['items'=>[],'total'=>0,'page'=>1,'pages'=>0];

if ($has_following) {
    $ids = implode(',', array_map('intval', $following));
    $pagination = paginate(
        "SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
                u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
                ap.path AS main_photo
         FROM ads a
         JOIN categories c ON c.id=a.category_id
         JOIN users u ON u.id=a.user_id
         LEFT JOIN ad_photos ap ON ap.ad_id = a.id AND ap.is_primary = 1
         WHERE a.user_id IN ($ids) AND a.status='active'
         ORDER BY a.created_at DESC",
        "SELECT COUNT(*) as total FROM ads a WHERE a.user_id IN ($ids) AND a.status='active'",
        [], 20
    );
}

// Suggested users to follow (most active, verified, with recent ads)
$suggestions = DB::fetchAll("
    SELECT u.id, u.name, u.avatar, u.is_verified_business, u.rating_avg, u.rating_count,
           u.ads_count, u.followers_count,
           (SELECT path FROM ad_photos WHERE ad_id=(SELECT id FROM ads WHERE user_id=u.id AND status='active' ORDER BY created_at DESC LIMIT 1) AND is_primary=1 LIMIT 1) as latest_photo
    FROM users u
    WHERE u.is_active=1 AND u.id != ?
    " . ($has_following ? "AND u.id NOT IN ($ids)" : "") . "
    AND u.ads_count > 0
    ORDER BY u.is_verified_business DESC, u.followers_count DESC, u.ads_count DESC
    LIMIT 8
", [$uid]);

$seo_title = 'Feed personalizat';
include __DIR__ . '/includes/header.php';
?>

<div class="container-lg" style="padding:24px 20px;max-width:1200px;">
  <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:flex-start;">

    <!-- FEED -->
    <div>
      <h1 style="font-size:1.2rem;font-weight:700;margin-bottom:20px;">
        🏠 Feed-ul tău
      </h1>

      <?php if (!$has_following): ?>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:48px 24px;text-align:center;">
        <div style="font-size:3.5rem;margin-bottom:16px;">👥</div>
        <h2 style="font-size:1.1rem;margin-bottom:8px;">Nu urmărești pe nimeni încă</h2>
        <p style="color:var(--text-3);font-size:14px;margin-bottom:20px;">Urmărește vânzători pentru a vedea anunțurile lor noi direct în feed-ul tău.</p>
        <a href="/cauta" class="btn btn-primary">Descoperă vânzători</a>
      </div>
      <?php elseif (empty($pagination['items'])): ?>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:48px 24px;text-align:center;">
        <div style="font-size:3rem;margin-bottom:16px;">📭</div>
        <h2 style="font-size:1.1rem;margin-bottom:8px;">Niciun anunț nou</h2>
        <p style="color:var(--text-3);font-size:14px;">Persoanele pe care le urmărești nu au anunțuri active momentan.</p>
      </div>
      <?php else: ?>
      <div class="items-grid" style="margin-bottom:24px;">
        <?php foreach($pagination['items'] as $ad): include __DIR__ . '/includes/ad-card.php'; endforeach; ?>
      </div>
      <?php if($pagination['pages']>1): ?>
      <nav class="pagination">
        <?php if($pagination['page']>1): ?><a href="?page=<?= $pagination['page']-1 ?>" class="page-btn">←</a><?php endif; ?>
        <?php for($p=1;$p<=$pagination['pages'];$p++): ?>
        <a href="?page=<?= $p ?>" class="page-btn <?= $p==$pagination['page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if($pagination['page']<$pagination['pages']): ?><a href="?page=<?= $pagination['page']+1 ?>" class="page-btn">→</a><?php endif; ?>
      </nav>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- SIDEBAR: Sugestii de urmărit -->
    <aside style="position:sticky;top:76px;">
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:14px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;">
          Sugestii pentru tine
        </h3>
        <?php foreach($suggestions as $s): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
          <a href="/utilizator/<?= $s['id'] ?>">
            <img src="<?= h(avatar_url($s['avatar'],$s['name'])) ?>" width="42" height="42"
                 style="border-radius:50%;object-fit:cover;border:1.5px solid var(--border);" alt="">
          </a>
          <div style="flex:1;min-width:0;">
            <a href="/utilizator/<?= $s['id'] ?>" style="font-size:13px;font-weight:600;color:var(--text);text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= h($s['name']) ?>
              <?php if($s['is_verified_business']): ?><span style="color:var(--info);font-size:11px;">✓</span><?php endif; ?>
            </a>
            <div style="font-size:11px;color:var(--text-3);">
              <?= $s['ads_count'] ?> anunțuri
              <?php if($s['followers_count']>0): ?> · <?= $s['followers_count'] ?> urmăritori<?php endif; ?>
            </div>
          </div>
          <button onclick="quickFollow(<?= $s['id'] ?>,this)"
                  class="btn btn-secondary btn-sm" style="flex-shrink:0;font-size:12px;padding:5px 10px;">
            + Urmărește
          </button>
        </div>
        <?php endforeach; ?>
        <?php if(empty($suggestions)): ?>
        <p style="font-size:13px;color:var(--text-3);text-align:center;padding:12px;">Urmărești deja pe toată lumea activă! 🎉</p>
        <?php endif; ?>
        <a href="/cauta" style="display:block;text-align:center;font-size:13px;color:var(--brand);margin-top:8px;text-decoration:none;">
          Descoperă mai mulți →
        </a>
      </div>

      <?php if($has_following): ?>
      <!-- Following list -->
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:14px;">
        <h3 style="font-size:13px;font-weight:700;margin-bottom:14px;color:var(--text-3);text-transform:uppercase;letter-spacing:0.5px;">
          Urmărești (<?= count($following) ?>)
        </h3>
        <?php
        $followed_users = DB::fetchAll("SELECT u.id, u.name, u.avatar, u.ads_count FROM users u WHERE u.id IN ($ids) ORDER BY u.name LIMIT 10", []);
        foreach($followed_users as $fu):
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
          <a href="/utilizator/<?= $fu['id'] ?>">
            <img src="<?= h(avatar_url($fu['avatar'],$fu['name'])) ?>" width="32" height="32"
                 style="border-radius:50%;object-fit:cover;" alt="">
          </a>
          <a href="/utilizator/<?= $fu['id'] ?>" style="font-size:13px;color:var(--text);text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= h($fu['name']) ?>
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </aside>

  </div>
</div>

<?= csrf_field() ?>
<script>
async function quickFollow(userId, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('user_id', userId);
    fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
    try {
        const r = await fetch('/api/follow.php', {method:'POST', body:fd, credentials:'same-origin'});
        const d = await r.json();
        if (d.error) { alert(d.error); btn.disabled=false; return; }
        if (d.following) {
            btn.textContent = '✓ Urmărești';
            btn.className = 'btn btn-secondary btn-sm';
            btn.style.cssText = 'flex-shrink:0;font-size:12px;padding:5px 10px;opacity:0.6;';
        }
    } catch(e) { btn.disabled=false; }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
