<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
@set_time_limit(300);                       // 5 min pentru upload + compresie video
@ini_set('max_execution_time', '300');

$slug = sanitize($_GET['slug'] ?? '');
$u    = current_user();
// Admin poate edita orice anunt, utilizatorul normal doar al lui
if (is_admin() || is_moderator()) {
    $ad = DB::fetch("SELECT * FROM ads WHERE slug=?", [$slug]);
} else {
    $ad = DB::fetch("SELECT * FROM ads WHERE slug=? AND user_id=?", [$slug, $u['id']]);
}
if (!$ad) { flash('error','Anunț negăsit sau acces interzis.'); redirect(is_admin() ? '/admin?s=ads' : '/cont/anunturile-mele'); }
$is_admin_edit = (is_admin() || is_moderator()) && $ad['user_id'] !== $u['id'];

$categories = DB::fetchAll("SELECT * FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order");
$photos     = DB::fetchAll("SELECT * FROM ad_photos WHERE ad_id=? ORDER BY sort_order,id", [$ad['id']]);

try {
    $existing_fields = DB::fetchAll("SELECT cf.field_name, af.field_value FROM ad_fields af JOIN category_fields cf ON cf.id=af.field_id WHERE af.ad_id=?", [$ad['id']]);
    $existing_vals   = array_column($existing_fields, 'field_value', 'field_name');
} catch (Exception $e) { $existing_vals = []; }

