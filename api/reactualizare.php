<?php
/* Veedo — Reactualizare anunț (bump): urcă anunțul în listă, o dată la 24h. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash('error', 'Cerere invalidă.');
    redirect('/');
}

$uid   = current_user()['id'];
$ad_id = (int)($_POST['ad_id'] ?? 0);

$ad = DB::fetch(
    "SELECT id, slug, user_id, status, created_at, bumped_at FROM ads WHERE id=?",
    [$ad_id]
);

if (!$ad || $ad['user_id'] != $uid) {
    flash('error', 'Anunț negăsit sau acces interzis.');
    redirect('/cont/anunturile-mele');
}

$back = '/anunt/' . $ad['slug'];

if ($ad['status'] !== 'active') {
    flash('error', 'Poți reactualiza doar anunțuri active.');
    redirect($back);
}

// Limită: o reactualizare la 24h (de la ultima reactualizare sau, dacă lipsește, de la postare)
$ref     = $ad['bumped_at'] ?: $ad['created_at'];
$elapsed = time() - strtotime($ref);

if ($elapsed < 86400) {
    $hours = (int)ceil((86400 - $elapsed) / 3600);
    flash('error', "Anunțul a fost actualizat recent. Îl poți reactualiza din nou peste {$hours} h.");
    redirect($back);
}

DB::query("UPDATE ads SET bumped_at = NOW() WHERE id = ?", [$ad_id]);
flash('success', 'Anunțul a fost reactualizat — a urcat în partea de sus a listei.');
redirect($back);