<?php
// Ad card — responsive. Requires $ad in scope.
// Mobile: immersive overlay. Desktop: clean classic card (image top, body below).
// Optional: $card_spotlight = true → larger card on mobile only.
$card_spotlight = $card_spotlight ?? false;

if (!isset($GLOBALS['veedo_likes_loaded'])) {
    $GLOBALS['veedo_liked_ids'] = [];
    $GLOBALS['veedo_faved_ids'] = [];
    $GLOBALS['veedo_likes_loaded'] = false;
}
if (is_logged() && !$GLOBALS['veedo_likes_loaded']) {
    $uid = current_user()['id'];
    $GLOBALS['veedo_liked_ids'] = array_column(DB::fetchAll("SELECT ad_id FROM likes WHERE user_id=?", [$uid]), 'ad_id');
    $GLOBALS['veedo_faved_ids'] = array_column(DB::fetchAll("SELECT ad_id FROM favorites WHERE user_id=?", [$uid]), 'ad_id');
    $GLOBALS['veedo_likes_loaded'] = true;
}
$isLiked = is_logged() && in_array((int)$ad['id'], $GLOBALS['veedo_liked_ids']);
$isFaved = is_logged() && in_array((int)$ad['id'], $GLOBALS['veedo_faved_ids']);

$_img = '';
if (!empty($ad['video_thumb']))    $_img = thumb_url($ad['video_thumb']);
elseif (!empty($ad['main_photo'])) $_img = photo_url($ad['main_photo']);
$_priceTxt = $ad['price']
    ? h(format_price((float)$ad['price'], $ad['currency']))
    : 'Preț la cerere';
$_classes = 'vh-card vh-reveal'
    . ($card_spotlight ? ' vh-card--feat' : '')
    . (!empty($ad['is_boosted']) ? ' vh-card--boosted' : '');
?>
<a href="/anunt/<?= h($ad['slug']) ?>" class="<?= $_classes ?>">
  <div class="vh-scene">
    <?php if ($_img): ?>
      <img src="<?= h($_img) ?>" alt="<?= h($ad['title']) ?>" loading="lazy"
           onerror="this.onerror=null;this.style.display='none';this.parentNode.insertAdjacentHTML('beforeend','<div class=&quot;vh-ph&quot;><?= h($ad['cat_icon'] ?? '📦') ?></div>');">
    <?php else: ?>
      <div class="vh-ph"><?= h($ad['cat_icon'] ?? '📦') ?></div>
    <?php endif; ?>
  </div>

  <div class="vh-tags">
    <span class="vh-cat-badge" style="background:<?= h($ad['cat_color'] ?? '#888') ?>"><?= h($ad['cat_name']) ?></span>
    <?php if (!empty($ad['is_boosted'])): ?>
      <span class="vh-boost-badge">⭐ Promovat</span>
    <?php endif; ?>
  </div>

  <div class="vh-actions">
    <button type="button" class="vh-ibtn like-btn<?= $isLiked ? ' liked' : '' ?>" data-ad-id="<?= (int)$ad['id'] ?>" aria-label="Apreciază" onclick="event.preventDefault();event.stopPropagation();">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="<?= $isLiked?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.1 1.5 4 3 5.5l7 7z"/></svg>
    </button>
    <button type="button" class="vh-ibtn fav-btn<?= $isFaved ? ' saved' : '' ?>" data-ad-id="<?= (int)$ad['id'] ?>" aria-label="Salvează" onclick="event.preventDefault();event.stopPropagation();">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="<?= $isFaved?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
    </button>
  </div>

  <div class="vh-info">
    <div class="vh-title"><?= h($ad['title']) ?></div>
    <div class="vh-price"><?= $_priceTxt ?><?php if($ad['price'] && !empty($ad['price_negotiable'])): ?> <span class="vh-neg">· Negociabil</span><?php endif; ?></div>
    <div class="vh-meta">
      <?php if (!empty($ad['city'])): ?><span>📍 <?= h($ad['city']) ?></span><?php endif; ?>
      <span>👁 <?= number_format((int)$ad['views_count']) ?></span>
      <span><?= time_ago($ad['created_at']) ?></span>
    </div>
    <div class="vh-seller">
      <img src="<?= h(avatar_url($ad['user_avatar'] ?? null, $ad['user_name'] ?? '')) ?>" alt="" loading="lazy">
      <span><?= h(mb_substr($ad['user_name'] ?? '', 0, 18)) ?></span>
      <?php if (!empty($ad['is_verified_business'])): ?>
        <svg class="vh-verified" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#FF6B35"/><path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <?php endif; ?>
    </div>
  </div>
</a>
