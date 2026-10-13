<?php
// verifica.php - email verification
require_once __DIR__ . '/includes/bootstrap.php';

$token = sanitize($_GET['token'] ?? '');
$user  = DB::fetch("SELECT id,name FROM users WHERE email_verify_token=? AND is_email_verified=0", [$token]);

if ($user) {
    DB::update('users', ['is_email_verified'=>1,'email_verify_token'=>null], 'id=?', [$user['id']]);
    login_user($user['id']);
    flash('success','Email-ul a fost verificat! Bun venit pe Veedo, '.$user['name'].'!');
    redirect('/');
} else {
    flash('error','Link de verificare invalid sau deja folosit.');
    redirect('/login');
}
