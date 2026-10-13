<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';
if (!is_logged()) { flash('error','Trebuie să fii conectat.'); redirect('/login'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) { flash('error','Eroare.'); redirect('/'); }

$u = current_user();
$reviewed_id = (int)($_POST['reviewed_id'] ?? 0);
$rating      = max(1,min(5,(int)($_POST['rating'] ?? 0)));
$comment     = sanitize($_POST['comment'] ?? '');
$ad_id       = (int)($_POST['ad_id'] ?? 0);

if (!$reviewed_id || !$rating) { flash('error','Date incomplete.'); redirect('back'); }
if ($reviewed_id == $u['id']) { flash('error','Nu poți da recenzie propriului profil.'); redirect('/utilizator/'.$reviewed_id); }

// Check if already reviewed this user
$existing = DB::fetch("SELECT id FROM reviews WHERE reviewer_id=? AND reviewed_id=?", [$u['id'], $reviewed_id]);
if ($existing) { flash('error','Ai dat deja o recenzie acestui utilizator.'); redirect('/utilizator/'.$reviewed_id); }

DB::insert('reviews', [
    'reviewer_id' => $u['id'],
    'reviewed_id' => $reviewed_id,
    'ad_id'       => $ad_id ?: null,
    'rating'      => $rating,
    'comment'     => $comment,
    'is_approved' => 1,
]);

// Update user rating avg
$stats = DB::fetch("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM reviews WHERE reviewed_id=? AND is_approved=1", [$reviewed_id]);
DB::update('users', ['rating_avg'=>round($stats['avg'],2),'rating_count'=>$stats['cnt']], 'id=?', [$reviewed_id]);

// Email notification for review
try {
    $rev_user = DB::fetch("SELECT name,email FROM users WHERE id=?", [$reviewed_id]);
    $me_user  = DB::fetch("SELECT name FROM users WHERE id=?", [current_user()['id']]);
    if ($rev_user && $me_user) {
        DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
            [$reviewed_id,'new_review', $me_user['name'].' ți-a lăsat o recenzie ⭐',
             $comment ? mb_substr($comment,0,100) : str_repeat('★',$rating).str_repeat('☆',5-$rating),
             '/utilizator/'.$reviewed_id.'?tab=recenzii']);
        notify_new_review($me_user, $rev_user, $rating, $comment);
    }
} catch (Exception $ex) {}
flash('success','Recenzia a fost adăugată!');
redirect('/utilizator/'.$reviewed_id);