$user_full = DB::fetch("SELECT phone FROM users WHERE id=?", [$u['id']]);
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $vals = [
        'title'            => sanitize($_POST['title'] ?? ''),
        'category_id'      => (int)($_POST['category_id'] ?? 0),
        'description'      => sanitize_html($_POST['description'] ?? ''),
        'price'            => strlen($_POST['price'] ?? '') ? (float)$_POST['price'] : null,
        'price_negotiable' => (int)isset($_POST['price_negotiable']),
        'currency'         => in_array($_POST['currency']??'',['RON','EUR','USD']) ? $_POST['currency'] : 'RON',
        'city'             => sanitize($_POST['city'] ?? ''),
        'county'           => sanitize($_POST['county'] ?? ''),
        'contact_phone'    => sanitize($_POST['contact_phone'] ?? ''),
    ];

    if (strlen($vals['title']) < 5)       $errors[] = 'Titlul trebuie să aibă cel puțin 5 caractere.';
    if (!$vals['category_id'])             $errors[] = 'Selectează o categorie.';
    if (strlen($vals['description']) < 20) $errors[] = 'Descrierea trebuie să aibă cel puțin 20 de caractere.';
    if (!$vals['city'])                    $errors[] = 'Introdu orașul.';

    if (empty($errors)) {
        // Update phone
        if ($vals['contact_phone'] && $vals['contact_phone'] !== $user_full['phone']) {
            DB::update('users', ['phone' => $vals['contact_phone']], 'id=?', [$u['id']]);
        }

        $update_data = [
            'title'            => $vals['title'],
            'category_id'      => $vals['category_id'],
            'description'      => $vals['description'],
            'price'            => $vals['price'],
            'price_negotiable' => $vals['price_negotiable'],
            'currency'         => $vals['currency'],
            'city'             => $vals['city'],
            'county'           => $vals['county'],
            'updated_at'       => date('Y-m-d H:i:s'),
            'bumped_at'        => date('Y-m-d H:i:s'),
        ];
        // Admin poate schimba status si boost
        if (is_admin() || is_moderator()) {
            $allowed_statuses = ['active','pending','rejected','expired','sold'];
            if (isset($_POST['admin_status']) && in_array($_POST['admin_status'], $allowed_statuses)) {
                $update_data['status'] = $_POST['admin_status'];
            }
            if (!empty($_POST['admin_boost'])) {
                $boost_days = (int)$_POST['admin_boost'];
                $update_data['is_boosted'] = 1;
                $update_data['boosted_until'] = date('Y-m-d H:i:s', strtotime("+{$boost_days} days"));
            }
            // Log admin edit
            $admin_note = sanitize($_POST['admin_note'] ?? '');
            admin_log('admin_ad_edit', 'ads', $ad['id'], $admin_note ?: 'Editat ca admin');
        }
        DB::update('ads', $update_data, 'id=?', [$ad['id']]);

        // Custom fields
        try {
            $all_fields = DB::fetchAll("SELECT * FROM category_fields WHERE category_id=? AND is_active=1", [$vals['category_id']]);
            foreach ($all_fields as $cf) {
                $fval = sanitize($_POST['cf_'.$cf['field_name']] ?? '');
                if ($fval) {
                    DB::query("INSERT INTO ad_fields (ad_id, field_id, field_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE field_value=?",
                        [$ad['id'], $cf['id'], $fval, $fval]);
                } else {
                    DB::query("DELETE FROM ad_fields WHERE ad_id=? AND field_id=?", [$ad['id'], $cf['id']]);
                }
            }
        } catch (Exception $e) {}

        // Video nou
        if (!empty($_FILES['video']['name']) && !$_FILES['video']['error']) {
            $vf     = $_FILES['video'];
            $max_mb = (int)setting('max_video_size_mb','500');
            if ($vf['size'] > $max_mb * 1024 * 1024) {
                $errors[] = 'Videoclipul depășește '.$max_mb.'MB.';
            } else {
                $real_mime = real_mime($vf['tmp_name']);
                if (!allowed_video_type($real_mime)) {
                    $errors[] = 'Format video neacceptat.';
                } else {
                    if (!is_dir(VIDEO_PATH)) mkdir(VIDEO_PATH, 0755, true);
                    $fname = upload_video_secure($vf, VIDEO_PATH);
                    if ($fname) {
                        // Compresie FFmpeg (functia se ocupa de tot — vezi includes/functions.php)
                        $original_size = filesize(VIDEO_PATH . $fname);
                        $compressed = bin2hex(random_bytes(8)) . '.mp4';
                        $t0 = microtime(true);
                        $compress_ok = compress_video_ffmpeg(VIDEO_PATH . $fname, VIDEO_PATH . $compressed);
                        $compressed_size = ($compress_ok && file_exists(VIDEO_PATH . $compressed))
                                           ? filesize(VIDEO_PATH . $compressed) : 0;
                        $dt = round(microtime(true) - $t0, 1);

                        // Logging — fisier vizibil + error_log
                        $_vlog = function($msg) {
                            $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
                            @file_put_contents(__DIR__ . '/video-compress.log', $line, FILE_APPEND | LOCK_EX);
                            error_log("[veedo video-edit] $msg");
                        };
                        $_vlog("(EDIT) orig=" . number_format($original_size/1048576, 2) . "MB"
                            . " | compressed=" . number_format($compressed_size/1048576, 2) . "MB"
                            . " | ok=" . ($compress_ok?'1':'0') . " | time={$dt}s");

                        if ($compress_ok && $compressed_size > 0 && $compressed_size < $original_size) {
                            @unlink(VIDEO_PATH . $fname); // pastram compresia
                            $fname = $compressed;
                            $_vlog("(EDIT) -> USING compressed (-" . round((1 - $compressed_size/$original_size)*100) . "%)");
                        } else {
                            @unlink(VIDEO_PATH . $compressed); // pastram originalul
                            $_vlog("(EDIT) -> KEEPING original");
                        }
                        // Sterge video vechi
                        if ($ad['video_path']) @unlink(VIDEO_PATH.$ad['video_path']);
                        if ($ad['video_thumb']) @unlink(THUMB_PATH.$ad['video_thumb']);
                        $thumb_name = bin2hex(random_bytes(8)).'.jpg';
                        generate_video_thumb(VIDEO_PATH.$fname, THUMB_PATH.$thumb_name);
                        // Client thumb fallback
                        if (!file_exists(THUMB_PATH.$thumb_name) && !empty($_POST['client_thumb_data'])) {
                            $b64 = $_POST['client_thumb_data'];
                            if (preg_match('/^data:image\/jpeg;base64,/', $b64)) {
                                $img = base64_decode(substr($b64, strpos($b64,',')+1));
                                if ($img && strlen($img) > 100 && strlen($img) < 2*1024*1024) {
                                    file_put_contents(THUMB_PATH.$thumb_name, $img);
                                    if (!@getimagesize(THUMB_PATH.$thumb_name)) @unlink(THUMB_PATH.$thumb_name);
                                }
                            }
                        }
                        DB::update('ads', [
                            'video_path'     => $fname,
                            'video_thumb'    => file_exists(THUMB_PATH.$thumb_name) ? $thumb_name : null,
                            'video_duration' => get_video_duration(VIDEO_PATH.$fname),
                        ], 'id=?', [$ad['id']]);
                    }
                }
            }
        }

        // Sterge video
        if (!empty($_POST['delete_video']) && $ad['video_path']) {
            @unlink(VIDEO_PATH.$ad['video_path']);
            if ($ad['video_thumb']) @unlink(THUMB_PATH.$ad['video_thumb']);
            DB::update('ads', ['video_path'=>null,'video_thumb'=>null,'video_duration'=>0], 'id=?', [$ad['id']]);
        }

        // Reordonare poze (drag & drop order din JS)
        if (!empty($_POST['photos_order'])) {
            $order = array_map('intval', explode(',', $_POST['photos_order']));
            foreach ($order as $i => $pid) {
                DB::query("UPDATE ad_photos SET sort_order=? WHERE id=? AND ad_id=?", [$i, $pid, $ad['id']]);
            }
        }

        // Set primary
        if (!empty($_POST['primary_photo'])) {
            $pid = (int)$_POST['primary_photo'];
            DB::query("UPDATE ad_photos SET is_primary=0 WHERE ad_id=?", [$ad['id']]);
            DB::query("UPDATE ad_photos SET is_primary=1 WHERE id=? AND ad_id=?", [$pid, $ad['id']]);
        }

        // Sterge poze selectate
        if (!empty($_POST['delete_photos'])) {
            foreach ((array)$_POST['delete_photos'] as $pid) {
                $p = DB::fetch("SELECT path FROM ad_photos WHERE id=? AND ad_id=?", [(int)$pid, $ad['id']]);
                if ($p) { @unlink(PHOTO_PATH.$p['path']); DB::query("DELETE FROM ad_photos WHERE id=?", [(int)$pid]); }
            }
        }

        // Adauga poze noi
        if (!empty($_FILES['photos']['name'][0]) && !$_FILES['photos']['error'][0]) {
            if (!is_dir(PHOTO_PATH)) mkdir(PHOTO_PATH, 0755, true);
            $remaining = max(0, (int)setting('max_photos','10') - count($photos));
            foreach (array_slice($_FILES['photos']['tmp_name'], 0, $remaining) as $i => $tmp) {
                if (!$_FILES['photos']['error'][$i]) {
                    $file  = ['tmp_name'=>$tmp,'type'=>$_FILES['photos']['type'][$i],'name'=>$_FILES['photos']['name'][$i]];
                    $pname = upload_photo($file, PHOTO_PATH);
                    if ($pname) DB::insert('ad_photos', ['ad_id'=>$ad['id'],'path'=>$pname,'sort_order'=>count($photos)+$i,'is_primary'=>0]);
                }
            }
        }

        if (empty($errors)) {
            flash('success','Anunțul a fost actualizat!');
            if ($is_admin_edit ?? false) {
                redirect('/admin?s=ads&flash=updated');
            } else {
                redirect('/anunt/'.$ad['slug']);
            }
        }
    }
}

