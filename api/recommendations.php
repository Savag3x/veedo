<?php
// api/recommendations.php — AI-like recomandări personalizate
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json');

$u = current_user();
$uid = $u['id'] ?? 0;
$limit = max(1, min((int)($_GET['limit'] ?? 12), 24));
$exclude = (int)($_GET['exclude_ad'] ?? 0);

if (!setting('ai_recommendations','1')) {
    json_response(['ads'=>[],'type'=>'disabled']);
}

// Algoritm de recomandare bazat pe comportament:
// 1. Categorii vizualizate recent
// 2. Categorii like-uite
// 3. Vanzatori urmăriti
// 4. Localitate utilizator
// 5. Fallback: populare + boosted

// Context: similar (bazat pe un anunt specific)
$ad_id = (int)($_GET['ad_id'] ?? 0);
if ($ad_id && isset($_GET['context']) && $_GET['context'] === 'similar') {
    $source_ad = DB::fetch("SELECT category_id, price FROM ads WHERE id=?", [$ad_id]);
    if ($source_ad) {
        $cat_id = $source_ad['category_id'];
        $price = (float)$source_ad['price'];
        $price_min = $price > 0 ? $price * 0.5 : 0;
        $price_max = $price > 0 ? $price * 1.5 : 999999999;
        
        $similar = DB::fetchAll("
            SELECT a.*, c.name as cat_name, c.color as cat_color, c.icon as cat_icon,
                   u.name as user_name, u.avatar as user_avatar, u.is_verified_business,
                   (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo,
                   'similar' as rec_reason
            FROM ads a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.user_id
            WHERE a.status='active' AND a.category_id=? AND a.id != ?
              AND (? = 0 OR (a.price BETWEEN ? AND ?))
            ORDER BY a.is_boosted DESC, a.is_featured DESC, a.views_count DESC
            LIMIT ?
        ", [$cat_id, $ad_id, $price, $price_min, $price_max, $limit]);
        
        foreach ($similar as &$s) {
            $s['rec_label']       = '📂 Din aceeași categorie';
            $s['url']             = BASE_URL . '/anunt/' . $s['slug'];
            $s['photo_url']       = $s['main_photo'] ? photo_url($s['main_photo']) : '';
            $s['price_formatted'] = ($s['price'] > 0)
                ? format_price((float)$s['price'], $s['currency'] ?? 'RON')
                : 'Preț la cerere';
        }
        json_response(['ads' => $similar, 'count' => count($similar), 'type' => 'similar']);
    }
}

$recommended = [];

if ($uid) {
    // Categorii vizualizate recent (ultimele 30 zile)
    $viewed_cats = DB::fetchAll("
        SELECT a.category_id, COUNT(*) as cnt
        FROM ad_views av JOIN ads a ON a.id=av.ad_id
        WHERE av.user_id=? AND av.viewed_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY a.category_id ORDER BY cnt DESC LIMIT 5
    ", [$uid]);
    
    // Categorii like-uite
    $liked_cats = DB::fetchAll("
        SELECT a.category_id, COUNT(*) as cnt
        FROM likes l JOIN ads a ON a.id=l.ad_id
        WHERE l.user_id=?
        GROUP BY a.category_id ORDER BY cnt DESC LIMIT 3
    ", [$uid]);
    
    // Construim scor per categorie
    $cat_scores = [];
    foreach ($viewed_cats as $vc) $cat_scores[$vc['category_id']] = ($cat_scores[$vc['category_id']] ?? 0) + $vc['cnt'] * 2;
    foreach ($liked_cats as $lc)  $cat_scores[$lc['category_id']] = ($cat_scores[$lc['category_id']] ?? 0) + $lc['cnt'] * 3;
    
    // Anunturi din categorii preferate de la vanzatori urmăriti
    if (!empty($cat_scores)) {
        arsort($cat_scores);
        $top_cats = array_keys(array_slice($cat_scores, 0, 3, true));
        $cats_in = implode(',', array_map('intval', $top_cats));
        
        $rec_from_following = DB::fetchAll("
            SELECT a.*, c.name as cat_name, c.color as cat_color, c.icon as cat_icon,
                   u.name as user_name, u.avatar as user_avatar, u.is_verified_business,
                   (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo,
                   'following_category' as rec_reason
            FROM ads a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.user_id
            WHERE a.status='active' AND a.category_id IN ($cats_in)
              AND a.user_id IN (SELECT following_id FROM follows WHERE follower_id=?)
              AND a.id != ?
              AND a.id NOT IN (SELECT ad_id FROM ad_views WHERE user_id=? AND viewed_at > DATE_SUB(NOW(), INTERVAL 7 DAY))
            ORDER BY a.is_boosted DESC, a.created_at DESC
            LIMIT ?
        ", [$uid, $exclude, $uid, min($limit, 6)]);
        
        $recommended = array_merge($recommended, $rec_from_following);
    }
    
    // Anunturi din aceeasi localitate
    if (!empty($u['city']) && count($recommended) < 4) {
        // (bug vechi: SQL-ul generat era invalid "IN ( OR 1=0)" când lista era goală)
        $skip = array_map('intval', array_column($recommended, 'id'));
        $skip[] = $exclude;
        $local = DB::fetchAll("
            SELECT a.*, c.name as cat_name, c.color as cat_color, c.icon as cat_icon,
                   u.name as user_name, u.avatar as user_avatar, u.is_verified_business,
                   (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo,
                   'local' as rec_reason
            FROM ads a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.user_id
            WHERE a.status='active' AND a.city=? AND a.user_id != ?
              AND a.id NOT IN (" . implode(',', $skip) . ")
            ORDER BY a.is_boosted DESC, a.views_count DESC
            LIMIT ?
        ", [$u['city'], $uid, 4 - count($recommended)]);
        $recommended = array_merge($recommended, $local);
    }
}

// Completam cu anunturi populare daca nu avem destule
$existing_ids = array_column($recommended, 'id');
$existing_ids[] = $exclude ?: 0;
$ids_str = implode(',', array_map('intval', $existing_ids));

$popular = DB::fetchAll("
    SELECT a.*, c.name as cat_name, c.color as cat_color, c.icon as cat_icon,
           u.name as user_name, u.avatar as user_avatar, u.is_verified_business,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo,
           'popular' as rec_reason
    FROM ads a JOIN categories c ON c.id=a.category_id JOIN users u ON u.id=a.user_id
    WHERE a.status='active' AND a.id NOT IN ($ids_str)
    ORDER BY a.is_boosted DESC, a.is_featured DESC, a.views_count DESC, a.likes_count DESC, a.created_at DESC
    LIMIT ?
", [max(0, $limit - count($recommended))]);

$recommended = array_merge($recommended, $popular);
$recommended = array_slice($recommended, 0, $limit);

// Adaugam metadata + câmpuri necesare pentru frontend
// Eliminăm câmpurile interne care nu trebuie expuse public
$private = ['lat','lng','address','reject_reason','contact_count','meta_title','meta_description','video_path'];
foreach ($recommended as &$ad) {
    foreach ($private as $pk) unset($ad[$pk]);
    $ad['rec_label'] = match($ad['rec_reason'] ?? 'popular') {
        'following_category' => '👥 De la urmăriți',
        'local'              => '📍 Din zona ta',
        'liked_similar'      => '❤️ Similar cu ce ai likat',
        default              => '🔥 Popular',
    };
    // Câmpuri necesare pentru JS din anunt.php
    $ad['url']             = BASE_URL . '/anunt/' . $ad['slug'];
    $ad['photo_url']       = $ad['main_photo'] ? photo_url($ad['main_photo']) : '';
    $ad['price_formatted'] = ($ad['price'] > 0)
        ? format_price((float)$ad['price'], $ad['currency'] ?? 'RON')
        : 'Preț la cerere';
}

json_response([
    'ads'   => $recommended,
    'count' => count($recommended),
    'type'  => $uid ? 'personalized' : 'popular',
]);
