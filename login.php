<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged()) redirect('/');

$error = '';
// Previne open redirect: acceptăm doar path-uri relative (nu URL-uri externe)
$_next_raw = $_GET['next'] ?? '/';
$next = (str_starts_with($_next_raw, '/') && !str_starts_with($_next_raw, '//')) 
    ? sanitize($_next_raw) 
    : '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $error = 'Eroare de securitate. Reîncarcă pagina.'; }
    else {
        $email    = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        // Rate limiting robustă: MAX_LOGIN_ATTEMPTS per IP în fereastra de blocare
        // Folosim rate_limit() în loc de COUNT pe admin_log (mai rapid, nu depinde de indecși)
        $rl_key = 'login_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
        if (!rate_limit($rl_key, MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_MINUTES * 60)) {
            $error = 'Prea multe încercări eșuate. Așteaptă ' . LOGIN_LOCKOUT_MINUTES . ' minute.';
        } else {
            $user = DB::fetch("SELECT * FROM users WHERE email=? AND is_active=1", [$email]);
            if ($user && password_verify($password, $user['password'])) {
                login_user($user['id']);
                if ($remember) {
                    session_set_cookie_params(['lifetime' => SESSION_LIFETIME]);
                }
                if ($user['theme_preference'] !== 'auto') {
                    $_SESSION['theme'] = $user['theme_preference'];
                }
                flash('success', 'Bun venit înapoi, ' . $user['name'] . '!');
                redirect($next ?: '/');
            } else {
                // Păstrăm admin_log doar pentru audit (nu mai e folosit la blocare)
                admin_log('login_fail', 'users', 0, $_SERVER['REMOTE_ADDR'] ?? '');
                $error = 'Email sau parolă incorectă.';
            }
        }
    }
}

$seo_title = 'Intră în cont';
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:40px 20px;">
  <div style="max-width:440px;margin:0 auto;">

    <div style="text-align:center;margin-bottom:32px;">
      <a href="/" style="font-family:var(--font-display);font-size:2rem;font-weight:800;color:var(--text);letter-spacing:-1px;text-decoration:none;">
        <span style="color:var(--brand);">V</span>eed<span style="color:var(--brand2);">o</span>
      </a>
      <h1 style="margin-top:12px;font-size:1.5rem;">Intră în cont</h1>
      <p style="color:var(--text-3);margin-top:6px;">Nu ai cont? <a href="/register">Înregistrează-te gratuit</a></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:28px;">
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= h($next) ?>">

        <div class="form-group">
          <label class="form-label" for="email">Adresă email</label>
          <input type="email" id="email" name="email" class="form-control"
                 value="<?= h($_POST['email'] ?? '') ?>"
                 placeholder="email@exemplu.ro" autocomplete="email" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label" for="password">
            Parolă
            <a href="/recover" style="float:right;font-size:13px;font-weight:400;">Ai uitat parola?</a>
          </label>
          <div style="position:relative;">
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="Parola ta" autocomplete="current-password" required>
            <button type="button" onclick="togglePass()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-3);cursor:pointer;" aria-label="Arată parola">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;">
          <input type="checkbox" id="remember" name="remember" style="width:16px;height:16px;">
          <label for="remember" style="font-size:14px;cursor:pointer;">Ține-mă conectat 30 de zile</label>
        </div>

        <button type="submit" class="btn btn-primary w-full btn-lg" style="justify-content:center;">
          Intră în cont
        </button>
      </form>
    </div>

    <p style="text-align:center;margin-top:20px;font-size:13px;color:var(--text-3);">
      Prin autentificare, accepți <a href="/pagina/termeni-si-conditii">Termenii</a> și <a href="/pagina/politica-confidentialitate">Politica GDPR</a>.
    </p>
  </div>
</div>

<script>
function togglePass() {
  const p = document.getElementById('password');
  p.type = p.type === 'password' ? 'text' : 'password';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