$seo_title = 'Editează: ' . $ad['title'];
include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Grid poze reordonabile ─── */
.photos-edit-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px,1fr));
    gap: 10px;
    margin-top: 10px;
}
.photo-edit-item {
    position: relative;
    border-radius: 10px;
    overflow: hidden;
    border: 2.5px solid var(--border);
    cursor: grab;
    transition: all 0.2s;
    aspect-ratio: 1;
    background: var(--bg-3);
}
.photo-edit-item.primary { border-color: var(--brand); }
.photo-edit-item.dragging { opacity: 0.4; border-style: dashed; cursor: grabbing; }
.photo-edit-item.drag-over { border-color: var(--brand); transform: scale(1.04); box-shadow: 0 4px 16px rgba(232,56,13,0.2); }
.photo-edit-item.marked-delete { opacity: 0.3; border-color: #EF4444; }
.photo-edit-item img { width:100%;height:100%;object-fit:cover;display:block;pointer-events:none; }
.photo-edit-badge {
    position: absolute; top: 4px; left: 4px;
    background: var(--brand); color: #fff;
    font-size: 9px; font-weight: 700;
    padding: 2px 6px; border-radius: 4px;
    pointer-events: none;
}
.photo-edit-actions {
    position: absolute; bottom: 0; left: 0; right: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.75) 0%, transparent 100%);
    padding: 20px 6px 6px;
    display: flex; gap: 4px; justify-content: center;
}
.photo-edit-btn {
    border: none; border-radius: 4px;
    font-size: 10px; padding: 3px 7px;
    cursor: pointer; font-weight: 600;
}

