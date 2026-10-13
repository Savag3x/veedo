<?php
// live-start.php — Pornire / Gestionare stream LIVE
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$u = current_user();

// Doar business/admin pot face LIVE
// LIVE doar pentru conturi Business VERIFICATE (rolul "business" se poate alege liber la înregistrare)
if (!$u['is_verified_business'] && $u['role'] !== 'admin') {
    flash('error','Doar conturile Business Verificat pot porni un stream LIVE.');
    redirect('/cont/abonamente');
}

if (!setting('live_enabled','1')) { redirect('/'); }

$errors = [];
$my_stream = DB::fetch("SELECT * FROM live_streams WHERE user_id=? AND status IN ('live','scheduled') ORDER BY created_at DESC LIMIT 1", [$u['id']]);

// Actiuni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title       = sanitize($_POST['title'] ?? '');
        $desc        = sanitize($_POST['description'] ?? '');
        $stream_url  = safe_url($_POST['stream_url'] ?? '');
        $scheduled   = sanitize($_POST['scheduled_at'] ?? '');

        if (strlen($title) < 5) $errors[] = 'Titlul trebuie să aibă cel puțin 5 caractere.';
        if (empty($errors)) {
            $stream_key = bin2hex(random_bytes(16));
            $sid = DB::insert('live_streams', [
                'user_id'      => $u['id'],
                'title'        => $title,
                'description'  => $desc ?: null,
                'stream_url'   => $stream_url ?: null,
                'stream_key'   => $stream_key,
                'status'       => $scheduled ? 'scheduled' : 'live',
                'scheduled_at' => $scheduled ?: null,
                'started_at'   => $scheduled ? null : date('Y-m-d H:i:s'),
            ]);
            flash('success', $scheduled ? 'Stream programat!' : 'Stream LIVE pornit!');
            redirect('/live/'.$sid);
        }
    }

    if ($action === 'end') {
        $end_id = (int)($_POST['stream_id'] ?? 0) ?: ($my_stream['id'] ?? 0);
        if ($end_id) {
            $to_end = DB::fetch("SELECT id FROM live_streams WHERE id=? AND user_id=?", [$end_id, $u['id']]);
            if ($to_end) {
                DB::update('live_streams', ['status'=>'ended','ended_at'=>date('Y-m-d H:i:s')], 'id=?', [$end_id]);
                // Stergem fisierul de chat dupa 1 ora (lasam sa fie citit)
                // Il marcam ca expirat redenumindu-l
                $chat_file = BASE_PATH . '/uploads/live_chats/stream_' . $end_id . '.json';
                if (file_exists($chat_file)) {
                    rename($chat_file, BASE_PATH . '/uploads/live_chats/ended_' . $end_id . '_' . time() . '.json');
                }
                flash('success','Stream-ul a fost oprit.');
            }
        }
        redirect('/live');
    }

    if ($action === 'add_product' && $my_stream) {
        $ad_id = (int)($_POST['ad_id'] ?? 0);
        $ad = DB::fetch("SELECT id FROM ads WHERE id=? AND user_id=? AND status='active'", [$ad_id, $u['id']]);
        if ($ad) {
            try { DB::insert('live_products', ['stream_id'=>$my_stream['id'],'ad_id'=>$ad_id]); } catch(Exception $e){}
            flash('success','Produs adăugat în stream!');
        }
        redirect('/live/start');
    }

    if ($action === 'remove_product' && $my_stream) {
        $lp_id = (int)($_POST['lp_id'] ?? 0);
        DB::query("DELETE FROM live_products WHERE id=? AND stream_id=?", [$lp_id, $my_stream['id']]);
        redirect('/live/start');
    }
}

