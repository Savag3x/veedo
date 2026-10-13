<?php
// =========================================================
// VEEDO Admin — Curățare fișiere orfane din /uploads/
// =========================================================
// Identifică și șterge fișiere care nu mai sunt referite în BD
// (anunțuri șterse, poze înlocuite, avatare schimbate, etc.)
//
// Safety:
//   - Doar admin
//   - Ignorăm fișiere mai noi de 24h (poate fi upload în progres)
//   - Validăm referintele DIN NOU înainte de delete (race condition)
//   - Logăm in admin_log fiecare curățare
// =========================================================

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
@set_time_limit(120);

// === CONFIGURARE FOLDERE + SQL pentru fișierele referite ===
$folders = [
    'photos' => [
        'label' => 'Poze anunțuri',
        'icon'  => '🖼️',
        'path'  => __DIR__ . '/../uploads/photos/',
        'sql'   => "SELECT path AS p FROM ad_photos WHERE path IS NOT NULL AND path <> ''",
    ],
    'videos' => [
        'label' => 'Videoclipuri',
        'icon'  => '🎬',
        'path'  => __DIR__ . '/../uploads/videos/',
        'sql'   => "SELECT video_path AS p FROM ads WHERE video_path IS NOT NULL AND video_path <> ''",
    ],
    'thumbs' => [
        'label' => 'Miniaturi (thumbs)',
        'icon'  => '🖌️',
        'path'  => __DIR__ . '/../uploads/thumbs/',
        // Combinăm 3 surse: video_thumb din anunțuri + thumbnail din live_sessions + thumbnail din live_streams
        'sql'   => "SELECT video_thumb AS p FROM ads WHERE video_thumb IS NOT NULL AND video_thumb <> ''
                    UNION SELECT thumbnail AS p FROM live_sessions WHERE thumbnail IS NOT NULL AND thumbnail <> ''
                    UNION SELECT thumbnail AS p FROM live_streams WHERE thumbnail IS NOT NULL AND thumbnail <> ''",
    ],
    'avatars' => [
        'label' => 'Avatare utilizatori',
        'icon'  => '👤',
        'path'  => __DIR__ . '/../uploads/avatars/',
        'sql'   => "SELECT avatar AS p FROM users WHERE avatar IS NOT NULL AND avatar <> ''",
    ],
    'stories' => [
        'label' => 'Stories (poze + video)',
        'icon'  => '📖',
        'path'  => __DIR__ . '/../uploads/stories/',
        'sql'   => "SELECT media_path AS p FROM stories WHERE media_path IS NOT NULL AND media_path <> ''",
    ],
];

const ORPHAN_AGE_SECONDS = 86400; // 24h — fișierele mai noi nu sunt considerate orfane (siguranță upload-uri în curs)
$now = time();

// Helper: obține set de basename-uri referite dintr-un SQL
$get_referenced = function(string $sql): array {
    $set = [];
    try {
        $rows = DB::fetchAll($sql);
        foreach ($rows as $r) {
            $p = $r['p'] ?? '';
            if ($p !== '') $set[basename($p)] = true;
        }
    } catch (Throwable $e) {
        // tabela poate lipsi (ex. live_sessions încă neimplementat) — sărim peste
    }
    return $set;
};

// ============================================
// POST: ștergere
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $folder_key = sanitize($_POST['folder'] ?? '');
    $names      = $_POST['names'] ?? [];

    if (!isset($folders[$folder_key]) || !is_array($names)) {
        flash('error', 'Cerere invalidă.');
        redirect('/admin/curatare-uploads.php');
    }

    $cfg        = $folders[$folder_key];
    $referenced = $get_referenced($cfg['sql']);
    $deleted    = 0;
    $freed      = 0;
    $skipped    = 0;

    foreach ($names as $rawName) {
        $name = basename((string)$rawName); // sanitizare cale (blochează ../ etc.)
        if ($name === '' || $name === '.' || $name === '..') continue;

        $full = $cfg['path'] . $name;
        if (!is_file($full)) { $skipped++; continue; }

        // VALIDARE REPETATA (race condition protect):
        if (isset($referenced[$name]))                          { $skipped++; continue; } // încă referit
        if ($now - filemtime($full) < ORPHAN_AGE_SECONDS)       { $skipped++; continue; } // prea nou

        $size = filesize($full);
        if (@unlink($full)) {
            $deleted++;
            $freed += $size;
        } else {
            $skipped++;
        }
    }

    admin_log('cleanup_uploads', $folder_key, 0, "deleted={$deleted}, skipped={$skipped}, freed=" . format_bytes($freed));

    $msg = "✅ Șters: <b>{$deleted}</b> fișiere, eliberat: <b>" . format_bytes($freed) . "</b>";
    if ($skipped > 0) $msg .= " &middot; sărit: {$skipped}";
    flash('success', $msg);
    redirect('/admin/curatare-uploads.php');
}

// ============================================
// SCAN: construim datele pentru afișare
// ============================================
$data = [];
$grand_total_orphans = 0;
$grand_orphan_size   = 0;

