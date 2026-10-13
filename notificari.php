<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$uid = current_user()['id'];

// Delete all notifications
if (isset($_GET['delete_all'])) {
    DB::query("DELETE FROM notifications WHERE user_id=?", [$uid]);
    flash('success','Toate notificările au fost șterse.');
    redirect('/notificari');
}

// Mark all as read
if (isset($_GET['mark_read'])) {
    DB::query("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid]);
    redirect('/notificari');
}

$notifications = DB::fetchAll("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50", [$uid]);
$unread_count  = array_sum(array_column($notifications, 'is_read') === 0 ? [] : array_map(fn($n) => (int)!$n['is_read'], $notifications));

// Mark fetched ones as read after display
DB::query("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid]);

$seo_title = 'Notificări';
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:28px 20px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
    <h1>Notificări</h1>
    <?php if ($notifications): ?>
      <a href="/notificari?mark_read=1" class="btn btn-secondary btn-sm">✓ Marchează toate citite</a>
      <a href="/notificari?delete_all=1" class="btn btn-secondary btn-sm" onclick="return confirm('Ștergi toate notificările?')">🗑 Șterge toate</a>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
    <div style="text-align:center;padding:80px 20px;color:var(--text-3);">
      <div style="font-size:3rem;margin-bottom:12px;">🔔</div>
      <h3>Nicio notificare</h3>
      <p style="margin-top:8px;">Vei primi notificări când ai mesaje noi sau anunțurile tale sunt aprobate.</p>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php foreach ($notifications as $n): ?>
      <div style="background:var(--surface);border:1px solid <?= !$n['is_read'] ? 'var(--brand)' : 'var(--border-2)' ?>;border-radius:var(--radius-lg);padding:16px;display:flex;gap:14px;align-items:flex-start;<?= !$n['is_read'] ? 'background:rgba(255,59,48,0.03);' : '' ?>">
        <div style="font-size:1.5rem;flex-shrink:0;">
          <?= match($n['type']) {
            'message'   => '💬',
            'ad_approved'=> '✅',
            'ad_rejected'=> '❌',
            'new_review' => '⭐',
            'boost_expired'=> '⏰',
            'system'    => '🔔',
            default     => '📢'
          } ?>
        </div>
        <div style="flex:1;">
          <div style="font-weight:<?= !$n['is_read']?'600':'400' ?>;font-size:14px;"><?= h($n['title']) ?></div>
          <?php if ($n['body']): ?>
            <div style="font-size:13px;color:var(--text-3);margin-top:3px;"><?= h($n['body']) ?></div>
          <?php endif; ?>
          <div style="font-size:12px;color:var(--text-3);margin-top:6px;"><?= time_ago($n['created_at']) ?></div>
        </div>
        <?php if ($n['link']): ?>
          <a href="<?= h($n['link']) ?>" class="btn btn-secondary btn-sm" style="flex-shrink:0;">Deschide</a>
        <?php endif; ?>
        <?php if (!$n['is_read']): ?>
          <span style="width:8px;height:8px;border-radius:50%;background:var(--brand);flex-shrink:0;margin-top:4px;"></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
