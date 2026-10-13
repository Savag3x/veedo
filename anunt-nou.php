<?php
require_once __DIR__ . '/includes/bootstrap.php';
@set_time_limit(300);                       // 5 min pentru upload + compresie video
@ini_set('max_execution_time', '300');
require_login();

$u = current_user();
$categories = DB::fetchAll("SELECT * FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order");

$errors = [];
$vals   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $errors[] = 'Eroare de securitate.'; }
    else {
        $vals = [
            'title'            => mb_substr(sanitize($_POST['title'] ?? ''), 0, 150),
            'category_id'      => (int)($_POST['category_id'] ?? 0),
            'description'      => mb_substr(sanitize_html($_POST['description'] ?? ''), 0, 9000),
            'price'            => strlen($_POST['price'] ?? '') ? max(0, min(9999999999, (float)$_POST['price'])) : null,
            'price_negotiable' => (int)isset($_POST['price_negotiable']),
            'currency'         => in_array($_POST['currency']??'',['RON','EUR','USD']) ? $_POST['currency'] : 'RON',
            'city'             => mb_substr(sanitize($_POST['city'] ?? ''), 0, 100),
            'county'           => mb_substr(sanitize($_POST['county'] ?? ''), 0, 100),
            'contact_phone'    => clean_phone($_POST['contact_phone'] ?? ''),
        ];

        // Anti-spam: max 10 anunțuri/oră + limita totală de anunțuri active per utilizator
        if (!rate_limit('post_ad', 10, 3600)) $errors[] = 'Ai postat prea multe anunțuri într-un timp scurt. Încearcă mai târziu.';
        $free_limit = (int)setting('free_ads_per_user', '100');
        if ($free_limit > 0 && !is_moderator()) {
            $active_cnt = (int)(DB::fetch("SELECT COUNT(*) c FROM ads WHERE user_id=? AND status IN ('active','pending')", [$u['id']])['c'] ?? 0);
            if ($active_cnt >= $free_limit) $errors[] = "Ai atins limita de {$free_limit} anunțuri active.";
        }

        if (strlen($vals['title']) < (int)setting('min_ad_title_length','10')) {
            $errors[] = 'Titlul trebuie să aibă cel puțin '.(int)setting('min_ad_title_length','10').' caractere.';
        }
        if (!$vals['category_id'] || !DB::fetch("SELECT id FROM categories WHERE id=? AND is_active=1", [$vals['category_id']])) $errors[] = 'Selectează o categorie.';
        if (strlen($vals['description']) < (int)setting('min_ad_description_length','20')) {
            $errors[] = 'Descrierea trebuie să aibă cel puțin '.(int)setting('min_ad_description_length','20').' de caractere.';
        }
        if (!$vals['city']) $errors[] = 'Introdu orașul.';

        // Validate required custom fields (safe if tables missing)
        try {
            $cat_fields = DB::fetchAll("SELECT * FROM category_fields WHERE category_id=? AND is_active=1 AND is_required=1 ORDER BY sort_order", [$vals['category_id']]);
            foreach ($cat_fields as $cf) {
                $fval = sanitize($_POST['cf_'.$cf['field_name']] ?? '');
                if (!$fval) $errors[] = $cf['field_label'] . ' este obligatoriu.';
            }
        } catch (Exception $e) { /* tables not yet created */ }

        // Video upload - SECURIZAT: verificare MIME real + extensie sigură
        $video_path = $video_thumb = null;
        $video_duration = 0;
        if (!empty($_FILES['video']['name']) && !$_FILES['video']['error']) {
            $vf = $_FILES['video'];
            $max_mb = (int)setting('max_video_size_mb','500');
            if ($vf['size'] > $max_mb * 1024 * 1024) {
                $errors[] = "Videoclipul depășește {$max_mb} MB.";
            } else {
                // Verifică MIME-ul REAL (nu cel declarat de browser)
                $real_video_mime = real_mime($vf['tmp_name']);
                if (!allowed_video_type($real_video_mime)) {
                    $errors[] = 'Format video neacceptat. Folosește MP4 sau WebM.';
                } else {
                    if (!is_dir(VIDEO_PATH)) mkdir(VIDEO_PATH, 0755, true);
                    if (!is_dir(THUMB_PATH)) mkdir(THUMB_PATH, 0755, true);
                    // Folosim upload_video_secure - extensie din MIME, nu din filename
                    $fname = upload_video_secure($vf, VIDEO_PATH);
                    if ($fname) {
                        // Compresie server-side cu FFmpeg
                        $original_size = filesize(VIDEO_PATH . $fname);
                        $compressed_name = bin2hex(random_bytes(8)) . '.mp4';
                        $t0 = microtime(true);
                        $compress_ok = compress_video_ffmpeg(VIDEO_PATH . $fname, VIDEO_PATH . $compressed_name);
                        $compressed_size = ($compress_ok && file_exists(VIDEO_PATH . $compressed_name))
                                           ? filesize(VIDEO_PATH . $compressed_name) : 0;
                        $dt = round(microtime(true) - $t0, 1);

                        // Logging — scriem si in fisier vizibil + error_log
                        $_vlog = function($msg) {
                            $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
                            error_log("[veedo video] $msg");
                        };
                        $ff = find_ffmpeg_binary('ffmpeg');
                        $_vlog("ffmpeg=" . ($ff ?: 'NU EXISTĂ')
                            . " | orig=" . number_format($original_size/1048576, 2) . "MB"
                            . " | compressed=" . number_format($compressed_size/1048576, 2) . "MB"
                            . " | ok=" . ($compress_ok?'1':'0') . " | time={$dt}s");

                        if ($compress_ok && $compressed_size > 0 && $compressed_size < $original_size) {
                            @unlink(VIDEO_PATH . $fname); // pastram compresia
                            $fname = $compressed_name;
                            $_vlog("-> USING compressed (-" . round((1 - $compressed_size/$original_size)*100) . "%)");
                        } else {
                            @unlink(VIDEO_PATH . $compressed_name); // pastram originalul
                            $_vlog("-> KEEPING original");
                        }
                        $video_path = $fname;
                        $thumb_name = bin2hex(random_bytes(8)).'.jpg';
                        if (generate_video_thumb(VIDEO_PATH.$fname, THUMB_PATH.$thumb_name)) {
                            $video_thumb = $thumb_name;
                        }
                        $video_duration = get_video_duration(VIDEO_PATH.$fname);

                        // Dacă ffmpeg nu a generat thumb (shared hosting),
                        // folosim thumb-ul capturat de browser via canvas
                        if (!$video_thumb && !empty($_POST['client_thumb_data'])) {
                            $b64 = $_POST['client_thumb_data'];
                            if (preg_match('/^data:image\/jpeg;base64,/', $b64)) {
                                $img_data = base64_decode(substr($b64, strpos($b64, ',') + 1));
                                if ($img_data && strlen($img_data) > 100 && strlen($img_data) < 2 * 1024 * 1024) {
                                    $client_thumb = bin2hex(random_bytes(8)) . '_ct.jpg';
                                    if (!is_dir(THUMB_PATH)) mkdir(THUMB_PATH, 0755, true);
                                    if (file_put_contents(THUMB_PATH . $client_thumb, $img_data)) {
                                        // Verifică că e o imagine validă
                                        if (@getimagesize(THUMB_PATH . $client_thumb)) {
                                            $video_thumb = $client_thumb;
                                        } else {
                                            @unlink(THUMB_PATH . $client_thumb);
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $errors[] = 'Eroare la încărcarea videoclipului.';
                    }
                }
            }
        }

        if (empty($errors)) {
            $slug = unique_slug($vals['title']);
            $days = (int)setting('anunt_expiry_days','60');
            // Aprobare automată dacă setarea e activă, sau dacă userul e moderator
            $auto_approve = setting_bool('ad_auto_approve');
            $status = (is_moderator() || $auto_approve) ? 'active' : 'pending';

            // Update user phone if provided and different
            if ($vals['contact_phone'] && $vals['contact_phone'] !== $u['phone']) {
                DB::update('users', ['phone' => $vals['contact_phone']], 'id=?', [$u['id']]);
            }

            $ad_id = DB::insert('ads', [
                'user_id'          => $u['id'],
                'category_id'      => $vals['category_id'],
                'title'            => $vals['title'],
                'slug'             => $slug,
                'description'      => $vals['description'],
                'price'            => $vals['price'],
                'price_negotiable' => $vals['price_negotiable'],
                'currency'         => $vals['currency'],
                'city'             => $vals['city'],
                'county'           => $vals['county'],
                'video_path'       => $video_path,
                'video_thumb'      => $video_thumb,
                'video_duration'   => $video_duration,
                'status'           => $status,
                'expires_at'       => date('Y-m-d', strtotime("+{$days} days")),
            ]);

            // Save custom fields (safe if tables not yet created)
            try {
                $all_fields = DB::fetchAll("SELECT * FROM category_fields WHERE category_id=? AND is_active=1", [$vals['category_id']]);
                foreach ($all_fields as $cf) {
                    $fval = mb_substr(sanitize((string)($_POST['cf_'.$cf['field_name']] ?? '')), 0, 255);
                    if ($fval) {
                        DB::query("INSERT INTO ad_fields (ad_id, field_id, field_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE field_value=?",
                            [$ad_id, $cf['id'], $fval, $fval]);
                    }
                }
            } catch (Exception $e) { /* tables not yet created */ }

            // Photos
            if (!empty($_FILES['photos']['name'][0])) {
                if (!is_dir(PHOTO_PATH)) mkdir(PHOTO_PATH,0755,true);
                $max = (int)setting('max_photos','10');
                foreach (array_slice($_FILES['photos']['tmp_name'],0,$max) as $i=>$tmp) {
                    if (!$_FILES['photos']['error'][$i]) {
                        $file = ['tmp_name'=>$tmp,'type'=>$_FILES['photos']['type'][$i],'name'=>$_FILES['photos']['name'][$i]];
                        $pname = upload_photo($file, PHOTO_PATH);
                        if ($pname) DB::insert('ad_photos',['ad_id'=>$ad_id,'path'=>$pname,'sort_order'=>$i,'is_primary'=>($i===0)?1:0]);
                    }
                }
            }

            DB::query("UPDATE users SET ads_count=ads_count+1 WHERE id=?", [$u['id']]);

            if ($status === 'pending') {
                flash('success','Anunțul a fost trimis spre aprobare!');
                redirect('/cont/anunturile-mele');
            } else {
                flash('success','Anunțul a fost publicat!');
                redirect('/anunt/'.$slug);
            }
        }
    }
}

// Get user's phone for pre-fill
$user_full = DB::fetch("SELECT phone FROM users WHERE id=?", [$u['id']]);

$seo_title = 'Postează anunț video';
include __DIR__ . '/includes/header.php';
?>

<style>
/* Stepper */
.post-stepper {
    display: flex; align-items: center;
    margin-bottom: 28px; gap: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px; padding: 16px 20px;
    overflow-x: auto; scrollbar-width: none;
}
.post-stepper::-webkit-scrollbar { display: none; }
.step {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; color: var(--text-3); font-size: 13px;
}
.step-num {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--bg-3); color: var(--text-3);
    font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.step.active .step-num { background: var(--brand); color: #fff; }
.step.active { color: var(--text); font-weight: 600; }
.step.done .step-num { background: #22C55E; color: #fff; }
.step-sep { flex: 1; min-width: 20px; height: 2px; background: var(--border-2); margin: 0 8px; }

/* Photo grid reorder */
.photos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 8px; margin-top: 12px;
}
.photo-thumb {
    position: relative; aspect-ratio: 1;
    border-radius: 8px; overflow: hidden;
    border: 2px solid var(--border);
    cursor: grab; transition: all 0.2s;
}
.photo-thumb:first-child { border-color: var(--brand); }
.photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-thumb .primary-badge {
    position: absolute; top: 3px; left: 3px;
    background: var(--brand); color: #fff;
    font-size: 9px; padding: 1px 5px; border-radius: 4px;
}
.photo-thumb .remove-photo {
    position: absolute; top: 3px; right: 3px;
    background: rgba(0,0,0,0.6); color: #fff;
    border: none; width: 18px; height: 18px;
    border-radius: 50%; cursor: pointer; font-size: 10px;
    display: flex; align-items: center; justify-content: center;
}
.photo-thumb.dragging { opacity: 0.4; border-style: dashed; }
.photo-thumb.drag-over { border-color: var(--brand); transform: scale(1.05); }

/* Live preview card */
.preview-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px;
    position: sticky; top: 76px;
}

/* Video zone imbunatatit */
#video-zone { cursor: pointer; }
#video-zone.has-video { padding: 0; border: none; background: none; }

@media(max-width:600px) {
    .post-stepper { padding: 12px 14px; }
    .step-label { display: none; }
}
</style>

<div class="container" style="padding:24px 20px 40px;max-width:820px;">

  <!-- Stepper -->
  <div class="post-stepper" id="stepper">
    <div class="step active" data-step="1">
        <div class="step-num">1</div>
        <span class="step-label">Media</span>
    </div>
    <div class="step-sep"></div>
    <div class="step" data-step="2">
        <div class="step-num">2</div>
        <span class="step-label">Detalii</span>
    </div>
    <div class="step-sep"></div>
    <div class="step" data-step="3">
        <div class="step-num">3</div>
        <span class="step-label">Preț</span>
    </div>
    <div class="step-sep"></div>
    <div class="step" data-step="4">
        <div class="step-num">4</div>
        <span class="step-label">Contact</span>
    </div>
  </div>

  <h1 style="margin-bottom:6px;font-size:1.3rem;">Postează anunț</h1>
  <p style="color:var(--text-3);margin-bottom:24px;font-size:14px;">Anunțurile cu video primesc de <strong style="color:var(--brand);">3×</strong> mai multe vizualizări!</p>

  <?php if ($errors): ?>
  <div class="alert alert-error" style="margin-bottom:20px;">
    <?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="post-form">
    <?= csrf_field() ?>

    <!-- 1. VIDEO -->
    <div id="video-section">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:16px;">
      <h2 style="font-size:1rem;margin-bottom:6px;">🎬 Video anunț <span style="font-size:13px;font-weight:400;color:var(--text-3);">— recomandat, dar opțional</span></h2>
      <p style="font-size:13px;color:var(--text-3);margin-bottom:16px;">MP4, WebM, MOV · Max <?= setting('max_video_size_mb','500') ?>MB · Max <?= (int)(setting('max_video_duration_sec','300')/60) ?> minute</p>
      <input type="file" name="video" id="video-input" accept="video/mp4,video/webm,video/quicktime" style="display:none;">
      <div class="upload-zone" id="video-zone" style="cursor:pointer;">
        <div class="upload-icon" id="video-icon">🎥</div>
        <h3 style="font-size:15px;margin-bottom:4px;" id="video-title">Trage videoclipul aici</h3>
        <p style="font-size:13px;color:var(--text-3);" id="video-subtitle">sau apasă pentru a selecta · MP4, WebM, MOV</p>
      </div>
      <!-- Preview video - arata in locul zonei -->
      <div id="video-preview-wrap" style="display:none;margin-top:0;">
        <div style="position:relative;border-radius:12px;overflow:hidden;background:#000;max-height:80vh;">
          <video id="video-preview" controls playsinline style="width:100%;height:auto;max-height:80vh;display:block;object-fit:contain;"></video>
          <button type="button" id="video-remove-btn"
                  style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.7);color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:600;">
            ✕ Elimină video
          </button>
        </div>
        <div id="video-info" style="margin-top:8px;font-size:13px;color:var(--text-3);display:flex;align-items:center;gap:8px;">
          <span style="color:#22C55E;font-weight:600;">✓</span>
          <span id="video-filename"></span>
          <span id="video-filesize" style="opacity:.7;"></span>
        </div>
      </div>
    </div>

    </div>

    <!-- 2. FOTOGRAFII -->
    <div id="photos-section">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:16px;">
      <h2 style="font-size:1rem;margin-bottom:6px;">📸 Fotografii <span style="font-size:13px;font-weight:400;color:var(--text-3);">— max <?= setting('max_photos','10') ?> poze, prima va fi principală</span></h2>
      <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" id="photos-input">
      <div class="upload-zone" style="padding:24px;cursor:pointer;" id="photos-zone">
        <div style="font-size:2rem;margin-bottom:6px;">📷</div>
        <p style="font-size:13px;color:var(--text-3);">JPEG, PNG, WebP · Prima poză va fi principală</p>
      </div>
      <div id="photos-preview" class="photos-grid"></div>
      <p id="photos-hint" style="font-size:12px;color:var(--text-3);margin-top:6px;display:none;">
        💡 Trage pozele pentru a le reordona. Prima e principala.
      </p>
    </div>

    </div>

    <!-- 3. DETALII -->
    <div id="details-section">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:16px;">
      <h2 style="font-size:1rem;margin-bottom:16px;">📋 Detalii anunț</h2>

      <div class="form-group">
        <label class="form-label">Titlu anunț *</label>
        <input type="text" name="title" class="form-control" value="<?= h($vals['title']??'') ?>"
               placeholder="ex: BMW Seria 5, 2022, impecabil, 48.000 km" maxlength="250" minlength="5" required>
        <div class="form-hint">Fii specific — titluri clare primesc mai multe clicuri</div>
      </div>

      <div class="form-group">
        <label class="form-label">Categorie *</label>
        <select name="category_id" id="category_id" class="form-control" required onchange="loadCategoryFields(this.value)">
          <option value="">— Selectează categoria —</option>
          <?php foreach($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($vals['category_id']??0)==$cat['id']?'selected':'' ?>>
              <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- CÂMPURI DINAMICE PER CATEGORIE -->
      <div id="category-fields-wrap" style="display:none;">
        <hr style="border:none;border-top:1px solid var(--border-2);margin:16px 0;">
        <div style="font-size:13px;font-weight:600;color:var(--text-3);margin-bottom:14px;text-transform:uppercase;letter-spacing:0.5px;">Specificații produs</div>
        <div id="category-fields-container"></div>
      </div>

      <div class="form-group">
        <label class="form-label">Descriere *</label>
        <textarea name="description" id="description-field" class="form-control" rows="7"
                  placeholder="Descrie produsul sau serviciul în detaliu: stare, caracteristici, de ce îl vinzi..."
                  minlength="20" required><?= h($vals['description']??'') ?></textarea>
        <div style="display:flex;justify-content:space-between;margin-top:4px;">
          <span class="form-hint">Minim 20 caractere</span>
          <span style="font-size:12px;color:var(--text-3);" id="desc-counter">0 caractere</span>
        </div>
      </div>
    </div>

    </div>

    <!-- 4. PRET -->
    <div id="price-section">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:16px;">
      <h2 style="font-size:1rem;margin-bottom:16px;">💰 Preț</h2>
      <div style="display:grid;grid-template-columns:1fr 110px;gap:12px;margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Preț</label>
          <input type="number" name="price" class="form-control" value="<?= h($vals['price']??'') ?>" placeholder="Lasă gol pentru &quot;Preț la cerere&quot;" min="0" step="any" inputmode="decimal">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Monedă</label>
          <select name="currency" class="form-control">
            <option value="RON" <?= ($vals['currency']??'RON')==='RON'?'selected':'' ?>>RON</option>
            <option value="EUR" <?= ($vals['currency']??'')==='EUR'?'selected':'' ?>>EUR</option>
            <option value="USD" <?= ($vals['currency']??'')==='USD'?'selected':'' ?>>USD</option>
          </select>
        </div>
      </div>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
        <input type="checkbox" name="price_negotiable" value="1" <?= ($vals['price_negotiable']??0)?'checked':'' ?>>
        Preț negociabil
      </label>
    </div>

    </div>

    <!-- 5. LOCATIE -->
    <div id="location-section">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:16px;">
      <h2 style="font-size:1rem;margin-bottom:16px;">📍 Locație</h2>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">Oraș *</label>
          <input type="text" name="city" class="form-control" value="<?= h($vals['city']??'') ?>" placeholder="București" required>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label">Județ</label>
          <input type="text" name="county" class="form-control" value="<?= h($vals['county']??'') ?>" placeholder="Ilfov">
        </div>
      </div>
    </div>

    </div>

    <!-- 6. CONTACT -->
    <div id="contact-section">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:24px;">
      <h2 style="font-size:1rem;margin-bottom:6px;">📞 Contact</h2>
      <p style="font-size:13px;color:var(--text-3);margin-bottom:16px;">Numărul va fi mascat și afișat doar când un utilizator logat apasă &quot;Sună vânzătorul&quot;</p>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Număr de telefon</label>
        <input type="tel" name="contact_phone" class="form-control"
               value="<?= h($vals['contact_phone'] ?? $user_full['phone'] ?? '') ?>"
               placeholder="07xx xxx xxx">
        <div class="form-hint">Dacă îl modifici, se va actualiza și în profilul tău</div>
      </div>
    </div>

    <div style="display:flex;gap:12px;justify-content:flex-end;">
      <a href="/" class="btn btn-secondary">Anulează</a>
      <button type="submit" class="btn btn-primary btn-lg">🚀 Publică anunțul</button>
    </div>
  </form>
</div>

<script>
// ── Category fields ──────────────────────────────────────
<?php
try {
    $all_fields_raw = DB::fetchAll("SELECT cf.* FROM category_fields cf WHERE cf.is_active=1 ORDER BY cf.category_id, cf.sort_order");
    $all_fields_grouped = array_reduce($all_fields_raw, function($carry, $f) {
        $f['options'] = $f['field_options'] ? json_decode($f['field_options'], true) : [];
        $carry[$f['category_id']][] = $f;
        return $carry;
    }, []);
} catch (Exception $e) { $all_fields_grouped = []; }
?>
const allCatFields = <?= js_json($all_fields_grouped) ?>;

function loadCategoryFields(catId) {
    const wrap = document.getElementById('category-fields-wrap');
    const container = document.getElementById('category-fields-container');
    const fields = allCatFields[catId] || [];
    if (!fields.length) { container.innerHTML=''; wrap.style.display='none'; return; }
    wrap.style.display = 'block';
    container.innerHTML = fields.map(f => {
        const req = f.is_required ? ' *' : '';
        const unit = f.field_unit ? `<span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px;pointer-events:none">${f.field_unit}</span>` : '';
        const unitPad = f.field_unit ? 'padding-right:50px;' : '';
        let input = '';
        if (f.field_type === 'select') {
            input = `<select name="cf_${f.field_name}" class="form-control" ${f.is_required?'required':''}><option value="">— Selectează —</option>${(f.options||[]).map(o=>`<option value="${o}">${o}</option>`).join('')}</select>`;
        } else if (f.field_type === 'number') {
            input = `<div style="position:relative">${unit}<input type="number" name="cf_${f.field_name}" class="form-control" style="${unitPad}" ${f.is_required?'required':''}></div>`;
        } else {
            input = `<input type="text" name="cf_${f.field_name}" class="form-control" ${f.is_required?'required':''}>`;
        }
        return `<div class="form-group"><label class="form-label">${f.field_label}${req}</label>${input}</div>`;
    }).join('');
}
const catSel = document.getElementById('category_id');
if (catSel?.value) loadCategoryFields(catSel.value);

// ── Stepper activ ─────────────────────────────────────────
function updateStepper() {
    const steps = ['video-section','details-section','price-section','contact-section'];
    const stepEls = document.querySelectorAll('.step');
    let scrollPos = window.scrollY + 120;
    let active = 0;
    steps.forEach((id, i) => {
        const el = document.getElementById(id);
        if (el && el.getBoundingClientRect().top + window.scrollY <= scrollPos) active = i;
    });
    stepEls.forEach((s, i) => {
        s.classList.toggle('active', i === active);
        s.classList.toggle('done', i < active);
    });
}
window.addEventListener('scroll', updateStepper, { passive: true });

// ── Video upload ──────────────────────────────────────────
const videoInput  = document.getElementById('video-input');
const videoZone   = document.getElementById('video-zone');
const videoPreviewWrap = document.getElementById('video-preview-wrap');
const videoPreview = document.getElementById('video-preview');

// Drag & drop - prevenim si propagarea ca sa nu triggeram label click
['dragenter','dragover'].forEach(ev => videoZone?.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation();
    videoZone.classList.add('drag');
}));
['dragleave'].forEach(ev => videoZone?.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation();
    videoZone.classList.remove('drag');
}));
videoZone?.addEventListener('drop', e => {
    e.preventDefault(); e.stopPropagation();
    videoZone.classList.remove('drag');
    const file = e.dataTransfer.files[0];
    if (file && file.type.startsWith('video/')) {
        // La drop nu triggeram label - setam direct
        const dt = new DataTransfer();
        dt.items.add(file);
        videoInput.files = dt.files;
        setVideoFile(file);
    }
});

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
    if (this.files[0]) setVideoFile(this.files[0]);
});

