<?php
// =========================================================
// VEEDO Admin — 🎨 Personalizare (aspect, homepage, carduri, meniu, footer)
// Previzualizare live a site-ului în dreapta; salvare cu un click.
// =========================================================
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$schema = ui_schema();
$tabs   = ui_tabs();
$tab    = isset($_GET['tab'], $tabs[$_GET['tab']]) ? $_GET['tab'] : 'brand';

/** Salvează un fișier imagine încărcat pentru logo/favicon; întoarce URL-ul relativ sau null. */
function ui_upload(string $field): ?string {
    if (empty($_FILES[$field]['name']) || !empty($_FILES[$field]['error'])) return null;
    $dir = UPLOAD_PATH . 'photos/';
    $name = upload_photo($_FILES[$field], $dir);
    return $name ? '/uploads/photos/' . $name : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // CSRF verificat global în bootstrap
    $action = $_POST['action'] ?? 'save';
    $postTab = isset($_POST['tab'], $tabs[$_POST['tab']]) ? $_POST['tab'] : 'brand';

    if ($action === 'save') {
        $n = 0;
        foreach ($schema as $key => $def) {
            if ($def[0] !== $postTab) continue;
            if ($def[2] === 'image') {
                $up = ui_upload('upload_' . $key);
                if ($up) { setting_update($key, $up); $n++; continue; }
                if (!empty($_POST['remove_' . $key])) { setting_update($key, ''); $n++; continue; }
            }
            // Checkbox-urile nebifate nu se trimit → 0
            $raw = $def[2] === 'bool' ? (isset($_POST[$key]) ? '1' : '0') : ($_POST[$key] ?? null);
            if ($raw === null) continue;
            $clean = ui_clean($key, $raw);
            if ($clean !== null) { setting_update($key, $clean); $n++; }
        }
        admin_log('customize_save', 'settings', 0, "Personalizare ($postTab): $n opțiuni");
        flash('success', 'Modificările au fost salvate.');
    } elseif ($action === 'reset_tab') {
        foreach ($schema as $key => $def) {
            if ($def[0] === $postTab && $def[2] !== 'image' && !str_starts_with($key, 'feature_')) setting_update($key, (string)$def[3]);
        }
        admin_log('customize_reset', 'settings', 0, "Resetare $postTab");
        flash('success', 'Secțiunea a fost readusă la valorile implicite.');
    } elseif ($action === 'import' && !empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
        $json = json_decode((string)file_get_contents($_FILES['import_file']['tmp_name'], false, null, 0, 200000), true);
        $n = 0;
        if (is_array($json)) {
            foreach ($json as $key => $val) {
                if (!isset($schema[$key])) continue;
                $clean = ui_clean($key, $val);
                if ($clean !== null) { setting_update($key, $clean); $n++; }
            }
        }
        admin_log('customize_import', 'settings', 0, "Import: $n opțiuni");
        flash($n ? 'success' : 'error', $n ? "Au fost importate $n opțiuni." : 'Fișier invalid.');
    }
    foreach (['settings', 'footer_cats', 'footer_pages', 'home_stats'] as $k) cache_forget($k);
    redirect('/admin/personalizare.php?tab=' . $postTab, 303);
}

