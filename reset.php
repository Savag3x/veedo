<?php
// reset.php - actual password reset with token
require_once __DIR__ . '/includes/bootstrap.php';
if (is_logged()) redirect('/');

// Rate limit: max 10 încercări/15 min per IP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_die('reset', 10, 900);
}

$token = sanitize($_GET['token'] ?? '');
$user  = DB::fetch("SELECT id,name,email FROM users WHERE reset_token=? AND reset_expires > NOW() AND is_active=1", [$token]);

$error   = '';
$success = false;

if (!$user && $token) {
    $error = 'Link-ul de resetare este invalid sau a expirat. Solicită unul nou.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && csrf_verify()) {
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    if (strlen($pass) < PASSWORD_MIN_LENGTH) {
        $error = 'Parola trebuie să aibă cel puțin ' . PASSWORD_MIN_LENGTH . ' caractere.';
    } elseif ($pass !== $confirm) {
        $error = 'Parolele nu coincid.';
    } else {
        DB::update('users', [
            'password'     => password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]),
            'reset_token'  => null,
            'reset_expires'=> null,
        ], 'id=?', [$user['id']]);
        login_user($user['id']);
        flash('success', 'Parola a fost schimbată cu succes!');
        redirect('/');
    }
}

$seo_title = 'Resetare parolă';
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:60px 20px;">
  <div style="max-width:420px;margin:0 auto;text-align:center;">
    <div style="font-size:3rem;margin-bottom:16px;">🔒</div>
    <h1 style="font-size:1.5rem;margin-bottom:24px;">Setează parola nouă</h1>

    <?php if ($error && !$user): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
      <a href="/recover" class="btn btn-primary mt-3">Solicită un nou link</a>
    <?php elseif ($user): ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:28px;text-align:left;">
        <p style="font-size:14px;color:var(--text-3);margin-bottom:16px;">Setezi parola pentru <strong><?= h($user['email']) ?></strong></p>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="password">Parolă nouă</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Min. <?= PASSWORD_MIN_LENGTH ?> caractere" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label" for="password_confirm">Confirmă parola</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="Repetă parola" required>
          </div>
          <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Salvează parola nouă</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