// ── Compresie video client-side ──────────────────────────
let compressedVideoBlob = null;
let compressedVideoFile = null;

async function setVideoFile(file) {
    const maxMb = <?= (int)setting('max_video_size_mb','500') ?>;
    if (file.size > maxMb * 1024 * 1024) {
        alert('Videoclipul depășește limita de ' + maxMb + 'MB!');
        videoInput.value = '';
        return;
    }

    // Show preview imediat cu fisierul original
    const url = URL.createObjectURL(file);
    videoPreview.src = url;
    videoZone.style.display = 'none';
    videoPreviewWrap.style.display = 'block';

    document.getElementById('video-filename').textContent = file.name;
    document.getElementById('video-filesize').textContent = '(' + (file.size/1024/1024).toFixed(1) + ' MB) — se comprimă...';

    // Canvas thumb capture
    captureVideoThumb(file);

    // Compresie client-side daca fisierul e > 15MB
    const COMPRESS_THRESHOLD_MB = 15;
    if (file.size > COMPRESS_THRESHOLD_MB * 1024 * 1024) {
        try {
            const compressed = await compressVideo(file);
            if (compressed && compressed.size < file.size * 0.95) {
                compressedVideoFile = new File([compressed], file.name.replace(/\.[^.]+$/, '.webm'), { type: compressed.type });
                const ratio = Math.round((1 - compressed.size / file.size) * 100);
                document.getElementById('video-filesize').textContent =
                    '(' + (file.size/1024/1024).toFixed(1) + ' MB → ' +
                    (compressed.size/1024/1024).toFixed(1) + ' MB, -' + ratio + '%) ✓ comprimat';
                document.getElementById('video-filesize').style.color = '#22C55E';
            } else {
                // Compresia nu a ajutat, folosim originalul
                compressedVideoFile = null;
                document.getElementById('video-filesize').textContent = '(' + (file.size/1024/1024).toFixed(1) + ' MB)';
            }
        } catch(e) {
            console.warn('Compresie esuata, folosim originalul:', e);
            compressedVideoFile = null;
            document.getElementById('video-filesize').textContent = '(' + (file.size/1024/1024).toFixed(1) + ' MB)';
        }
    } else {
        compressedVideoFile = null;
        document.getElementById('video-filesize').textContent = '(' + (file.size/1024/1024).toFixed(1) + ' MB)';
    }
}