/* ── Video zone ── */
.video-edit-zone { cursor: pointer; }
</style>

<div class="container" style="padding:24px 20px 40px;max-width:820px;">

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <a href="/anunt/<?= h($ad['slug']) ?>" class="btn btn-secondary btn-sm">← Înapoi</a>
        <h1 style="margin:0;font-size:1.2rem;font-weight:800;">✏️ Editează anunțul</h1>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;">
        <?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="edit-form" novalidate>
        <?= csrf_field() ?>
        <!-- Ordine poze (actualizata de JS la drag) -->
        <input type="hidden" name="photos_order" id="photos-order-input">

        <!-- ── VIDEO ──────────────────────────────────────── -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:14px;">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;">🎬 Video</h2>

            <?php if (!empty($ad['video_path'])): ?>
            <!-- Video existent -->
            <div style="position:relative;border-radius:10px;overflow:hidden;background:#000;max-height:300px;margin-bottom:12px;" id="existing-video-wrap">
                <video style="width:100%;max-height:300px;object-fit:contain;display:block;" controls muted preload="metadata">
                    <source src="<?= h(BASE_URL.'/uploads/videos/'.$ad['video_path']) ?>" type="video/mp4">
                </video>
                <?php if($ad['video_duration']): ?>
                <div style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,0.65);color:#fff;font-size:11px;padding:2px 8px;border-radius:4px;">
                    ⏱ <?= gmdate('i:s', $ad['video_duration']) ?>
                </div>
                <?php endif; ?>
            </div>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#EF4444;margin-bottom:10px;">
                <input type="checkbox" name="delete_video" value="1" id="delete-video"
                       onchange="document.getElementById('existing-video-wrap').style.opacity=this.checked?'0.3':'1'">
                🗑 Șterge videoclipul actual
            </label>
            <div style="font-size:12px;color:var(--text-3);margin-bottom:12px;">sau înlocuiește cu unul nou:</div>
            <?php else: ?>
            <p style="font-size:13px;color:var(--text-3);margin-bottom:14px;">
                Anunțurile cu video primesc de <strong style="color:var(--brand);">3×</strong> mai multe vizualizări!
            </p>
            <?php endif; ?>

            <!-- Upload video nou -->
            <input type="file" name="video" id="video-input" accept="video/mp4,video/webm,video/quicktime" style="display:none;">
            <div class="upload-zone video-edit-zone" style="padding:16px;cursor:pointer;" id="video-zone">
                <div style="font-size:1.5rem;">🎬</div>
                <p style="font-size:13px;color:var(--text-3);margin-top:4px;" id="video-label">
                    Apasă pentru a selecta · MP4, WebM, MOV · Max <?= setting('max_video_size_mb','500') ?>MB
                </p>
            </div>
            <div id="video-preview-wrap" style="display:none;margin-top:10px;">
                <div style="position:relative;border-radius:10px;overflow:hidden;background:#000;max-height:280px;">
                    <video id="video-preview" controls playsinline style="width:100%;max-height:280px;object-fit:contain;display:block;"></video>
                    <button type="button" onclick="clearNewVideo()"
                            style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,0.7);color:#fff;border:none;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:12px;">
                        ✕ Anulează
                    </button>
                </div>
                <div style="margin-top:6px;font-size:12px;color:#22C55E;font-weight:600;" id="video-info"></div>
            </div>
        </div>

        <!-- ── FOTOGRAFII EXISTENTE ──────────────────────── -->
        <?php if (!empty($photos)): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                <h2 style="font-size:1rem;font-weight:700;">📸 Fotografii (<?= count($photos) ?>)</h2>
                <span style="font-size:12px;color:var(--text-3);">Trage pentru reordonare</span>
            </div>
            <p style="font-size:12px;color:var(--text-3);margin-bottom:12px;">
                Prima din grid e principala · ★ setează principala · 🗑 marchează pentru ștergere
            </p>
            <div class="photos-edit-grid" id="photos-grid">
                <?php foreach ($photos as $p): ?>
                <div class="photo-edit-item <?= $p['is_primary'] ? 'primary' : '' ?>"
                     data-id="<?= $p['id'] ?>" draggable="true">
                    <img src="<?= h(photo_url($p['path'])) ?>" alt="">
                    <?php if ($p['is_primary']): ?>
                    <div class="photo-edit-badge">Principal</div>
                    <?php endif; ?>
                    <div class="photo-edit-actions">
                        <button type="button" class="photo-edit-btn"
                                style="background:var(--brand);color:#fff;"
                                onclick="setPrimary(<?= $p['id'] ?>)" title="Setează principală">★</button>
                        <button type="button" class="photo-edit-btn"
                                style="background:rgba(239,68,68,0.9);color:#fff;"
                                onclick="toggleDelete(this, <?= $p['id'] ?>)" title="Șterge">🗑</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Inputs ascunse pentru delete si primary -->
            <div id="delete-inputs"></div>
            <input type="hidden" name="primary_photo" id="primary-photo-input" value="">
        </div>
        <?php endif; ?>

        <!-- ── ADAUGĂ POZE NOI ───────────────────────────── -->
        <?php $remaining = max(0, (int)setting('max_photos','10') - count($photos)); ?>
        <?php if ($remaining > 0): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:14px;">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:10px;">➕ Adaugă fotografii noi
                <span style="font-size:12px;font-weight:400;color:var(--text-3);">(max <?= $remaining ?> rămase)</span>
            </h2>
            <input type="file" name="photos[]" id="photos-new-input"
                   accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
            <div class="upload-zone" style="padding:16px;cursor:pointer;" id="photos-new-zone">
                <div style="font-size:1.5rem;">📷</div>
                <p style="font-size:13px;color:var(--text-3);margin-top:4px;">JPEG, PNG, WebP</p>
            </div>
            <div id="new-photos-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;"></div>
        </div>
        <?php endif; ?>

        <!-- ── DETALII ────────────────────────────────────── -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:14px;">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;">📋 Detalii anunț</h2>

            <div class="form-group">
                <label class="form-label">Titlu *</label>
                <input type="text" name="title" class="form-control" value="<?= h($ad['title']) ?>" required maxlength="250">
            </div>

            <div class="form-group">
                <label class="form-label">Categorie *</label>
                <select name="category_id" id="category_id" class="form-control" required onchange="loadCategoryFields(this.value)">
                    <option value="">— Selectează —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $ad['category_id']==$cat['id']?'selected':'' ?>>
                        <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="category-fields-wrap" style="display:none;">
                <hr style="border:none;border-top:1px solid var(--border-2);margin:16px 0;">
                <div id="category-fields-container"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Descriere *</label>
                <textarea name="description" id="description-field" class="form-control" rows="7" required><?= h($ad['description']) ?></textarea>
                <div style="display:flex;justify-content:space-between;margin-top:4px;">
                    <span class="form-hint">Minim 20 caractere</span>
                    <span style="font-size:12px;color:var(--text-3);" id="desc-counter"><?= strlen($ad['description']) ?> caractere</span>
                </div>
            </div>
        </div>

        <!-- ── PREȚ ──────────────────────────────────────── -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:14px;">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;">💰 Preț</h2>
            <div style="display:grid;grid-template-columns:1fr 110px;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Preț</label>
                    <input type="number" name="price" class="form-control" value="<?= h($ad['price']) ?>" placeholder="Preț la cerere" min="0">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Monedă</label>
                    <select name="currency" class="form-control">
                        <option value="RON" <?= $ad['currency']==='RON'?'selected':'' ?>>RON</option>
                        <option value="EUR" <?= $ad['currency']==='EUR'?'selected':'' ?>>EUR</option>
                        <option value="USD" <?= $ad['currency']==='USD'?'selected':'' ?>>USD</option>
                    </select>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                <input type="checkbox" name="price_negotiable" value="1" <?= $ad['price_negotiable']?'checked':'' ?>>
                Preț negociabil
            </label>
        </div>

        <!-- ── LOCAȚIE ────────────────────────────────────── -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:14px;">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;">📍 Locație</h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Oraș *</label>
                    <input type="text" name="city" class="form-control" value="<?= h($ad['city']) ?>" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Județ</label>
                    <input type="text" name="county" class="form-control" value="<?= h($ad['county']) ?>">
                </div>
            </div>
        </div>

        <!-- ── CONTACT ────────────────────────────────────── -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:24px;">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:16px;">📞 Contact</h2>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Număr de telefon</label>
                <input type="tel" name="contact_phone" class="form-control"
                       value="<?= h($user_full['phone'] ?? '') ?>" placeholder="07xx xxx xxx">
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;">
            <a href="/anunt/<?= h($ad['slug']) ?>" class="btn btn-secondary">Anulează</a>
            <button type="submit" class="btn btn-primary btn-lg">💾 Salvează</button>
        </div>
    </form>
