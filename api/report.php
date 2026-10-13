<?php
// api/report.php
require_once __DIR__ . '/../includes/bootstrap.php';
if (!is_logged()) { flash('error','Trebuie să fii conectat.'); redirect('/login'); }
$ad_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') rate_limit_or_die('report', 5, 300); // max 5 rapoarte/5 min

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $ad_id  = (int)($_POST['ad_id'] ?? 0);
    $reason = in_array($_POST['reason']??'',['spam','fake','inappropriate','scam','other']) ? $_POST['reason'] : 'other';
    $details= mb_substr(sanitize($_POST['details'] ?? ''), 0, 1000);
    $uid    = current_user()['id'];

    if ($ad_id && DB::fetch("SELECT id FROM ads WHERE id=?",[$ad_id])) {
        // Don't allow duplicate reports
        if (!DB::fetch("SELECT id FROM reports WHERE reporter_id=? AND ad_id=?",[$uid,$ad_id])) {
            DB::insert('reports',['reporter_id'=>$uid,'ad_id'=>$ad_id,'reason'=>$reason,'details'=>$details]);
            // Notifică toți administratorii (nu doar user_id=1)
            foreach (DB::fetchAll("SELECT id FROM users WHERE role='admin' AND is_active=1 LIMIT 10") as $adm) {
                add_notification((int)$adm['id'], 'report', 'Raport nou primit', "Motiv: $reason", '/admin?s=reports');
            }
            flash('success','Raportul tău a fost trimis. Mulțumim!');
        } else {
            flash('info','Ai raportat deja acest anunț.');
        }
    }
}
// Redirect înapoi la anunț, nu la referer (poate fi manipulat)
$redirect_url = '/';
if ($ad_id) {
    $ad_slug = DB::fetch("SELECT slug FROM ads WHERE id=?", [$ad_id]);
    if ($ad_slug) $redirect_url = '/anunt/' . $ad_slug['slug'];
}
redirect($redirect_url);
