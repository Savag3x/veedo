<?php
// ============================================================
// VEEDO — Generator de date demo
// Folosit de: database/seed-demo.php (CLI) și admin/demo-data.php (panou admin)
//
// Toți utilizatorii demo au email @demo.veedo.ro și o parolă aleatorie (nimeni nu se poate
// autentifica în conturile lor) — astfel pot fi identificați și șterși oricând, fără a atinge
// datele reale.
// ============================================================

const DEMO_EMAIL_DOMAIN = 'demo.veedo.ro';

/** Rulează tot procesul. Opțiuni: reset (bool), photos ('auto'|'online'|'generated'), revive (bool). */
function demo_seed(array $o = []): array {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $log = [];
    $o += ['reset' => true, 'photos' => 'auto', 'revive' => false];

    if (!extension_loaded('gd')) throw new RuntimeException('Extensia PHP GD lipsește — necesară pentru generarea pozelor.');

    if ($o['reset']) $log[] = demo_reset();
    if ($o['revive']) $log[] = demo_revive();

    $data = require __DIR__ . '/demo-data.php';
    foreach ([PHOTO_PATH, AVATAR_PATH, UPLOAD_PATH . 'stories/'] as $d) if (!is_dir($d)) @mkdir($d, 0755, true);

    $cats = [];
    foreach (DB::fetchAll("SELECT id, slug, color FROM categories") as $c) $cats[$c['slug']] = $c;
    $fields = [];
    foreach (DB::fetchAll("SELECT id, category_id, field_name FROM category_fields WHERE is_active=1") as $f) {
        $fields[$f['category_id']][$f['field_name']] = (int)$f['id'];
    }

    $online = $o['photos'] !== 'generated';
    $photoStats = ['online' => 0, 'generated' => 0];

    // ── Utilizatori ──────────────────────────────────────────
    $uids = []; $business = []; $private = [];
    foreach ($data['users'] as $i => [$name, $city, $county, $isBiz, $phone, $bio]) {
        $email = slug($name) . '@' . DEMO_EMAIL_DOMAIN;
        $avatar = demo_avatar($name, $i);
        $uids[$i] = DB::insert('users', [
            'name' => $name, 'email' => $email,
            'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
            'phone' => $phone, 'avatar' => $avatar, 'bio' => $bio ?: null, 'city' => $city, 'county' => $county,
            'role' => $isBiz ? 'business' : 'user', 'is_verified_business' => $isBiz ? 1 : 0,
            'business_verified_until' => $isBiz ? date('Y-m-d', strtotime('+1 year')) : null,
            'business_name' => $isBiz ? $name : null, 'business_desc' => $isBiz ? $bio : null,
            'is_active' => 1, 'is_email_verified' => 1, 'theme_preference' => 'auto',
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(40, 400) . ' days')),
        ]);
        if ($isBiz) $business[] = $i; else $private[] = $i;
    }
    $log[] = count($uids) . ' utilizatori demo creați (' . count($business) . ' business verificați).';

    // ── Anunțuri ─────────────────────────────────────────────
    $adIds = []; $adOwner = []; $adCount = array_fill_keys(array_keys($uids), 0);
    foreach ($data['ads'] as $n => $a) {
        if (!isset($cats[$a['cat']])) continue;
        $cat = $cats[$a['cat']];
        $owner = $uids[$a['u']];
        $created = time() - mt_rand(2 * 3600, 18 * 86400);
        $status = ($n % 17 === 5) ? 'pending' : (($n % 19 === 7) ? 'sold' : 'active');
        $boost = !empty($a['boost']) && $status === 'active';

        $adId = DB::insert('ads', [
            'user_id' => $owner, 'category_id' => $cat['id'],
            'title' => $a['title'], 'slug' => unique_slug($a['title']), 'description' => $a['desc'],
            'price' => $a['price'] > 0 ? $a['price'] : null, 'price_negotiable' => $a['neg'] ? 1 : 0,
            'currency' => $a['cur'], 'city' => $a['city'], 'county' => $a['county'],
            'status' => $status,
            'is_boosted' => $boost ? 1 : 0,
            'boosted_until' => $boost ? date('Y-m-d H:i:s', strtotime('+' . mt_rand(5, 14) . ' days')) : null,
            'boost_type' => $boost ? 'premium' : null,
            'views_count' => $boost ? mt_rand(1800, 9000) : mt_rand(40, 2400),
            'contact_count' => mt_rand(0, 60),
            'expires_at' => date('Y-m-d', strtotime('+60 days')),
            'created_at' => date('Y-m-d H:i:s', $created),
            'bumped_at' => mt_rand(0, 3) === 0 ? date('Y-m-d H:i:s', min(time(), $created + mt_rand(86400, 4 * 86400))) : null,
        ]);
        $adIds[$n] = $adId; $adOwner[$n] = $a['u'];
        $adCount[$a['u']]++;

        // Câmpuri specifice categoriei
        foreach ($a['f'] ?? [] as $fname => $fval) {
            if (isset($fields[$cat['id']][$fname])) {
                DB::query("INSERT IGNORE INTO ad_fields (ad_id, field_id, field_value) VALUES (?,?,?)", [$adId, $fields[$cat['id']][$fname], (string)$fval]);
            }
        }

        // Poze: online (LoremFlickr, după cuvinte cheie) sau generate local
        $nPhotos = $boost ? 4 : 3;
        $files = [];
        for ($p = 0; $p < $nPhotos; $p++) $files[$p] = bin2hex(random_bytes(16)) . '.jpg';
        // Descărcare în paralel (toate pozele anunțului deodată)
        $got = $online ? demo_download_photos($a['kw'], $n * 10, array_map(fn($f) => PHOTO_PATH . $f, $files)) : [];
        if ($online && !array_filter($got) && $o['photos'] === 'auto' && $photoStats['online'] === 0) $online = false; // fără internet → nu mai încercăm
        foreach ($files as $p => $file) {
            $ok = !empty($got[$p]);
            if ($ok) $photoStats['online']++;
            else { demo_generate_photo(PHOTO_PATH . $file, $cat['color'] ?: '#7357FF', $a['title'], '', $n * 7 + $p); $photoStats['generated']++; }
            DB::insert('ad_photos', ['ad_id' => $adId, 'path' => $file, 'sort_order' => $p, 'is_primary' => $p === 0 ? 1 : 0]);
        }
    }
    $log[] = count($adIds) . ' anunțuri create cu ' . ($photoStats['online'] + $photoStats['generated']) . ' poze ('
           . $photoStats['online'] . ' descărcate, ' . $photoStats['generated'] . ' generate).';

    // ── Like-uri, favorite, comentarii ───────────────────────
    $nLikes = 0; $nComments = 0;
    foreach ($adIds as $n => $adId) {
        $fans = array_diff(array_keys($uids), [$adOwner[$n]]);
        shuffle($fans);
        $real = 0;
        foreach (array_slice($fans, 0, mt_rand(2, 9)) as $f) {
            $real += DB::query("INSERT IGNORE INTO likes (user_id, ad_id) VALUES (?,?)", [$uids[$f], $adId])->rowCount();
            if (mt_rand(0, 2) === 0) DB::query("INSERT IGNORE INTO favorites (user_id, ad_id) VALUES (?,?)", [$uids[$f], $adId]);
        }
        $nLikes += $real;
        DB::query("UPDATE ads SET likes_count=? WHERE id=?", [$real + mt_rand(4, 140), $adId]);

        for ($c = 0, $k = mt_rand(0, 3); $c < $k; $c++) {
            $who = $fans[$c] ?? $fans[0];
            $cid = DB::insert('ad_comments', [
                'ad_id' => $adId, 'user_id' => $uids[$who], 'body' => $data['questions'][array_rand($data['questions'])],
                'created_at' => date('Y-m-d H:i:s', time() - mt_rand(3600, 10 * 86400)),
            ]);
            $nComments++;
            if (mt_rand(0, 1)) {
                DB::insert('ad_comments', [
                    'ad_id' => $adId, 'user_id' => $uids[$adOwner[$n]], 'parent_id' => $cid,
                    'body' => $data['answers'][array_rand($data['answers'])],
                    'created_at' => date('Y-m-d H:i:s', time() - mt_rand(60, 3600)),
                ]);
                $nComments++;
            }
        }
    }
    $log[] = "$nLikes like-uri și $nComments comentarii adăugate.";

    // ── Recenzii pentru vânzătorii business ─────────────────
    $nRev = 0;
    foreach ($business as $b) {
        $buyers = $private; shuffle($buyers);
        $sellerAds = array_keys(array_filter($adOwner, fn($o) => $o === $b));
        foreach (array_slice($buyers, 0, mt_rand(2, 5)) as $buyer) {
            [$rating, $text] = $data['reviews'][array_rand($data['reviews'])];
            DB::query("INSERT IGNORE INTO reviews (reviewer_id, reviewed_id, ad_id, rating, comment, is_approved, created_at) VALUES (?,?,?,?,?,1,?)",
                [$uids[$buyer], $uids[$b], $sellerAds ? $adIds[$sellerAds[array_rand($sellerAds)]] : null, $rating, $text,
                 date('Y-m-d H:i:s', time() - mt_rand(86400, 60 * 86400))]);
            $nRev++;
        }
        $st = DB::fetch("SELECT AVG(rating) a, COUNT(*) c FROM reviews WHERE reviewed_id=? AND is_approved=1", [$uids[$b]]);
        DB::update('users', ['rating_avg' => round((float)$st['a'], 2), 'rating_count' => (int)$st['c']], 'id=?', [$uids[$b]]);
    }

    // ── Urmăriri ─────────────────────────────────────────────
    foreach ($private as $p) {
        $targets = $business; shuffle($targets);
        foreach (array_slice($targets, 0, mt_rand(2, 4)) as $t) {
            DB::query("INSERT IGNORE INTO follows (follower_id, following_id) VALUES (?,?)", [$uids[$p], $uids[$t]]);
        }
    }
    foreach ($uids as $i => $id) {
        DB::query("UPDATE users SET ads_count=?,
                     followers_count=(SELECT COUNT(*) FROM follows WHERE following_id=?),
                     following_count=(SELECT COUNT(*) FROM follows WHERE follower_id=?) WHERE id=?",
            [$adCount[$i], $id, $id, $id]);
    }
    $log[] = "$nRev recenzii și urmăriri între utilizatori adăugate.";

    // ── Conversații ──────────────────────────────────────────
    $nConv = 0;
    $active = array_keys(array_filter($adIds, fn($id) => true));
    shuffle($active);
    foreach (array_slice($active, 0, 8) as $n) {
        $sellerIdx = $adOwner[$n];
        $buyerIdx = $private[array_rand($private)];
        if ($buyerIdx === $sellerIdx) continue;
        $script = $data['chats'][array_rand($data['chats'])];
        $t = time() - mt_rand(3600, 5 * 86400);
        $conv = DB::insert('conversations', [
            'ad_id' => $adIds[$n], 'buyer_id' => $uids[$buyerIdx], 'seller_id' => $uids[$sellerIdx],
            'last_message' => end($script), 'last_message_at' => date('Y-m-d H:i:s', $t + count($script) * 300),
            'buyer_unread' => 0, 'seller_unread' => count($script) % 2, 'created_at' => date('Y-m-d H:i:s', $t),
        ]);
        foreach ($script as $k => $msg) {
            DB::insert('messages', [
                'conversation_id' => $conv, 'sender_id' => $k % 2 === 0 ? $uids[$buyerIdx] : $uids[$sellerIdx],
                'body' => $msg, 'is_read' => $k < count($script) - 1 ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s', $t + $k * 300),
            ]);
        }
        $nConv++;
    }

    // ── Stories (24h) ────────────────────────────────────────
    $nStories = 0;
    foreach (array_slice($business, 0, 5) as $k => $b) {
        $file = bin2hex(random_bytes(12)) . '.jpg';
        demo_generate_photo(UPLOAD_PATH . 'stories/' . $file, ['#7357FF', '#FF4FA3', '#0EA5E9', '#10B981', '#F59E0B'][$k],
            $data['stories'][$k], $data['users'][$b][0], 500 + $k, 1080, 1920);
        DB::insert('stories', [
            'user_id' => $uids[$b], 'media_path' => $file, 'media_type' => 'photo', 'caption' => $data['stories'][$k],
            'views_count' => mt_rand(20, 400), 'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        ]);
        $nStories++;
    }
    $log[] = "$nConv conversații și $nStories stories (vizibile 24h) adăugate.";

    foreach (['home_stats', 'footer_cats', 'footer_pages', 'settings'] as $k) cache_forget($k);
    $log[] = 'Gata! Utilizatorii demo au email @' . DEMO_EMAIL_DOMAIN . ' și pot fi șterși oricând.';
    return $log;
}

/** Șterge TOATE datele demo (utilizatori @demo.veedo.ro și tot ce țin de ei), inclusiv fișierele. */
function demo_reset(): string {
    $users = DB::fetchAll("SELECT id, avatar FROM users WHERE email LIKE ?", ['%@' . DEMO_EMAIL_DOMAIN]);
    if (!$users) return 'Nu existau date demo anterioare.';
    $ids = array_map('intval', array_column($users, 'id'));
    $in = implode(',', $ids);

    $ads = DB::fetchAll("SELECT id FROM ads WHERE user_id IN ($in)");
    $adIn = $ads ? implode(',', array_map('intval', array_column($ads, 'id'))) : '0';

    foreach (DB::fetchAll("SELECT path FROM ad_photos WHERE ad_id IN ($adIn)") as $p) @unlink(PHOTO_PATH . basename($p['path']));
    foreach (DB::fetchAll("SELECT media_path FROM stories WHERE user_id IN ($in)") as $s) @unlink(UPLOAD_PATH . 'stories/' . basename($s['media_path']));
    foreach ($users as $u) if ($u['avatar']) @unlink(AVATAR_PATH . basename($u['avatar']));

    $convIds = DB::fetchAll("SELECT id FROM conversations WHERE ad_id IN ($adIn) OR buyer_id IN ($in) OR seller_id IN ($in)");
    $convIn = $convIds ? implode(',', array_map('intval', array_column($convIds, 'id'))) : '0';

    $stmts = [
        "DELETE FROM messages WHERE conversation_id IN ($convIn) OR sender_id IN ($in)",
        "DELETE FROM conversations WHERE id IN ($convIn)",
        "DELETE FROM ad_photos WHERE ad_id IN ($adIn)",
        "DELETE FROM ad_fields WHERE ad_id IN ($adIn)",
        "DELETE FROM ad_views WHERE ad_id IN ($adIn) OR user_id IN ($in)",
        "DELETE FROM likes WHERE ad_id IN ($adIn) OR user_id IN ($in)",
        "DELETE FROM favorites WHERE ad_id IN ($adIn) OR user_id IN ($in)",
        "DELETE FROM ad_comments WHERE ad_id IN ($adIn) OR user_id IN ($in)",
        "DELETE FROM reports WHERE ad_id IN ($adIn) OR reporter_id IN ($in)",
        "DELETE FROM reviews WHERE reviewer_id IN ($in) OR reviewed_id IN ($in)",
        "DELETE FROM follows WHERE follower_id IN ($in) OR following_id IN ($in)",
        "DELETE FROM notifications WHERE user_id IN ($in)",
        "DELETE FROM story_views WHERE viewer_id IN ($in) OR story_id IN (SELECT id FROM stories WHERE user_id IN ($in))",
        "DELETE FROM stories WHERE user_id IN ($in)",
        "DELETE FROM ads WHERE id IN ($adIn)",
        "DELETE FROM users WHERE id IN ($in)",
    ];
    foreach ($stmts as $sql) {
        try { DB::query($sql); } catch (Throwable $e) { /* tabel opțional lipsă */ }
    }
    // Contoarele utilizatorilor reali care urmăreau/erau urmăriți de conturi demo
    DB::query("UPDATE users u SET followers_count=(SELECT COUNT(*) FROM follows WHERE following_id=u.id),
                                  following_count=(SELECT COUNT(*) FROM follows WHERE follower_id=u.id)");
    return 'Date demo anterioare șterse: ' . count($ids) . ' utilizatori, ' . count($ads) . ' anunțuri.';
}

/** Reactivează anunțurile active existente care au expirat (le prelungește cu 60 de zile). */
function demo_revive(): string {
    $n = DB::query("UPDATE ads SET expires_at = DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                    WHERE status='active' AND expires_at IS NOT NULL AND expires_at < CURDATE()")->rowCount();
    return "$n anunțuri existente expirate au fost prelungite cu 60 de zile.";
}

/**
 * Descarcă în paralel poze reale după cuvinte cheie (LoremFlickr, licențe Creative Commons).
 * Returnează [index => bool]. Fiecare poză e re-encodată (fără metadate / conținut neașteptat).
 */
function demo_download_photos(string $kw, int $lockBase, array $dests): array {
    if (!function_exists('curl_multi_init')) return [];
    $mh = curl_multi_init();
    $hs = [];
    foreach ($dests as $i => $dest) {
        $ch = curl_init('https://loremflickr.com/1080/1350/' . rawurlencode($kw) . '?lock=' . ($lockBase + $i));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'VeedoDemoSeeder/1.0']);
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    $res = [];
    foreach ($hs as $i => $ch) {
        $img = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $res[$i] = false;
        if ($code !== 200 || !$img || strlen($img) < 5000) continue;
        $info = @getimagesizefromstring($img);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) continue;
        $im = @imagecreatefromstring($img);
        if (!$im) continue;
        $res[$i] = imagejpeg($im, $dests[$i], 84);
        imagedestroy($im);
    }
    curl_multi_close($mh);
    return $res;
}