</div>

<script>
// ── Category fields ───────────────────────────────────────
const existingVals = <?= json_encode($existing_vals) ?>;
<?php
try {
    $ef = DB::fetchAll("SELECT * FROM category_fields WHERE is_active=1 ORDER BY category_id, sort_order");
    $eg = array_reduce($ef, function($c,$f){ $f['options']=$f['field_options']?json_decode($f['field_options'],true):[]; $c[$f['category_id']][]=$f; return $c; }, []);
} catch(Exception $e){ $eg=[]; }
?>
const allCatFields = <?= json_encode($eg) ?>;

function loadCategoryFields(catId) {
    const wrap = document.getElementById('category-fields-wrap');
    const cont = document.getElementById('category-fields-container');
    const fields = allCatFields[catId] || [];
    if (!fields.length) { wrap.style.display='none'; return; }
    wrap.style.display = 'block';
    cont.innerHTML = fields.map(f => {
        const val  = existingVals[f.field_name] || '';
        const req  = f.is_required ? ' *' : '';
        const unit = f.field_unit ? `<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px;pointer-events:none">${f.field_unit}</span>` : '';
        const up   = f.field_unit ? 'padding-right:50px;' : '';
        let inp = '';
        if (f.field_type === 'select') {
            inp = `<select name="cf_${f.field_name}" class="form-control" ${f.is_required?'required':''}><option value="">— Selectează —</option>${(f.options||[]).map(o=>`<option value="${o}" ${o===val?'selected':''}>${o}</option>`).join('')}</select>`;
        } else if (f.field_type === 'number') {
            inp = `<div style="position:relative">${unit}<input type="number" name="cf_${f.field_name}" class="form-control" value="${val}" style="${up}" ${f.is_required?'required':''}></div>`;
        } else {
            inp = `<input type="text" name="cf_${f.field_name}" class="form-control" value="${val}" ${f.is_required?'required':''}>`;
        }
        return `<div class="form-group"><label class="form-label">${f.field_label}${req}</label>${inp}</div>`;
    }).join('');
}
loadCategoryFields(document.getElementById('category_id').value);