foreach ($folders as $key => $cfg) {
    $referenced = $get_referenced($cfg['sql']);
    $files      = [];

    if (is_dir($cfg['path'])) {
        $dir = @scandir($cfg['path']);
        if ($dir) foreach ($dir as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === 'index.html') continue;
            $full = $cfg['path'] . $f;
            if (!is_file($full)) continue;
            $files[] = [
                'name'  => $f,
                'size'  => filesize($full),
                'mtime' => filemtime($full),
            ];
        }
    }

    $orphans          = [];
    $total_size       = 0;
    $orphan_size      = 0;
    $referenced_count = 0;
    $protected_new    = 0;

    foreach ($files as $f) {
        $total_size += $f['size'];
        if (isset($referenced[$f['name']])) {
            $referenced_count++;
        } else {
            if ($now - $f['mtime'] >= ORPHAN_AGE_SECONDS) {
                $orphans[]    = $f;
                $orphan_size += $f['size'];
            } else {
                $protected_new++; // există în uploads, nereferit, dar prea nou — nu-l atingem
            }
        }
    }

    // sortăm orfanii descrescător după mărime (cele mai grele primele)
    usort($orphans, fn($a, $b) => $b['size'] <=> $a['size']);

    $data[$key] = [
        'cfg'              => $cfg,
        'total_files'      => count($files),
        'total_size'       => $total_size,
        'referenced_count' => $referenced_count,
        'orphan_count'     => count($orphans),
        'orphan_size'      => $orphan_size,
        'protected_new'    => $protected_new,
        'orphans'          => $orphans,
    ];

    $grand_total_orphans += count($orphans);
    $grand_orphan_size   += $orphan_size;
}

$seo_title = 'Curățare uploads — Admin';
$page_class = 'admin-page';
include __DIR__ . '/../includes/header.php';
?>