async function compressVideo(file) {
    return new Promise((resolve, reject) => {
        const video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.src = URL.createObjectURL(file);

        video.addEventListener('loadedmetadata', () => {
            // Calculam rezolutia tinta: max 1080p, pastram aspect ratio
            const maxDim = 1080;
            let w = video.videoWidth;
            let h = video.videoHeight;

            if (w > maxDim || h > maxDim) {
                if (w > h) { h = Math.round(h * maxDim / w); w = maxDim; }
                else       { w = Math.round(w * maxDim / h); h = maxDim; }
            }
            // Asiguram ca dimensiunile sunt pare (cerinta codec)
            w = w % 2 === 0 ? w : w - 1;
            h = h % 2 === 0 ? h : h - 1;

            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');

            // Target bitrate: ~1.5Mbps pentru 720p, ~2.5Mbps pentru 1080p
            const targetBitrate = w >= 1080 ? 2500000 : (w >= 720 ? 1500000 : 800000);

            // Verificam suport codec
            const mimeTypes = [
                'video/webm;codecs=vp9',
                'video/webm;codecs=vp8',
                'video/webm',
            ];
            const supportedMime = mimeTypes.find(m => MediaRecorder.isTypeSupported(m));

            if (!supportedMime) {
                reject(new Error('MediaRecorder not supported'));
                return;
            }

            const chunks = [];
            const recorder = new MediaRecorder(canvas.captureStream(30), {
                mimeType: supportedMime,
                videoBitsPerSecond: targetBitrate,
            });

            recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
            recorder.onstop = () => {
                const blob = new Blob(chunks, { type: supportedMime.split(';')[0] });
                resolve(blob);
            };
            recorder.onerror = reject;

            // Afisam progress
            const infoEl = document.getElementById('video-filesize');
            const duration = video.duration;
            let startTime = null;

            function drawFrame(timestamp) {
                if (!startTime) startTime = timestamp;
                const elapsed = (timestamp - startTime) / 1000;
                const videoTime = elapsed;

                if (videoTime >= duration || recorder.state === 'inactive') {
                    recorder.stop();
                    video.pause();
                    return;
                }

                video.currentTime = videoTime;
                ctx.drawImage(video, 0, 0, w, h);

                const pct = Math.min(100, Math.round(videoTime / duration * 100));
                if (infoEl) infoEl.textContent = `Se comprimă... ${pct}% (${w}×${h})`;

                requestAnimationFrame(drawFrame);
            }

            video.addEventListener('canplaythrough', () => {
                video.play();
                recorder.start(100); // chunk la 100ms
                requestAnimationFrame(drawFrame);
            }, { once: true });
        });

        video.addEventListener('error', reject);
        video.load();
    });
}