// ── Description counter ───────────────────────────────────
const descArea = document.getElementById('description-field');
const descCtr  = document.getElementById('desc-counter');
if (descArea) descArea.addEventListener('input', () => descCtr.textContent = descArea.value.length + ' caractere');

// ── Video nou ─────────────────────────────────────────────
const videoInput   = document.getElementById('video-input');
const videoZone    = document.getElementById('video-zone');
const videoPrev    = document.getElementById('video-preview-wrap');
const videoPreview = document.getElementById('video-preview');

// Click pe zona - flag care previne dubla deschidere
let _picking = false;
videoZone?.addEventListener('click', () => {
    if (_picking) return;
    _picking = true;
    videoInput.click();
    // Resetam dupa 2 secunde (timp suficient pentru orice browser)
    setTimeout(() => { _picking = false; }, 2000);
});

videoInput?.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const maxMb = <?= (int)setting('max_video_size_mb','500') ?>;
    if (file.size > maxMb * 1024 * 1024) {
        alert('Videoclipul depășește ' + maxMb + 'MB!');
        this.value = ''; return;
    }
    videoZone.style.display = 'none';
    videoPreview.src = URL.createObjectURL(file);
    document.getElementById('video-info').textContent =
        '✓ ' + file.name + ' (' + (file.size/1024/1024).toFixed(1) + ' MB) — va fi comprimat și înlocuit la salvare';
    videoPrev.style.display = 'block';
    // Auto-uncheck delete
    const del = document.getElementById('delete-video');
    if (del) del.checked = false;
    // Canvas thumb
    captureThumb(file);
});