/** Generează o imagine tip „poster” (gradient + forme + titlu) când nu există internet. */
function demo_generate_photo(string $dest, string $hex, string $title, string $sub, int $seed, int $w = 900, int $h = 1125): bool {
    mt_srand($seed * 7919);
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    [$r, $g, $b] = demo_hex($hex);
    $palette = [[115, 87, 255], [255, 79, 163], [212, 255, 63], [76, 201, 255], [255, 138, 61]];
    [$r2, $g2, $b2] = $palette[$seed % count($palette)];

    // Gradient diagonal: culoarea categoriei → nuanță închisă
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $cr = (int)($r * (1 - $t) * .9 + 14 * $t); $cg = (int)($g * (1 - $t) * .9 + 12 * $t); $cb = (int)($b * (1 - $t) * .9 + 22 * $t);
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, max(0, $cr), max(0, $cg), max(0, $cb)));
    }
    // Pete de lumină (aurora)
    for ($k = 0; $k < 6; $k++) {
        $cx = mt_rand(-100, $w + 100); $cy = mt_rand(-100, (int)($h * .7)); $rad = mt_rand((int)($w * .35), (int)($w * .8));
        $col = imagecolorallocatealpha($im, $k % 2 ? $r2 : 255, $k % 2 ? $g2 : 255, $k % 2 ? $b2 : 255, 118);
        for ($s = $rad; $s > 0; $s -= (int)max(24, $rad / 10)) {
            imagefilledellipse($im, $cx, $cy, $s, $s, $col);
        }
    }
    $font = __DIR__ . '/assets/DejaVuSans-Bold.ttf';
    $fontR = __DIR__ . '/assets/DejaVuSans.ttf';
    $white = imagecolorallocate($im, 255, 255, 255);

    if ($sub === '') {
        // Poză de anunț: fără text jos (cardul afișează deja titlul și prețul) —
        // doar 1–2 cuvinte cheie mari, centrate, ca o „copertă".
        $words = array_map('mb_strtoupper', array_slice(preg_split('/\s+/u', trim($title)), 0, 2));
        // Zonă sigură: ~58% din lățime (cardurile verticale decupează lateralele imaginii)
        $safe = (int)($w * .58);
        $fs = (int)($w / 8);
        $fits = function (array $lines, int $size) use ($font, $safe) {
            foreach ($lines as $ln) { $bb = imagettfbbox($size, 0, $font, $ln); if ($bb[2] - $bb[0] > $safe) return false; }
            return true;
        };
        $lines = [implode(' ', $words)];
        if (!$fits($lines, $fs)) $lines = $words;               // câte un cuvânt pe rând
        while (!$fits($lines, $fs) && $fs > 22) $fs -= 3;
        $lh = (int)($fs * 1.28);
        $y0 = (int)($h * .42 - (count($lines) - 1) * $lh / 2 + $fs / 2);
        foreach ($lines as $k => $ln) {
            $bb = imagettfbbox($fs, 0, $font, $ln);
            imagettftext($im, $fs, 0, (int)(($w - ($bb[2] - $bb[0])) / 2), $y0 + $k * $lh, $white, $font, $ln);
        }
        $ly = $y0 + (count($lines) - 1) * $lh + 36;
        imagefilledrectangle($im, (int)($w / 2 - 50), $ly, (int)($w / 2 + 50), $ly + 8, imagecolorallocatealpha($im, 212, 255, 63, 10));
    } else {
        // Story: text mare + subtitlu
        $shade = imagecolorallocatealpha($im, 0, 0, 0, 70);
        imagefilledrectangle($im, 0, (int)($h * .62), $w, $h, $shade);
        $fs = (int)($w / 15);
        $y = (int)($h * .70) + $fs;
        foreach (array_slice(demo_wrap($title, $font, $fs, $w - 120), 0, 3) as $ln) {
            imagettftext($im, $fs, 0, 60, $y, $white, $font, $ln);
            $y += (int)($fs * 1.35);
        }
        imagettftext($im, (int)($w / 26), 0, 60, $y + 20, imagecolorallocate($im, 212, 255, 63), $font, $sub);
    }
    imagettftext($im, (int)($w / 42), 0, 50, 64, imagecolorallocatealpha($im, 255, 255, 255, 50), $fontR, 'imagine demo');

    $ok = imagejpeg($im, $dest, 82);
    imagedestroy($im);
    mt_srand();
    return $ok;
}

