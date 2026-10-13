<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged()) redirect('/');
if (!setting_bool('registration_open')) {
    flash('error', 'Înregistrarea nu este disponibilă momentan.');
    redirect('/login');
}

$errors = [];
$vals   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $errors[] = 'Eroare de securitate.'; }
    else {
        rate_limit_or_die('register', 5, 3600); // anti-spam: înainte de orice altă procesare
        $vals = [
            'name'     => mb_substr(sanitize($_POST['name'] ?? ''), 0, 80),
            'email'    => mb_strtolower(trim((string)($_POST['email'] ?? ''))),
            'phone'    => clean_phone($_POST['phone'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'confirm'  => $_POST['password_confirm'] ?? '',
            'type'     => in_array($_POST['type'] ?? '', ['user','business']) ? $_POST['type'] : 'user',
        ];

        if (strlen($vals['name']) < 3) $errors[] = 'Numele trebuie să aibă cel puțin 3 caractere.';
        if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresa de email nu este validă.';
        if (strlen($vals['password']) < PASSWORD_MIN_LENGTH) $errors[] = 'Parola trebuie să aibă cel puțin ' . PASSWORD_MIN_LENGTH . ' caractere.';
        if ($vals['password'] !== $vals['confirm']) $errors[] = 'Parolele nu coincid.';
        if (DB::fetch("SELECT id FROM users WHERE email=?", [$vals['email']])) $errors[] = 'Această adresă de email este deja înregistrată.';
        if (!isset($_POST['gdpr'])) $errors[] = 'Trebuie să accepți politica GDPR.';

        if (strlen($vals['password']) > 200) $errors[] = 'Parola este prea lungă.';

        if (empty($errors)) {
            $token = bin2hex(random_bytes(32));

            // Track referral din GET / POST / cookie
            $ref_code    = (string)($_GET['ref'] ?? $_POST['ref'] ?? $_COOKIE['veedo_ref'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $ref_code)) $ref_code = '';
            $referrer_id = null;
            if ($ref_code) {
                $referrer = DB::fetch("SELECT id FROM users WHERE referral_code=?", [$ref_code]);
                if ($referrer) $referrer_id = $referrer['id'];
            }

            $uid = DB::insert('users', [
                'name'               => $vals['name'],
                'email'              => $vals['email'],
                'phone'              => $vals['phone'],
                'password'           => password_hash($vals['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                'role'               => $vals['type'],
                'email_verify_token' => $token,
                'is_active'          => 1,
                'is_email_verified'  => 0,
            ]);

            login_user($uid);

            // Înregistrare referral
            if ($referrer_id) {
                try {
                    DB::query("INSERT IGNORE INTO referrals (referrer_id, referred_id) VALUES (?,?)",
                        [$referrer_id, $uid]);
                } catch (Exception $ex) {}
            }

            flash('success', 'Cont creat cu succes! Bun venit pe Veedo!');

            // Notificare welcome + email
            try {
                DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
                    [$uid, 'system', 'Bun venit pe Veedo! 🎉', 'Postează primul tău anunț video acum!', '/anunt/nou']);
                notify_welcome(['email' => $vals['email'], 'name' => $vals['name']]);
            } catch (Exception $ex) {}

            redirect('/');
        }
    }
}

$seo_title = 'Înregistrare gratuită';
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:40px 20px;">
  <div style="max-width:500px;margin:0 auto;">

    <div style="text-align:center;margin-bottom:32px;">
      <a href="/" style="font-family:var(--font-display);font-size:2rem;font-weight:800;color:var(--text);letter-spacing:-1px;text-decoration:none;">
        <span style="color:var(--brand);">V</span>eed<span style="color:var(--brand2);">o</span>
      </a>
      <h1 style="margin-top:12px;font-size:1.5rem;">Creează cont gratuit</h1>
      <p style="color:var(--text-3);margin-top:6px;">Ai deja cont? <a href="/login">Intră în cont</a></p>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:28px;">

      <!-- Type selector -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px;">
        <label style="cursor:pointer;">
          <input type="radio" name="type_sel" value="user" checked style="display:none;" onchange="document.querySelector('[name=type]').value='user'">
          <div class="type-card" id="card-user" style="border:2px solid var(--brand);border-radius:var(--radius);padding:14px;text-align:center;background:rgba(255,59,48,0.04);">
            <div style="font-size:1.5rem;margin-bottom:4px;">👤</div>
            <div style="font-weight:600;font-size:14px;">Persoană fizică</div>
            <div style="font-size:12px;color:var(--text-3);">Anunțuri personale</div>
          </div>
        </label>
        <label style="cursor:pointer;">
          <input type="radio" name="type_sel" value="business" style="display:none;" onchange="document.querySelector('[name=type]').value='business'">
          <div class="type-card" id="card-business" style="border:2px solid var(--border);border-radius:var(--radius);padding:14px;text-align:center;">
            <div style="font-size:1.5rem;margin-bottom:4px;">🏢</div>
            <div style="font-weight:600;font-size:14px;">Business</div>
            <div style="font-size:12px;color:var(--text-3);">Companie / PFA</div>
          </div>
        </label>
      </div>

      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= h($vals['type'] ?? 'user') ?>">

        <div class="form-group">
          <label class="form-label" for="name">Nume complet / Denumire firmă</label>
          <input type="text" id="name" name="name" class="form-control"
                 value="<?= h($vals['name'] ?? '') ?>" placeholder="Ion Popescu" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label" for="email">Adresă email</label>
          <input type="email" id="email" name="email" class="form-control"
                 value="<?= h($vals['email'] ?? '') ?>" placeholder="email@exemplu.ro" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="phone">Număr de telefon <span style="color:var(--text-3);font-weight:400;">(opțional)</span></label>
          <input type="tel" id="phone" name="phone" class="form-control"
                 value="<?= h($vals['phone'] ?? '') ?>" placeholder="07xx xxx xxx">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label" for="password">Parolă</label>
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="Min. <?= PASSWORD_MIN_LENGTH ?> caractere" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="password_confirm">Confirmă parola</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control"
                   placeholder="Repetă parola" required>
          </div>
        </div>

        <!-- Password strength -->
        <div style="margin-top:-12px;margin-bottom:16px;">
          <div id="pass-bar" style="height:4px;border-radius:2px;background:var(--bg-3);overflow:hidden;">
            <div id="pass-fill" style="height:100%;width:0;transition:all 0.3s;border-radius:2px;"></div>
          </div>
          <div id="pass-label" style="font-size:12px;color:var(--text-3);margin-top:4px;"></div>
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:14px;">
            <input type="checkbox" name="gdpr" style="width:16px;height:16px;flex-shrink:0;margin-top:2px;" required>
            <span>Am citit și accept <a href="/pagina/termeni-si-conditii">Termenii și condițiile</a> și <a href="/pagina/politica-confidentialitate">Politica de confidențialitate GDPR</a> a platformei Veedo.ro.</span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary w-full btn-lg" style="justify-content:center;">
          Creează cont gratuit
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// Type card toggle
document.querySelectorAll('[name=type_sel]').forEach(r => {
  r.addEventListener('change', function() {
    document.getElementById('card-user').style.border = '2px solid var(--border)';
    document.getElementById('card-user').style.background = '';
    document.getElementById('card-business').style.border = '2px solid var(--border)';
    document.getElementById('card-business').style.background = '';
    const card = document.getElementById('card-' + this.value);
    card.style.border = '2px solid var(--brand)';
    card.style.background = 'rgba(255,59,48,0.04)';
  });
});

// Password strength meter
document.getElementById('password').addEventListener('input', function() {
  const v = this.value;
  let score = 0;
  if (v.length >= 8) score++;
  if (v.length >= 12) score++;
  if (/[A-Z]/.test(v)) score++;
  if (/[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  const colors = ['', '#FF3B30', '#FF9500', '#FFD60A', '#30D158', '#34C759'];
  const labels = ['', 'Foarte slabă', 'Slabă', 'Medie', 'Bună', 'Excelentă'];
  document.getElementById('pass-fill').style.width = (score * 20) + '%';
  document.getElementById('pass-fill').style.background = colors[score] || '';
  document.getElementById('pass-label').textContent = labels[score] || '';
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