function clearNewVideo() {
    videoInput.value = '';
    videoPreview.src = '';
    videoPrev.style.display = 'none';
    videoZone.style.display = '';
}

function captureThumb(file) {
    const v = document.createElement('video');
    v.muted = true; v.playsInline = true; v.src = URL.createObjectURL(file);
    v.addEventListener('loadeddata', () => { v.currentTime = 1; });
    v.addEventListener('seeked', function() {
        const c = document.createElement('canvas');
        c.width  = Math.min(v.videoWidth, 640);
        c.height = Math.round(c.width * v.videoHeight / v.videoWidth);
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        let inp = document.getElementById('client_thumb_data');
        if (!inp) { inp = document.createElement('input'); inp.type='hidden'; inp.name='client_thumb_data'; inp.id='client_thumb_data'; document.getElementById('edit-form').appendChild(inp); }
        inp.value = c.toDataURL('image/jpeg', 0.8);
        URL.revokeObjectURL(v.src);
    }, { once: true });
}

// ── Poze existente — drag & drop reordonare ───────────────
const grid      = document.getElementById('photos-grid');
const orderInp  = document.getElementById('photos-order-input');
const deleteInp = document.getElementById('delete-inputs');
const primaryInp= document.getElementById('primary-photo-input');

let deleteSet   = new Set();
let primaryId   = <?= !empty($photos) ? array_values(array_filter($photos, fn($p) => $p['is_primary']))[0]['id'] ?? 0 : 0 ?>;

function updateOrderInput() {
    if (!grid) return;
    const ids = [...grid.querySelectorAll('.photo-edit-item')].map(el => el.dataset.id);
    orderInp.value = ids.join(',');
}

function setPrimary(id) {
    primaryId = id;
    primaryInp.value = id;
    grid.querySelectorAll('.photo-edit-item').forEach(el => {
        const isPrim = +el.dataset.id === id;
        el.classList.toggle('primary', isPrim);
        const badge = el.querySelector('.photo-edit-badge');
        if (isPrim && !badge) {
            const b = document.createElement('div');
            b.className = 'photo-edit-badge'; b.textContent = 'Principal';
            el.appendChild(b);
        } else if (!isPrim && badge) badge.remove();
    });
}

function toggleDelete(btn, id) {
    const item = btn.closest('.photo-edit-item');
    if (deleteSet.has(id)) {
        deleteSet.delete(id);
        item.classList.remove('marked-delete');
        btn.textContent = '🗑';
        btn.style.background = 'rgba(239,68,68,0.9)';
    } else {
        deleteSet.add(id);
        item.classList.add('marked-delete');
        btn.textContent = '↩';
        btn.style.background = 'rgba(100,100,100,0.9)';
    }
    // Actualizare hidden inputs
    deleteInp.innerHTML = [...deleteSet].map(i => `<input type="hidden" name="delete_photos[]" value="${i}">`).join('');
}