// Export JSON
if (($_GET['export'] ?? '') === '1') {
    $out = [];
    foreach ($schema as $key => $def) $out[$key] = ui($key);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="veedo-personalizare-' . date('Y-m-d') . '.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$seo_title = 'Personalizare — Admin';
include __DIR__ . '/../includes/header.php';
?>
<style>
.cz{max-width:1600px;margin:0 auto;padding:22px 24px 40px;display:grid;grid-template-columns:minmax(380px,520px) 1fr;gap:24px;align-items:start}
.cz-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.cz-head h1{font-size:1.6rem}
.cz-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
.cz-tabs a{font-size:13px;font-weight:600;padding:8px 12px;border-radius:11px;color:var(--text-2);background:var(--glass);border:1px solid var(--glass-border)}
.cz-tabs a:hover{color:var(--text);border-color:var(--glass-hi)}
.cz-tabs a.on{background:var(--accent);color:var(--on-accent);border-color:var(--accent)}
.cz-panel{background:var(--surface);border:1px solid var(--glass-border);border-radius:24px;padding:8px 20px 20px;backdrop-filter:blur(20px)}
.cz-row{padding:14px 0;border-bottom:1px solid var(--border-2)}
.cz-row:last-of-type{border-bottom:0}
.cz-row label.t{display:block;font-size:13.5px;font-weight:600;margin-bottom:8px;color:var(--text)}
.cz-bool{display:flex;align-items:center;justify-content:space-between;gap:14px;cursor:pointer}
.cz-bool span{font-size:13.5px;font-weight:600}
.sw{position:relative;width:46px;height:26px;flex-shrink:0}
.sw input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}
.sw i{position:absolute;inset:0;border-radius:999px;background:var(--surface-3);border:1px solid var(--glass-border);transition:background .2s}
.sw i::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:var(--text-3);transition:transform .25s var(--spring),background .2s}
.sw input:checked+i{background:var(--accent)}
.sw input:checked+i::after{transform:translateX(20px);background:var(--on-accent)}
.cz-color{display:flex;gap:10px;align-items:center}
.cz-color input[type=color]{width:52px;height:44px;border:1px solid var(--glass-border);border-radius:12px;background:none;cursor:pointer;padding:3px}
.cz-swatches{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.cz-swatches button{width:26px;height:26px;border-radius:8px;border:2px solid var(--glass-border);cursor:pointer}
.cz-img{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.cz-img img{height:44px;max-width:140px;object-fit:contain;border-radius:8px;background:var(--glass);padding:4px;border:1px solid var(--glass-border)}
.cz-actions{position:sticky;bottom:12px;margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;padding:12px;border-radius:18px;background:var(--surface-solid);border:1px solid var(--glass-border);box-shadow:var(--shadow-lg);z-index:5}
.cz-preview{position:sticky;top:calc(var(--header-h) + 12px)}
.cz-pbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.cz-pbar select{max-width:220px;min-height:38px;padding:6px 36px 6px 12px;font-size:13px}
.cz-dev{display:flex;gap:4px;padding:4px;background:var(--glass);border:1px solid var(--glass-border);border-radius:12px}
.cz-dev button{border:0;background:none;color:var(--text-3);padding:6px 10px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer}
.cz-dev button.on{background:var(--surface-3);color:var(--text)}
.cz-frame-wrap{border-radius:22px;overflow:hidden;border:1px solid var(--glass-border);background:var(--bg);box-shadow:var(--shadow-lg);height:calc(100vh - var(--header-h) - 90px);display:flex;justify-content:center;transition:all .3s}
.cz-frame-wrap iframe{width:100%;height:100%;border:0;background:var(--bg);transition:width .3s}
.cz-frame-wrap.mobile iframe{width:390px;border-left:1px solid var(--glass-border);border-right:1px solid var(--glass-border)}
.cz-help{font-size:12.5px;color:var(--text-3);margin-top:6px}
@media(max-width:1100px){.cz{grid-template-columns:1fr}.cz-preview{position:static}.cz-frame-wrap{height:70vh}}
</style>

<div class="cz">
  <div>
    <div class="cz-head">
      <div>
        <a href="/admin" class="btn btn-ghost btn-sm" style="margin:0 0 6px -10px">← Admin</a>
        <h1>🎨 Personalizare</h1>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn btn-secondary btn-sm" href="?export=1">⬇ Export</a>
        <form method="POST" enctype="multipart/form-data" id="import-form" style="display:inline">
          <input type="hidden" name="action" value="import"><input type="hidden" name="tab" value="<?= h($tab) ?>">
          <label class="btn btn-secondary btn-sm" style="cursor:pointer">⬆ Import<input type="file" name="import_file" accept="application/json,.json" hidden onchange="this.form.submit()"></label>
        </form>
      </div>
    </div>

    <nav class="cz-tabs">
      <?php foreach ($tabs as $k => $lbl): ?><a href="?tab=<?= h($k) ?>" class="<?= $k === $tab ? 'on' : '' ?>"><?= h($lbl) ?></a><?php endforeach; ?>
    </nav>

    <form method="POST" enctype="multipart/form-data" class="cz-panel" id="cz-form">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <?php foreach ($schema as $key => $d):
        if ($d[0] !== $tab) continue;
        [, $label, $type, $def] = $d; $opts = $d[4] ?? []; $val = ui($key); $id = 'f_' . $key; ?>
      <div class="cz-row">
        <?php if ($type === 'bool'): ?>
          <label class="cz-bool" for="<?= h($id) ?>"><span><?= h($label) ?></span>
            <span class="sw"><input type="checkbox" id="<?= h($id) ?>" name="<?= h($key) ?>" value="1" data-live="<?= h($key) ?>" <?= $val === '1' ? 'checked' : '' ?>><i></i></span>
          </label>
        <?php else: ?>
          <label class="t" for="<?= h($id) ?>"><?= h($label) ?></label>
          <?php if ($type === 'color'): ?>
            <div class="cz-color">
              <input type="color" id="<?= h($id) ?>" name="<?= h($key) ?>" value="<?= h($val ?: $def) ?>" data-live="<?= h($key) ?>">
              <input type="text" class="form-control" value="<?= h($val ?: $def) ?>" style="max-width:130px;font-family:var(--font-mono)" oninput="if(/^#[0-9a-f]{6}$/i.test(this.value)){var c=document.getElementById('<?= h($id) ?>');c.value=this.value;c.dispatchEvent(new Event('input'))}">
            </div>
            <div class="cz-swatches">
              <?php foreach (($key === 'site_color_primary' ? ['#2563EB', '#0F766E', '#4F46E5', '#0EA5E9', '#16A34A', '#EA580C', '#D4FF3F'] : ['#1E40AF', '#0F172A', '#334155', '#4F46E5', '#0F766E', '#9B86FF', '#7357FF']) as $sw): ?>
                <button type="button" style="background:<?= $sw ?>" title="<?= $sw ?>" onclick="var c=document.getElementById('<?= h($id) ?>');c.value='<?= $sw ?>';c.nextElementSibling&&0;c.dispatchEvent(new Event('input'));c.parentNode.querySelector('input[type=text]').value='<?= $sw ?>'"></button>
              <?php endforeach; ?>
            </div>
          <?php elseif ($type === 'select'): ?>
            <select class="form-control" id="<?= h($id) ?>" name="<?= h($key) ?>" data-live="<?= h($key) ?>">
              <?php foreach ($opts as $ov => $ol): ?><option value="<?= h($ov) ?>" <?= (string)$ov === $val ? 'selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?>
            </select>
          <?php elseif ($type === 'textarea' || $type === 'lines'): ?>
            <textarea class="form-control" id="<?= h($id) ?>" name="<?= h($key) ?>" rows="<?= $key === 'custom_css' ? 10 : 3 ?>" <?= $key === 'custom_css' ? 'style="font-family:var(--font-mono);font-size:13px"' : '' ?>><?= h($val) ?></textarea>
          <?php elseif ($type === 'image'): ?>
            <div class="cz-img">
              <?php if ($val): ?><img src="<?= h($val) ?>" alt=""><label style="font-size:13px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="remove_<?= h($key) ?>" value="1"> Elimină</label><?php endif; ?>
              <input type="file" name="upload_<?= h($key) ?>" accept="image/png,image/jpeg,image/webp" class="form-control" style="max-width:280px;min-height:auto;padding:8px">
            </div>
            <input type="text" class="form-control" name="<?= h($key) ?>" value="<?= h($val) ?>" placeholder="sau un URL: https://…" style="margin-top:8px">
          <?php elseif ($type === 'number'): ?>
            <input type="number" class="form-control" id="<?= h($id) ?>" name="<?= h($key) ?>" value="<?= h($val) ?>" min="0" style="max-width:180px">
          <?php else: ?>
            <input type="text" class="form-control" id="<?= h($id) ?>" name="<?= h($key) ?>" value="<?= h($val) ?>">
          <?php endif; ?>
          <?php if ($key === 'ui_hero_title'): ?><div class="cz-help">Exemplu: <code>Vezi *înainte* să cumperi.</code> — cuvântul dintre stele apare colorat.</div><?php endif; ?>
          <?php if ($key === 'ui_nav_links'): ?><div class="cz-help">Exemplu: <code>Blog | https://blog.veedo.ro</code> sau <code>Ajutor | /pagina/ajutor</code> (max. 6).</div><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div class="cz-actions">
        <button type="submit" class="btn btn-primary">💾 Salvează</button>
        <button type="submit" class="btn btn-ghost" formnovalidate onclick="if(!confirm('Readuci această secțiune la valorile implicite?'))return false;this.form.action.value='reset_tab'">↺ Valori implicite</button>
        <span id="cz-dirty" style="display:none;align-self:center;font-size:12.5px;color:var(--warning)">● modificări nesalvate</span>
      </div>
    </form>
  </div>

  <aside class="cz-preview">
    <div class="cz-pbar">
      <strong style="font-size:13.5px">Previzualizare</strong>
      <select class="form-control" id="pv-page">
        <option value="/">Homepage</option>
        <option value="/cauta">Căutare</option>
        <?php $pv = DB::fetch("SELECT slug FROM ads WHERE status='active' ORDER BY views_count DESC LIMIT 1"); if ($pv): ?>
        <option value="/anunt/<?= h($pv['slug']) ?>">Pagină de anunț</option>
        <?php endif; ?>
        <option value="/login">Autentificare</option>
      </select>
      <div class="cz-dev"><button type="button" class="on" data-dev="desktop">🖥 Desktop</button><button type="button" data-dev="mobile">📱 Mobil</button></div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="pvReload()">↻</button>
    </div>
    <div class="cz-frame-wrap" id="pv-wrap"><iframe id="pv" src="/" title="Previzualizare site"></iframe></div>
    <p class="cz-help">Culorile, colțurile, stilul cardurilor și opțiunile de temă se văd imediat. Textele apar după ce apeși „Salvează”.</p>
  </aside>
</div>

<script>
(function () {
  const frame = document.getElementById('pv');
  const form = document.getElementById('cz-form');
  window.pvReload = () => { try { frame.contentWindow.location.reload(); } catch (e) { frame.src = frame.src; } };
  document.getElementById('pv-page').addEventListener('change', e => { frame.src = e.target.value; });
  document.querySelectorAll('.cz-dev button').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.cz-dev button').forEach(x => x.classList.toggle('on', x === b));
    document.getElementById('pv-wrap').classList.toggle('mobile', b.dataset.dev === 'mobile');
  }));

  // Previzualizare live: aplicăm direct în documentul din iframe (aceeași origine)
  const bodyClass = { ui_card_style: 'cards-', ui_card_size: 'cardsize-', ui_radius: 'radius-', ui_aurora_level: 'aurora-' };
  const negClass = { ui_aurora: 'no-aurora', ui_grain: 'no-grain', ui_animations: 'no-anim', ui_card_seller: 'card-no-seller', ui_card_views: 'card-no-views', ui_card_location: 'card-no-meta' };
  function lum(hex) { const n = parseInt(hex.slice(1), 16); return 0.299 * (n >> 16) + 0.587 * (n >> 8 & 255) + 0.114 * (n & 255); }
  function live(el) {
    let doc; try { doc = frame.contentDocument; } catch (e) { return; }
    if (!doc || !doc.body) return;
    const k = el.dataset.live, v = el.type === 'checkbox' ? el.checked : el.value, root = doc.documentElement, body = doc.body;
    if (k === 'site_color_primary') { root.style.setProperty('--accent', v); root.style.setProperty('--accent-2', v); root.style.setProperty('--on-accent', lum(v) > 150 ? '#0A0A0C' : '#FFFFFF'); }
    else if (k === 'site_color_secondary') root.style.setProperty('--brand', v);
    else if (k === 'site_theme_default') root.setAttribute('data-theme', v);
    else if (bodyClass[k]) { [...body.classList].filter(c => c.startsWith(bodyClass[k])).forEach(c => body.classList.remove(c)); body.classList.add(bodyClass[k] + v); }
    else if (negClass[k]) body.classList.toggle(negClass[k], !v);
  }
  form.addEventListener('input', e => { document.getElementById('cz-dirty').style.display = 'inline'; if (e.target.dataset.live) live(e.target); });
  form.addEventListener('change', e => { document.getElementById('cz-dirty').style.display = 'inline'; if (e.target.dataset.live) live(e.target); });
  frame.addEventListener('load', () => form.querySelectorAll('[data-live]').forEach(live));
  window.addEventListener('beforeunload', e => { if (document.getElementById('cz-dirty').style.display === 'inline' && !form.dataset.submitting) { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('submit', () => { form.dataset.submitting = '1'; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
