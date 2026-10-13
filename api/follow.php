<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (!is_logged()) json_response(['error' => 'Trebuie să fii conectat.'], 401);

$uid        = current_user()['id'];
$target_id  = (int)($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

if (!$target_id || $target_id === $uid) {
    json_response(['error' => 'Invalid'], 400);
}

$target = DB::fetch("SELECT id, name FROM users WHERE id=? AND is_active=1", [$target_id]);
if (!$target) json_response(['error' => 'Utilizator negăsit'], 404);

$existing = DB::fetch("SELECT id FROM follows WHERE follower_id=? AND following_id=?", [$uid, $target_id]);

if ($existing) {
    // Unfollow
    DB::query("DELETE FROM follows WHERE follower_id=? AND following_id=?", [$uid, $target_id]);
    DB::query("UPDATE users SET followers_count=GREATEST(0,followers_count-1) WHERE id=?", [$target_id]);
    DB::query("UPDATE users SET following_count=GREATEST(0,following_count-1) WHERE id=?", [$uid]);
    $following = false;
} else {
    // Follow
    DB::insert('follows', ['follower_id'=>$uid, 'following_id'=>$target_id]);
    DB::query("UPDATE users SET followers_count=followers_count+1 WHERE id=?", [$target_id]);
    DB::query("UPDATE users SET following_count=following_count+1 WHERE id=?", [$uid]);
    $following = true;

    // Notificare in-app
    DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
                // Web Push
                try { if (function_exists('push_notify')) { push_notify($target_id, $cu['name'] . ' a început să te urmărească', 'Vezi profilul lor pe Veedo', '/utilizator/' . $cu['id']);
    } } catch(Exception $pe) {}
        [$target_id, 'new_follower',
         current_user()['name'].' te urmărește acum',
         'Ai un follower nou pe Veedo!',
         '/utilizator/'.$uid]);
    
    // Notificare email (async — nu blocam raspunsul)
    $follower = current_user();
    $followed_user = DB::fetch("SELECT email, name, notification_email FROM users WHERE id=?", [$target_id]);
    if ($followed_user && !empty($followed_user['notification_email'])) {
        require_once __DIR__ . '/../includes/mailer.php';
        @notify_new_follow($follower, $followed_user);
    }
}

$followers_count = (int)DB::fetch("SELECT followers_count FROM users WHERE id=?", [$target_id])['followers_count'];

json_response([
    'following'       => $following,
    'followers_count' => $followers_count,
    'label'           => $following ? 'Urmărești' : 'Urmărește',
]);