<style>
.cu-page { max-width:1200px; margin:0 auto; padding:24px 16px 80px; }
.cu-page h1 { font-size:28px; margin:0 0 8px; }
.cu-page .lead { color:var(--text-2); margin:0 0 24px; font-size:15px; }
.cu-summary { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px; padding:16px 20px; background:var(--bg-2); border-radius:14px; border:1px solid var(--border); }
.cu-summary .stat { display:flex; flex-direction:column; }
.cu-summary .stat-val { font-size:22px; font-weight:800; color:var(--text); }
.cu-summary .stat-label { font-size:12px; color:var(--text-3); text-transform:uppercase; letter-spacing:0.5px; }
.cu-folder { background:var(--bg-2); border:1px solid var(--border); border-radius:14px; margin-bottom:16px; overflow:hidden; }
.cu-folder-head { display:flex; align-items:center; gap:16px; padding:16px 20px; cursor:pointer; user-select:none; }
.cu-folder-head:hover { background:var(--bg-3); }
.cu-folder-icon { font-size:28px; }
.cu-folder-title { flex:1; }
.cu-folder-title .name { font-weight:700; font-size:16px; }
.cu-folder-title .meta { font-size:13px; color:var(--text-3); margin-top:2px; }
.cu-orphan-badge { padding:4px 10px; border-radius:8px; background:var(--danger); color:#fff; font-weight:700; font-size:13px; }
.cu-clean-badge  { padding:4px 10px; border-radius:8px; background:var(--success); color:#fff; font-weight:700; font-size:13px; }
.cu-folder-body { display:none; border-top:1px solid var(--border); padding:16px 20px; }
.cu-folder.open .cu-folder-body { display:block; }
.cu-orphan-list { max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:10px; padding:6px; background:var(--bg); }
.cu-orphan-row { display:flex; align-items:center; gap:12px; padding:6px 10px; border-radius:6px; font-size:13px; }
.cu-orphan-row:hover { background:var(--bg-3); }
.cu-orphan-row input[type=checkbox] { width:16px; height:16px; cursor:pointer; }
.cu-orphan-name { flex:1; font-family:monospace; word-break:break-all; }
.cu-orphan-size { color:var(--text-3); min-width:80px; text-align:right; }
.cu-orphan-age  { color:var(--text-3); min-width:80px; text-align:right; font-size:12px; }
.cu-actions { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.cu-empty { text-align:center; padding:24px; color:var(--text-3); font-style:italic; }
@media (max-width:600px) {
    .cu-orphan-age { display:none; }
}
</style>

<div class="cu-page">
    <h1>🧹 Curățare fișiere orfane</h1>
    <p class="lead">
        Fișiere din <code>/uploads/</code> care nu mai sunt referite în baza de date
        (anunțuri șterse, poze înlocuite, etc.). Fișierele <b>mai noi de 24h</b> sunt protejate
        automat (pot fi upload-uri în curs).
    </p>

    <?php if ($flash = get_flash('success')): ?>
        <div class="alert alert-success" style="margin-bottom:16px;"><?= $flash // safe: din flash() ?></div>
    <?php endif; ?>
    <?php if ($flash = get_flash('error')): ?>
        <div class="alert alert-danger" style="margin-bottom:16px;"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="cu-summary">
        <div class="stat">
            <span class="stat-val"><?= $grand_total_orphans ?></span>
            <span class="stat-label">Total orfani</span>
        </div>
        <div class="stat">
            <span class="stat-val"><?= h(format_bytes($grand_orphan_size)) ?></span>
            <span class="stat-label">Spațiu de recuperat</span>
        </div>
        <div class="stat" style="margin-left:auto;">
            <a href="/admin/curatare-uploads.php" class="btn btn-secondary btn-sm">🔄 Re-scanare</a>
        </div>
    </div>

    <?php foreach ($data as $key => $d):
        $cfg = $d['cfg'];
        $has_orphans = $d['orphan_count'] > 0;
    ?>
    <div class="cu-folder<?= $has_orphans ? '' : ' clean' ?>" id="folder-<?= h($key) ?>">
        <div class="cu-folder-head" onclick="document.getElementById('folder-<?= h($key) ?>').classList.toggle('open')">
            <div class="cu-folder-icon"><?= $cfg['icon'] ?></div>
            <div class="cu-folder-title">
                <div class="name"><?= h($cfg['label']) ?> <span style="color:var(--text-3);font-weight:400;font-size:13px;">— /uploads/<?= h($key) ?>/</span></div>
                <div class="meta">
                    <?= $d['total_files'] ?> fișiere · <?= h(format_bytes($d['total_size'])) ?>
                    &middot; referite: <?= $d['referenced_count'] ?>
                    <?php if ($d['protected_new'] > 0): ?>
                        &middot; <span title="Mai noi de 24h, nu vor fi atinși">protejați (noi): <?= $d['protected_new'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($has_orphans): ?>
                <span class="cu-orphan-badge"><?= $d['orphan_count'] ?> orfani · <?= h(format_bytes($d['orphan_size'])) ?></span>
            <?php else: ?>
                <span class="cu-clean-badge">✓ curat</span>
            <?php endif; ?>
            <span style="font-size:18px;color:var(--text-3);">▾</span>
        </div>

        <?php if ($has_orphans): ?>
        <div class="cu-folder-body">
            <form method="post" action="/admin/curatare-uploads.php" onsubmit="return confirmDelete(this, '<?= h($cfg['label']) ?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="folder" value="<?= h($key) ?>">

                <div style="margin-bottom:10px;font-size:13px;">
                    <a href="javascript:void(0)" onclick="cuToggleAll('<?= h($key) ?>', true)">Selectează toate</a>
                    &middot;
                    <a href="javascript:void(0)" onclick="cuToggleAll('<?= h($key) ?>', false)">Deselectează</a>
                </div>

                <div class="cu-orphan-list">
                    <?php foreach ($d['orphans'] as $f):
                        $age_days = floor(($now - $f['mtime']) / 86400);
                    ?>
                        <label class="cu-orphan-row">
                            <input type="checkbox" name="names[]" value="<?= h($f['name']) ?>" data-folder="<?= h($key) ?>" data-size="<?= (int)$f['size'] ?>">
                            <span class="cu-orphan-name"><?= h($f['name']) ?></span>
                            <span class="cu-orphan-size"><?= h(format_bytes($f['size'])) ?></span>
                            <span class="cu-orphan-age"><?= $age_days ?> zile</span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="cu-actions">
                    <button type="submit" class="btn btn-danger">🗑️ Șterge selectate</button>
                    <button type="button" class="btn btn-secondary" onclick="cuToggleAll('<?= h($key) ?>', true); this.form.requestSubmit();">🗑️ Șterge TOȚI orfanii (<?= $d['orphan_count'] ?>)</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($grand_total_orphans === 0): ?>
        <div style="text-align:center;padding:48px;color:var(--text-3);">
            <div style="font-size:56px;margin-bottom:12px;">✨</div>
            <h2 style="margin:0 0 8px;">Totul e curat!</h2>
            <p>Nu există fișiere orfane în <code>/uploads/</code>.</p>
        </div>
    <?php endif; ?>

    <p style="margin-top:32px;font-size:12px;color:var(--text-3);text-align:center;">
        Acțiunile de ștergere sunt logate în jurnalul admin (<code>/admin?s=log</code>).
        Fișierele se șterg <b>permanent</b> — fără coș de gunoi.
    </p>
</div>

<script>
function cuToggleAll(folder, state) {
    document.querySelectorAll('input[type=checkbox][data-folder="'+folder+'"]').forEach(cb => cb.checked = state);
}
function confirmDelete(form, label) {
    const checked = form.querySelectorAll('input[name="names[]"]:checked');
    if (checked.length === 0) {
        alert('Selectează cel puțin un fișier.');
        return false;
    }
    let totalSize = 0;
    checked.forEach(cb => totalSize += parseInt(cb.dataset.size || 0, 10));
    const sizeMb = (totalSize / 1048576).toFixed(2);
    return confirm(`Ștergi PERMANENT ${checked.length} fișiere (${sizeMb} MB) din "${label}"?\n\nAcțiunea NU poate fi anulată.`);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