/** Avatar simplu (inițiale pe gradient) salvat ca fișier. */
function demo_avatar(string $name, int $seed): string {
    $file = bin2hex(random_bytes(12)) . '.jpg';
    $palette = ['#7357FF', '#FF4FA3', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#14B8A6'];
    [$r, $g, $b] = demo_hex($palette[$seed % count($palette)]);
    $s = 256;
    $im = imagecreatetruecolor($s, $s);
    for ($y = 0; $y < $s; $y++) {
        $t = $y / $s;
        imageline($im, 0, $y, $s, $y, imagecolorallocate($im, (int)($r * (1 - $t * .45)), (int)($g * (1 - $t * .45)), (int)($b * (1 - $t * .45))));
    }
    $ini = '';
    foreach (array_slice(preg_split('/\s+/u', trim($name)), 0, 2) as $p) $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    $font = __DIR__ . '/assets/DejaVuSans-Bold.ttf';
    $bb = imagettfbbox(90, 0, $font, $ini);
    imagettftext($im, 90, 0, (int)(($s - ($bb[2] - $bb[0])) / 2), (int)(($s + ($bb[1] - $bb[7])) / 2), imagecolorallocate($im, 255, 255, 255), $font, $ini);
    imagejpeg($im, AVATAR_PATH . $file, 86);
    imagedestroy($im);
    return $file;
}

function demo_hex(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (!ctype_xdigit($hex) || strlen($hex) !== 6) $hex = '7357FF';
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function demo_wrap(string $text, string $font, int $size, int $maxW): array {
    $lines = []; $cur = '';
    foreach (preg_split('/\s+/u', $text) as $word) {
        $try = $cur === '' ? $word : "$cur $word";
        $bb = imagettfbbox($size, 0, $font, $try);
        if ($bb[2] - $bb[0] > $maxW && $cur !== '') { $lines[] = $cur; $cur = $word; }
        else $cur = $try;
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}
