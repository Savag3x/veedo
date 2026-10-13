<?php
// contact.php
require_once __DIR__ . '/includes/bootstrap.php';

$sent   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Anti-spam: limită pe IP + câmp capcană invizibil (boții îl completează)
    rate_limit_or_die('contact', 5, 3600);
    if (!empty($_POST['website'])) { $sent = true; }
    $name    = mb_substr(no_crlf(sanitize($_POST['name'] ?? '')), 0, 80);
    $email   = trim((string)($_POST['email'] ?? ''));
    $subject = mb_substr(no_crlf(sanitize($_POST['subject'] ?? '')), 0, 150);
    $message = mb_substr(sanitize($_POST['message'] ?? ''), 0, 5000);

    if (strlen($name) < 2)     $errors[] = 'Introdu numele tău.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalid.';
    if (strlen($message) < 10) $errors[] = 'Mesajul trebuie să aibă cel puțin 10 caractere.';

    if (empty($errors) && !$sent) {
        $contact_email = setting('contact_email') ?: setting('site_email') ?: '';
        
        // Trimite email catre admin
        if ($contact_email) {
            $body = "<h2>Mesaj nou prin formular contact</h2>
                <p><strong>De la:</strong> " . h($name) . " &lt;" . h($email) . "&gt;</p>
                <p><strong>Subiect:</strong> " . h($subject) . "</p>
                <hr>
                <div style='background:#F5F5F5;padding:16px;border-radius:8px;white-space:pre-wrap;'>" . h($message) . "</div>
                <hr>
                <p style='font-size:12px;color:#888;'>Trimis de pe " . h($_SERVER['REMOTE_ADDR'] ?? '') . " la " . date('d.m.Y H:i') . "</p>";
            veedo_mail($contact_email, setting('site_name','Veedo'), "Contact: $subject — $name", $body);
        }

        // Confirmare catre utilizator
        veedo_mail($email, $name, 'Am primit mesajul tău!',
            "<h2>Mulțumim, " . h($name) . "! 📨</h2>
            <p>Am primit mesajul tău și îți vom răspunde în cel mai scurt timp.</p>
            <div style='background:#F5F5F5;border-left:3px solid #E8380D;padding:12px 16px;border-radius:0 8px 8px 0;margin:12px 0;'>
                <strong>Subiect:</strong> " . h($subject) . "<br><br>" . nl2br(h(mb_substr($message, 0, 300))) . (mb_strlen($message)>300?'...' : '') . "
            </div>
            <p style='font-size:13px;color:#888;'>Echipa " . h(setting('site_name','Veedo')) . "</p>"
        );

        // Notificare admin in platform
        DB::insert('notifications', [
            'user_id' => 1,
            'type'    => 'contact',
            'title'   => "Contact: $subject — $name",
            'body'    => "De la: $email\n\n$message",
            'link'    => '/admin?s=dashboard',
        ]);
        $sent = true;
    }
}

$seo_title = 'Contact';
include __DIR__ . '/includes/header.php';
?>

<div class="container-sm" style="padding:40px 20px;">
  <div style="max-width:560px;margin:0 auto;">
    <h1 style="margin-bottom:8px;">Contactează-ne</h1>
    <p style="color:var(--text-3);margin-bottom:28px;">Ai întrebări sau probleme? Suntem aici să te ajutăm.</p>

    <?php if ($sent): ?>
      <div class="alert alert-success">
        ✓ Mesajul tău a fost trimis! Îți vom răspunde în cel mai scurt timp, și pe email.
      </div>
      <a href="/" class="btn btn-primary mt-3">← Înapoi acasă</a>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <div style="background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);padding:28px;">
        <form method="POST">
          <div style="position:absolute;left:-9999px;" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <?= csrf_field() ?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">Nume *</label>
              <input type="text" name="name" class="form-control" value="<?= h($_POST['name']??current_user()['name']??'') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Email *</label>
              <input type="email" name="email" class="form-control" value="<?= h($_POST['email']??current_user()['email']??'') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Subiect</label>
            <select name="subject" class="form-control">
              <option>Problemă tehnică</option>
              <option>Raportez un anunț</option>
              <option>Întrebare despre plăți</option>
              <option>Parteneri / Publicitate</option>
              <option>Altceva</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Mesaj *</label>
            <textarea name="message" class="form-control" rows="5" placeholder="Descrie problema sau întrebarea ta..." required><?= h($_POST['message']??'') ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">Trimite mesajul</button>
        </form>
      </div>

      <div style="margin-top:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;">
        <?php if (setting('contact_email')): ?>
        <div style="text-align:center;padding:16px;background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);">
          <div style="font-size:1.5rem;margin-bottom:6px;">📧</div>
          <div style="font-size:12px;color:var(--text-3);">Email</div>
          <a href="mailto:<?= h(setting('contact_email')) ?>" style="font-size:13px;font-weight:500;"><?= h(setting('contact_email')) ?></a>
        </div>
        <?php endif; ?>
        <?php if (setting('contact_phone')): ?>
        <div style="text-align:center;padding:16px;background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);">
          <div style="font-size:1.5rem;margin-bottom:6px;">📞</div>
          <div style="font-size:12px;color:var(--text-3);">Telefon</div>
          <a href="tel:<?= h(setting('contact_phone')) ?>" style="font-size:13px;font-weight:500;"><?= h(setting('contact_phone')) ?></a>
        </div>
        <?php endif; ?>
        <div style="text-align:center;padding:16px;background:var(--surface);border:1px solid var(--border-2);border-radius:var(--radius-lg);">
          <div style="font-size:1.5rem;margin-bottom:6px;">⏰</div>
          <div style="font-size:12px;color:var(--text-3);">Program suport</div>
          <div style="font-size:13px;font-weight:500;">Lun–Vin 9–18</div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
