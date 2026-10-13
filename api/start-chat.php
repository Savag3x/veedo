<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    json_response(['error' => 'Invalid request'], 400);
}

$uid     = current_user()['id'];
$ad_id   = (int)($_POST['ad_id'] ?? 0);
$profile_id = (int)($_POST['profile_id'] ?? 0);

// Get seller_id and ad context
if ($ad_id) {
    $ad = DB::fetch("SELECT id, user_id, title, slug FROM ads WHERE id=? AND status='active'", [$ad_id]);
    if (!$ad) { flash('error','Anunțul nu mai este activ.'); redirect('/'); }
    if ($ad['user_id'] == $uid) { flash('error','Nu poți trimite mesaj propriului anunț.'); redirect('/anunt/'.$ad['slug']); }
    $seller_id = $ad['user_id'];
    $redirect_back = '/anunt/'.$ad['slug'];
} elseif ($profile_id) {
    if ($profile_id == $uid) { flash('error','Nu poți trimite mesaj ție însuți.'); redirect('/utilizator/'.$profile_id); }
    $seller_id = $profile_id;
    $ad = null;
    $redirect_back = '/utilizator/'.$profile_id;
} else {
    flash('error','Destinatar invalid.'); redirect('/');
}

// Check if conversation already exists
$existing = DB::fetch("
    SELECT id FROM conversations
    WHERE (buyer_id=? AND seller_id=?) OR (buyer_id=? AND seller_id=?)
    " . ($ad_id ? "AND ad_id=?" : "") . "
    LIMIT 1
", $ad_id
    ? [$uid, $seller_id, $seller_id, $uid, $ad_id]
    : [$uid, $seller_id, $seller_id, $uid]
);

if ($existing) {
    redirect('/mesaje/'.$existing['id']);
}

// Create new conversation
$conv_id = DB::insert('conversations', [
    'buyer_id'   => $uid,
    'seller_id'  => $seller_id,
    'ad_id'      => $ad_id ?: null,
    'buyer_unread'  => 0,
    'seller_unread' => 1,
]);

// Add welcome message
$welcome = $ad
    ? 'Bună ziua! Sunt interesat de anunțul dvs. "' . mb_substr($ad['title'],0,60) . '".'
    : 'Bună ziua! Aș dori să vă contactez.';

DB::insert('messages', [
    'conversation_id' => $conv_id,
    'sender_id'       => $uid,
    'body'            => $welcome,
]);

// Notification for seller
DB::query("INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)", [
    $seller_id, 'message',
    'Mesaj nou de la ' . current_user()['name'],
    mb_substr($welcome, 0, 100),
    '/mesaje/'.$conv_id
]);

redirect('/mesaje/'.$conv_id);