document.getElementById('video-remove-btn')?.addEventListener('click', function(e) {
    e.stopPropagation();
    videoInput.value = '';
    videoPreview.src = '';
    videoZone.style.display = '';
    videoPreviewWrap.style.display = 'none';
    const th = document.getElementById('client_thumb_data');
    if (th) th.value = '';
});

function captureVideoThumb(file) {
    const url = URL.createObjectURL(file);
    const v = document.createElement('video');
    v.muted = true; v.playsInline = true; v.preload = 'metadata';
    v.src = url;
    v.addEventListener('loadeddata', () => { v.currentTime = 1; });
    v.addEventListener('seeked', function() {
        const canvas = document.createElement('canvas');
        canvas.width  = Math.min(v.videoWidth, 640);
        canvas.height = Math.round(canvas.width * v.videoHeight / v.videoWidth);
        canvas.getContext('2d').drawImage(v, 0, 0, canvas.width, canvas.height);
        let inp = document.getElementById('client_thumb_data');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'client_thumb_data'; inp.id = 'client_thumb_data';
            document.querySelector('form')?.appendChild(inp);
        }
        inp.value = canvas.toDataURL('image/jpeg', 0.8);
        URL.revokeObjectURL(url);
    }, { once: true });
}

// ── Photos preview + reorder ──────────────────────────────
let photoFiles = [];

