<?php
// Ad card — responsive. Requires $ad in scope.
// Mobil: card imersiv (media full, text peste gradient). Desktop: card "framed" (media în ramă, info dedesubt).
// Opțional: $card_spotlight = true → card mare doar pe mobil.
$card_spotlight = $card_spotlight ?? false;
$card_eager     = $card_eager ?? false;   // primele carduri din pagină: fără lazy-load (LCP mai bun)

if (!isset($GLOBALS['veedo_likes_loaded'])) {
    $GLOBALS['veedo_liked_ids'] = [];
    $GLOBALS['veedo_faved_ids'] = [];
    $GLOBALS['veedo_likes_loaded'] = false;
}
if (is_logged() && !$GLOBALS['veedo_likes_loaded']) {
    $uid = current_user()['id'];
    // array_flip → căutare O(1) per card (in_array era O(n) pentru fiecare card)
    $GLOBALS['veedo_liked_ids'] = array_flip(array_column(DB::fetchAll("SELECT ad_id FROM likes WHERE user_id=?", [$uid]), 'ad_id'));
    $GLOBALS['veedo_faved_ids'] = array_flip(array_column(DB::fetchAll("SELECT ad_id FROM favorites WHERE user_id=?", [$uid]), 'ad_id'));
    $GLOBALS['veedo_likes_loaded'] = true;
}
$isLiked = is_logged() && isset($GLOBALS['veedo_liked_ids'][(int)$ad['id']]);
$isFaved = is_logged() && isset($GLOBALS['veedo_faved_ids'][(int)$ad['id']]);

$_img = '';
if (!empty($ad['main_photo']))      $_img = photo_url($ad['main_photo']);
elseif (!empty($ad['video_thumb'])) $_img = thumb_url($ad['video_thumb']);
$_hasVideo = !empty($ad['video_path']) || !empty($ad['video_thumb']);
$_dur      = (int)($ad['video_duration'] ?? 0);
$_priceTxt = (float)$ad['price'] > 0
    ? h(format_price((float)$ad['price'], $ad['currency'] ?? 'RON'))
    : 'Preț la cerere';
$_classes = 'vh-card vh-reveal'
    . ($card_spotlight ? ' vh-card--feat' : '')
    . (is_boosted($ad) ? ' vh-card--boosted' : '');
$_catColor = safe_color($ad['cat_color'] ?? '', '#55535E');
?>
<a href="/anunt/<?= h($ad['slug']) ?>" class="<?= $_classes ?>">
  <div class="vh-scene">
    <?php if ($_img): ?>
      <img src="<?= h($_img) ?>" alt="<?= h($ad['title']) ?>" width="400" height="400"
           <?= $card_eager ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async"
           onerror="this.onerror=null;this.style.display='none';this.parentNode.insertAdjacentHTML('beforeend','<div class=&quot;vh-ph&quot;><?= h($ad['cat_icon'] ?? '📦') ?></div>');">
    <?php else: ?>
      <div class="vh-ph"><?= h($ad['cat_icon'] ?? '📦') ?></div>
    <?php endif; ?>
    <?php if ($_hasVideo): ?>
      <span class="vh-video-badge"><i><svg width="9" height="9" viewBox="0 0 24 24" fill="#fff"><path d="M7 4v16l13-8z"/></svg></i><?= $_dur > 0 ? format_duration($_dur) : 'Video' ?></span>
    <?php endif; ?>
  </div>

  <div class="vh-tags">
    <span class="vh-cat-badge" style="background:<?= h($_catColor) ?>"><?= h($ad['cat_name'] ?? '') ?></span>
    <?php if (is_boosted($ad)): ?>
      <span class="vh-boost-badge">★ Promovat</span>
    <?php endif; ?>
  </div>

  <div class="vh-actions">
    <button type="button" class="vh-ibtn like-btn<?= $isLiked ? ' liked' : '' ?>" data-ad-id="<?= (int)$ad['id'] ?>" aria-label="Apreciază" aria-pressed="<?= $isLiked ? 'true' : 'false' ?>" onclick="event.preventDefault();event.stopPropagation();">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="<?= $isLiked?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2.3" stroke-linejoin="round"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5c0 2.1 1.5 4 3 5.5l7 7z"/></svg>
    </button>
    <button type="button" class="vh-ibtn fav-btn<?= $isFaved ? ' saved' : '' ?>" data-ad-id="<?= (int)$ad['id'] ?>" aria-label="Salvează" aria-pressed="<?= $isFaved ? 'true' : 'false' ?>" onclick="event.preventDefault();event.stopPropagation();">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="<?= $isFaved?'currentColor':'none' ?>" stroke="currentColor" stroke-width="2.3" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
    </button>
  </div>

  <div class="vh-info">
    <div class="vh-title"><?= h($ad['title']) ?></div>
    <div class="vh-price"><?= $_priceTxt ?><?php if ((float)$ad['price'] > 0 && !empty($ad['price_negotiable'])): ?> <span class="vh-neg">· negociabil</span><?php endif; ?></div>
    <div class="vh-meta">
      <?php if (!empty($ad['city'])): ?><span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><?= h($ad['city']) ?></span><?php endif; ?>
      <span><?= h(time_ago($ad['bumped_at'] ?? null ?: $ad['created_at'])) ?></span>
    </div>
    <div class="vh-seller">
      <img src="<?= h(avatar_url($ad['user_avatar'] ?? null, $ad['user_name'] ?? '')) ?>" alt="" width="22" height="22" loading="lazy" decoding="async">
      <span><?= h(mb_substr($ad['user_name'] ?? '', 0, 22)) ?></span>
      <?php if (!empty($ad['is_verified_business'])): ?>
        <svg class="vh-verified" viewBox="0 0 24 24" fill="none" aria-label="Business verificat"><circle cx="12" cy="12" r="11" fill="#4F46E5"/><path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <?php endif; ?>
      <span class="vh-sep" style="margin-left:auto"></span>
      <span>👁 <?= number_format((int)($ad['views_count'] ?? 0), 0, ',', '.') ?></span>
    </div>
  </div>
</a>