// Drag events pe grid
if (grid) {
    grid.addEventListener('dragstart', e => {
        const item = e.target.closest('.photo-edit-item');
        if (item) { item.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
    });
    grid.addEventListener('dragend', e => {
        const item = e.target.closest('.photo-edit-item');
        if (item) { item.classList.remove('dragging'); updateOrderInput(); }
    });
    grid.addEventListener('dragover', e => {
        e.preventDefault();
        const dragging = grid.querySelector('.dragging');
        const target   = e.target.closest('.photo-edit-item');
        if (target && target !== dragging) {
            grid.querySelectorAll('.photo-edit-item').forEach(el => el.classList.remove('drag-over'));
            target.classList.add('drag-over');
            const rect = target.getBoundingClientRect();
            const after = e.clientX > rect.left + rect.width / 2;
            grid.insertBefore(dragging, after ? target.nextSibling : target);
        }
    });
    grid.addEventListener('dragleave', e => {
        const item = e.target.closest('.photo-edit-item');
        if (item) item.classList.remove('drag-over');
    });
    grid.addEventListener('drop', e => {
        e.preventDefault();
        grid.querySelectorAll('.photo-edit-item').forEach(el => el.classList.remove('drag-over'));
        updateOrderInput();
    });
    updateOrderInput();
}

// ── Poze noi preview ──────────────────────────────────────
// Photos new zone click
let _pickingPhotoNew = false;
document.getElementById('photos-new-zone')?.addEventListener('click', () => {
    if (_pickingPhotoNew) return;
    _pickingPhotoNew = true;
    document.getElementById('photos-new-input').click();
    setTimeout(() => { _pickingPhotoNew = false; }, 2000);
});
document.getElementById('photos-new-input')?.addEventListener('change', function() {
    previewNewPhotos(this);
});

function previewNewPhotos(input) {
    const prev = document.getElementById('new-photos-preview');
    prev.innerHTML = '';
    Array.from(input.files).forEach((file, i) => {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.style.cssText = 'position:relative;width:80px;height:80px;';
            div.innerHTML = `<img src="${e.target.result}" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border);">
                ${i===0?'<span style="position:absolute;top:3px;left:3px;background:var(--brand);color:#fff;font-size:9px;padding:1px 5px;border-radius:4px;">Nou</span>':''}`;
            prev.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
}

// ── Upload progress ───────────────────────────────────────
document.getElementById('edit-form')?.addEventListener('submit', function(e) {
    const vi = document.getElementById('video-input');
    if (!vi?.files.length) return;
    e.preventDefault();

    const pb = document.createElement('div');
    pb.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px 20px;width:300px;box-shadow:0 8px 32px rgba(0,0,0,0.15);';
    pb.innerHTML = `<div style="font-weight:700;margin-bottom:10px;" id="pb-t">⬆️ Se încarcă videoclipul...</div>
        <div style="background:var(--bg-3);border-radius:100px;height:8px;overflow:hidden;margin-bottom:8px;">
            <div id="pb-b" style="height:100%;width:0%;background:var(--brand);border-radius:100px;transition:width 0.3s;"></div>
        </div>
        <div id="pb-i" style="font-size:12px;color:var(--text-3);text-align:center;"></div>`;
    document.body.appendChild(pb);

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
        if (!e.lengthComputable) return;
        const pct = Math.round(e.loaded/e.total*100);
        document.getElementById('pb-b').style.width = pct + '%';
        document.getElementById('pb-i').textContent = pct + '% · ' + (e.loaded/1024/1024).toFixed(1) + '/' + (e.total/1024/1024).toFixed(1) + 'MB';
        if (pct === 100) document.getElementById('pb-t').textContent = '⚙️ Se procesează...';
    });
    xhr.addEventListener('load', () => {
        if (xhr.responseURL && xhr.responseURL !== location.href) location.href = xhr.responseURL;
        else { document.open(); document.write(xhr.responseText); document.close(); }
    });
    xhr.addEventListener('error', () => { alert('Eroare upload.'); pb.remove(); });
    xhr.open('POST', this.action || location.href);
    xhr.send(new FormData(this));
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>