// Photos zone click
let _pickingPhoto = false;
document.getElementById('photos-zone')?.addEventListener('click', () => {
    if (_pickingPhoto) return;
    _pickingPhoto = true;
    document.getElementById('photos-input').click();
    setTimeout(() => { _pickingPhoto = false; }, 2000);
});
document.getElementById('photos-input')?.addEventListener('change', async function() {
    let files = Array.from(this.files).slice(0, <?= (int)setting('max_photos','10') ?>);
    const zone = document.getElementById('photos-zone');
    if (window.VeedoCompress && files.length) {
        const orig = zone ? zone.innerHTML : '';
        if (zone) zone.innerHTML = '<div style="font-size:1.6rem;margin-bottom:6px;">⏳</div><p style="font-size:13px;color:var(--text-3);">Se optimizează pozele…</p>';
        try { files = await window.VeedoCompress.compressMany(files, {maxDim:1600, quality:0.82}); } catch(e) {}
        if (zone) zone.innerHTML = orig;
    }
    photoFiles = files;
    updatePhotoInput();
    renderPhotoGrid();
});

function renderPhotoGrid() {
    const grid = document.getElementById('photos-preview');
    const hint = document.getElementById('photos-hint');
    grid.innerHTML = '';
    if (photoFiles.length > 1 && hint) hint.style.display = 'block';

    photoFiles.forEach((file, i) => {
        const div = document.createElement('div');
        div.className = 'photo-thumb';
        div.draggable = true;
        div.dataset.idx = i;

        const reader = new FileReader();
        reader.onload = e => {
            div.innerHTML = `
                <img src="${e.target.result}" alt="">
                ${i===0 ? '<span class="primary-badge">Principal</span>' : ''}
                <button type="button" class="remove-photo" onclick="removePhoto(${i})">✕</button>
            `;
        };
        reader.readAsDataURL(file);

        // Drag events
        div.addEventListener('dragstart', () => { div.classList.add('dragging'); });
        div.addEventListener('dragend',   () => { div.classList.remove('dragging'); });
        div.addEventListener('dragover',  e => { e.preventDefault(); div.classList.add('drag-over'); });
        div.addEventListener('dragleave', () => { div.classList.remove('drag-over'); });
        div.addEventListener('drop', e => {
            e.preventDefault();
            div.classList.remove('drag-over');
            const fromIdx = +document.querySelector('.photo-thumb.dragging')?.dataset.idx;
            const toIdx   = +div.dataset.idx;
            if (fromIdx !== toIdx) {
                const moved = photoFiles.splice(fromIdx, 1)[0];
                photoFiles.splice(toIdx, 0, moved);
                updatePhotoInput();
                renderPhotoGrid();
            }
        });

        grid.appendChild(div);
    });
}