// Produsele mele disponibile pentru stream
$my_ads = DB::fetchAll("
    SELECT a.*, c.name as cat_name,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo
    FROM ads a JOIN categories c ON c.id=a.category_id
    WHERE a.user_id=? AND a.status='active'
    ORDER BY a.created_at DESC LIMIT 50
", [$u['id']]);

// Produsele din stream curent
$stream_products = $my_stream ? DB::fetchAll("
    SELECT lp.id as lp_id, a.title, a.price, a.slug,
           (SELECT path FROM ad_photos WHERE ad_id=a.id AND is_primary=1 LIMIT 1) as main_photo
    FROM live_products lp JOIN ads a ON a.id=lp.ad_id
    WHERE lp.stream_id=?
", [$my_stream['id']]) : [];

$seo_title = 'Gestionare LIVE';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:28px 20px;max-width:760px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:10px;">
    <div>
      <h1 style="font-size:1.4rem;margin-bottom:4px;">🔴 Gestionare LIVE Shopping</h1>
      <p style="color:var(--text-3);font-size:14px;">Pornește un stream și prezintă-ți produsele în timp real</p>
    </div>
    <a href="/live" class="btn btn-secondary btn-sm">← Toate streamurile</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:20px;"><?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($my_stream): ?>
  <!-- Stream activ -->
  <div style="background:var(--surface);border:2px solid <?= $my_stream['status']==='live'?'var(--danger)':'var(--warning)' ?>;border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
      <?php if ($my_stream['status']==='live'): ?>
        <span style="background:#EF4444;color:#fff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:700;animation:pulse 2s infinite;">🔴 LIVE</span>
      <?php else: ?>
        <span style="background:#F59E0B;color:#fff;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:700;">📅 PROGRAMAT</span>
      <?php endif; ?>
      <h2 style="font-size:1rem;margin:0;"><?= h($my_stream['title']) ?></h2>
    </div>

    <?php if ($my_stream['status']==='live'): ?>
    <div style="background:var(--bg-2);border-radius:var(--radius);padding:12px;margin-bottom:16px;font-size:13px;">
      <div style="margin-bottom:6px;"><strong>👁 Vizualizatori activi:</strong> <span id="viewers-count"><?= $my_stream['viewers_count'] ?></span></div>
      <div><strong>⏱ Durată:</strong> <span id="stream-duration">Se calculează...</span></div>
      <?php if ($my_stream['stream_url']): ?>
      <div style="margin-top:8px;">
        <strong>🔗 Link stream:</strong> <a href="<?= h($my_stream['stream_url']) ?>" target="_blank" style="font-size:12px;"><?= h(mb_substr($my_stream['stream_url'],0,60)) ?></a>
      </div>
      <?php endif; ?>
      <div style="margin-top:8px;">
        <strong>🔑 Stream key:</strong> <code style="font-size:11px;background:var(--bg-3);padding:2px 6px;border-radius:4px;"><?= h($my_stream['stream_key']) ?></code>
        <button onclick="navigator.clipboard.writeText('<?= h($my_stream['stream_key']) ?>').then(()=>alert('Copiat!'))" class="btn btn-secondary btn-sm" style="margin-left:6px;">Copiază</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Produse in stream -->
    <div style="margin-bottom:16px;">
      <div style="font-weight:600;font-size:14px;margin-bottom:10px;">🛒 Produse în stream (<?= count($stream_products) ?>)</div>
      <?php if (!empty($stream_products)): ?>
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;">
        <?php foreach($stream_products as $sp): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;background:var(--bg-2);padding:10px;border-radius:var(--radius);">
          <div style="display:flex;align-items:center;gap:10px;">
            <?php if($sp['main_photo']): ?>
              <img src="/uploads/photos/<?= h($sp['main_photo']) ?>" width="40" height="40" style="border-radius:6px;object-fit:cover;" alt="">
            <?php endif; ?>
            <div>
              <div style="font-size:13px;font-weight:600;"><?= h(mb_substr($sp['title'],0,40)) ?></div>
              <div style="font-size:12px;color:var(--brand);"><?= $sp['price']>0?number_format($sp['price'],0).' RON':'Negociabil' ?></div>
            </div>
          </div>
          <form method="POST" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_product">
            <input type="hidden" name="lp_id" value="<?= $sp['lp_id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Adauga produs -->
      <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_product">
        <select name="ad_id" class="form-control" style="flex:1;min-width:200px;">
          <option value="">— Alege un anunț —</option>
          <?php foreach($my_ads as $ma): ?>
          <option value="<?= $ma['id'] ?>"><?= h(mb_substr($ma['title'],0,50)) ?> — <?= $ma['price']>0?number_format($ma['price'],0).' RON':'Negociabil' ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">+ Adaugă</button>
      </form>
    </div>

    <!-- Oprire stream -->
    <form method="POST" onsubmit="return confirm('Ești sigur că vrei să oprești stream-ul?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="end">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button type="submit" class="btn btn-danger">⏹ Oprește stream-ul</button>
        <a href="/live/<?= $my_stream['id'] ?>" class="btn btn-secondary" target="_blank">👁 Vezi ca spectator</a>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- Creare stream nou -->
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:24px;margin-bottom:24px;">
    <h2 style="font-size:1rem;margin-bottom:16px;">▶ Pornește un stream nou</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label class="form-label">Titlu stream *</label>
        <input type="text" name="title" class="form-control" placeholder="ex: Colecție nouă de haine — reduceri 30%" required maxlength="200">
      </div>
      <div class="form-group">
        <label class="form-label">Descriere</label>
        <textarea name="description" class="form-control" rows="2" placeholder="Ce vei prezenta în acest stream?" maxlength="500"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">URL Stream (YouTube Live / Twitch)</label>
        <input type="url" name="stream_url" class="form-control" placeholder="https://youtube.com/watch?v=... sau https://twitch.tv/...">
        <div class="form-hint">Opțional. Dacă nu ai un URL, folosim player-ul nostru nativ.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Programare (opțional)</label>
        <input type="datetime-local" name="scheduled_at" class="form-control" min="<?= date('Y-m-d\TH:i') ?>">
        <div class="form-hint">Lasă gol pentru a porni LIVE acum.</div>
      </div>

      <div style="background:var(--bg-2);border-radius:var(--radius);padding:12px;margin-bottom:16px;font-size:13px;color:var(--text-2);">
        <strong>💡 Sfaturi pentru un LIVE de succes:</strong>
        <ul style="margin:6px 0 0 16px;line-height:1.8;">
          <li>Anunță stream-ul în avans pe Stories și Feed</li>
          <li>Pregătește produsele pe care vrei să le prezinți</li>
          <li>Asigură-te că ai lumină bună și conexiune stabilă</li>
          <li>Interacționează cu comentariile în timp real</li>
        </ul>
      </div>
      <button type="submit" class="btn btn-primary btn-lg" style="justify-content:center;">🔴 Pornește stream-ul</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Ghid tehnic -->
  <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:20px;">
    <h2 style="font-size:1rem;margin-bottom:14px;">📖 Cum funcționează streaming-ul</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;font-size:13px;">
      <div>
        <div style="font-weight:700;margin-bottom:6px;">🎥 Opțiunea 1 — YouTube Live</div>
        <ol style="margin-left:14px;line-height:1.8;color:var(--text-2);">
          <li>Mergi pe YouTube Studio</li>
          <li>Pornește un stream live</li>
          <li>Copiază URL-ul și introdu-l mai sus</li>
          <li>Vizitatorii văd stream-ul embed</li>
        </ol>
      </div>
      <div>
        <div style="font-weight:700;margin-bottom:6px;">📡 Opțiunea 2 — OBS / Streamlabs</div>
        <ol style="margin-left:14px;line-height:1.8;color:var(--text-2);">
          <li>Instalează OBS Studio</li>
          <li>Setează server RTMP (Twitch)</li>
          <li>Folosește stream key-ul de mai sus</li>
          <li>Adaugă URL-ul Twitch ca link</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<style>
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }
</style>
<script>
// Timer durata stream
<?php if ($my_stream && $my_stream['status']==='live' && $my_stream['started_at']): ?>
const startedAt = new Date('<?= $my_stream['started_at'] ?>').getTime();
function updateDuration() {
    const diff = Date.now() - startedAt;
    const h = Math.floor(diff/3600000), m = Math.floor((diff%3600000)/60000), s = Math.floor((diff%60000)/1000);
    document.getElementById('stream-duration').textContent = `${h>0?h+'h ':''} ${m}m ${s}s`;
}
setInterval(updateDuration, 1000);
updateDuration();
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>