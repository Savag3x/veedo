<?php
// recover.php - request password reset
require_once __DIR__ . '/includes/bootstrap.php';
if (is_logged()) redirect('/');

// Rate limit: max 5 cereri/15 min per IP (previne enumerare email-uri și spam)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_die('recover', 5, 900);
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $user  = filter_var($email, FILTER_VALIDATE_EMAIL)
        ? DB::fetch("SELECT id,name,email FROM users WHERE email=? AND is_active=1", [$email])
        : null;
    if ($user) {
        // În DB salvăm doar hash-ul tokenului: o scurgere a bazei de date nu permite preluarea conturilor
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        DB::update('users', ['reset_token'=>hash('sha256', $token),'reset_expires'=>$expires], 'id=?', [$user['id']]);
        require_once __DIR__ . '/includes/mailer.php';
        $link = BASE_URL . '/reset/' . $token;
        veedo_mail($user['email'], $user['name'], 'Resetare parolă',
            '<h2>Resetare parolă</h2>
             <p>Salut, ' . h($user['name']) . '! Am primit o cerere de resetare a parolei pentru contul tău.</p>
             <p><a href="' . h($link) . '" class="btn">Setează o parolă nouă</a></p>
             <p style="font-size:13px;color:#888;">Link-ul este valabil 60 de minute. Dacă nu ai cerut resetarea, ignoră acest email — parola ta rămâne neschimbată.</p>');
        admin_log('password_reset_request', 'users', $user['id'], '');
    }
    // Always show success (don't reveal if email exists)
    $sent = true;
}

$seo_title = 'Recuperare parolă';
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:60px 20px;">
  <div style="max-width:420px;margin:0 auto;text-align:center;">
    <div style="font-size:3rem;margin-bottom:16px;">🔑</div>
    <h1 style="font-size:1.5rem;margin-bottom:8px;">Ai uitat parola?</h1>
    <p style="color:var(--text-3);margin-bottom:28px;">Introdu emailul contului tău și îți trimitem un link de resetare.</p>

    <?php if ($sent): ?>
      <div class="alert alert-success">
        ✓ Dacă emailul există în sistem, vei primi un link de resetare în câteva minute. Verifică și folderul Spam.
      </div>
      <a href="/login" class="btn btn-primary mt-3">← Înapoi la autentificare</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:28px;text-align:left;">
        <form method="POST">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="email">Adresă email</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="email@exemplu.ro" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Trimite link de resetare</button>
        </form>
      </div>
      <p style="margin-top:16px;font-size:14px;"><a href="/login">← Înapoi la autentificare</a></p>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