function removePhoto(idx) {
    photoFiles.splice(idx, 1);
    updatePhotoInput();
    renderPhotoGrid();
}

function updatePhotoInput() {
    const inp = document.getElementById('photos-input');
    const dt  = new DataTransfer();
    photoFiles.forEach(f => dt.items.add(f));
    inp.files = dt.files;
}

// ── Description counter ───────────────────────────────────
const descArea    = document.getElementById('description-field');
const descCounter = document.getElementById('desc-counter');
if (descArea && descCounter) {
    const upd = () => {
        const n = descArea.value.length;
        descCounter.textContent = n + ' caractere';
        descCounter.style.color = n < 20 ? 'var(--brand)' : 'var(--text-3)';
    };
    descArea.addEventListener('input', upd);
    upd();
}

// ── Upload progress ───────────────────────────────────────
const form = document.querySelector('form');
if (form) {
    form.addEventListener('submit', function(e) {
        const submitBtn = form.querySelector('button[type=submit]');
        const hasVideo = videoInput && videoInput.files.length > 0;
        function restoreBtn(){ if(submitBtn){ submitBtn.disabled=false; submitBtn.innerHTML=submitBtn.dataset.orig||'🚀 Publică anunțul'; } }
        if (submitBtn && !submitBtn.disabled) {
            submitBtn.dataset.orig = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Se publică…';
        }
        if (!hasVideo) return;
        e.preventDefault();

        let pb = document.getElementById('upload-pb');
        if (!pb) {
            pb = document.createElement('div');
            pb.id = 'upload-pb';
            pb.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px 20px;width:300px;box-shadow:0 8px 32px rgba(0,0,0,0.15);';
            pb.innerHTML = `
                <div style="font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                    <span id="pb-icon">⬆️</span> <span id="pb-title">Se încarcă videoclipul...</span>
                </div>
                <div style="background:var(--bg-3);border-radius:100px;height:8px;overflow:hidden;margin-bottom:8px;">
                    <div id="pb-bar" style="height:100%;width:0%;background:var(--brand);border-radius:100px;transition:width 0.3s;"></div>
                </div>
                <div id="pb-info" style="font-size:12px;color:var(--text-3);text-align:center;">Pregătire...</div>`;
            document.body.appendChild(pb);
        }

        const fd  = new XMLHttpRequest();
        // Folosim fisierul comprimat daca exista
        const ffd = new FormData(form);
        if (compressedVideoFile) {
            ffd.delete('video');
            ffd.append('video', compressedVideoFile, compressedVideoFile.name);
        }

        fd.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const pct = Math.round(e.loaded / e.total * 100);
                document.getElementById('pb-bar').style.width = pct + '%';
                document.getElementById('pb-info').textContent = pct + '% · ' + (e.loaded/1024/1024).toFixed(1) + 'MB / ' + (e.total/1024/1024).toFixed(1) + 'MB';
                if (pct === 100) {
                    document.getElementById('pb-title').textContent = 'Se procesează...';
                    document.getElementById('pb-icon').textContent = '⚙️';
                }
            }
        });

        fd.addEventListener('load', () => {
            if (fd.status === 200) {
                const url = fd.responseURL;
                if (url && url !== window.location.href) window.location.href = url;
                else { document.open(); document.write(fd.responseText); document.close(); }
            } else {
                alert('Eroare upload: ' + fd.status);
                pb.remove(); restoreBtn();
            }
        });

        fd.addEventListener('error', () => { alert('Eroare conexiune.'); pb.remove(); restoreBtn(); });
        fd.open('POST', form.action || window.location.href);
        fd.send(ffd);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>