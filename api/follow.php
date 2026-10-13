<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (!is_logged()) json_response(['error' => 'Trebuie să fii conectat.', 'redirect' => '/login'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);
rate_limit_or_die('follow', 30, 60);

$cu         = current_user();
$uid        = (int)$cu['id'];
$data       = request_json();
$target_id  = (int)($_POST['user_id'] ?? $data['user_id'] ?? 0);

if (!$target_id || $target_id === $uid) {
    json_response(['error' => 'Invalid'], 400);
}

$target = DB::fetch("SELECT id, name FROM users WHERE id=? AND is_active=1", [$target_id]);
if (!$target) json_response(['error' => 'Utilizator negăsit'], 404);

$existing = DB::fetch("SELECT id FROM follows WHERE follower_id=? AND following_id=?", [$uid, $target_id]);

if ($existing) {
    // Unfollow
    $del = DB::query("DELETE FROM follows WHERE follower_id=? AND following_id=?", [$uid, $target_id])->rowCount();
    if ($del) {
        DB::query("UPDATE users SET followers_count=GREATEST(0,followers_count-1) WHERE id=?", [$target_id]);
        DB::query("UPDATE users SET following_count=GREATEST(0,following_count-1) WHERE id=?", [$uid]);
    }
    $following = false;
} else {
    // Follow (INSERT IGNORE: cereri duble simultane nu dublează contoarele)
    $ins = DB::query("INSERT IGNORE INTO follows (follower_id, following_id) VALUES (?,?)", [$uid, $target_id])->rowCount();
    $following = true;

    if ($ins) {
        DB::query("UPDATE users SET followers_count=followers_count+1 WHERE id=?", [$target_id]);
        DB::query("UPDATE users SET following_count=following_count+1 WHERE id=?", [$uid]);

        // Notificare in-app
        add_notification($target_id, 'new_follower', $cu['name'] . ' te urmărește acum', 'Ai un follower nou pe Veedo!', '/utilizator/' . $uid);

        // Web Push
        try {
            if (function_exists('push_notify')) {
                push_notify($target_id, $cu['name'] . ' a început să te urmărească', 'Vezi profilul pe Veedo', '/utilizator/' . $uid);
            }
        } catch (Throwable $pe) {}

        // Notificare email
        $followed_user = DB::fetch("SELECT id, email, name, notification_email FROM users WHERE id=?", [$target_id]);
        if ($followed_user && !empty($followed_user['notification_email'])) {
            require_once __DIR__ . '/../includes/mailer.php';
            try { notify_new_follow($cu, $followed_user); } catch (Throwable $e) {}
        }
    }
}

$followers_count = (int)(DB::fetch("SELECT followers_count FROM users WHERE id=?", [$target_id])['followers_count'] ?? 0);

json_response([
    'following'       => $following,
    'followers_count' => $followers_count,
    'label'           => $following ? 'Urmărești' : 'Urmărește',
]);
