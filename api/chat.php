<?php
// api/chat.php — text + image messages
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';
if (!is_logged()) json_response(['error'=>'Unauthenticated'],401);
$uid = current_user()['id'];

function chat_img_url(?string $p): ?string {
    return $p ? BASE_URL . '/uploads/chat/' . rawurlencode($p) : null;
}
// Mesajele vechi erau salvate deja cu htmlspecialchars; acum salvăm textul brut și
// escapăm doar la afișare. Decodăm entitățile pentru compatibilitate cu mesajele vechi.
function chat_plain(?string $b): string {
    return html_entity_decode((string)$b, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conv_id = (int)($_GET['conv_id'] ?? 0);
    $last_id = (int)($_GET['last_id'] ?? 0);
    $conv = DB::fetch("SELECT * FROM conversations WHERE id=? AND (buyer_id=? OR seller_id=?)",[$conv_id,$uid,$uid]);
    if (!$conv) json_response(['error'=>'Not found'],404);
    $msgs = DB::fetchAll("SELECT m.*, u.name as sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.conversation_id=? AND m.id>? ORDER BY m.id ASC LIMIT 200",[$conv_id,$last_id]);
    foreach ($msgs as &$m) {
        $m['body']      = chat_plain($m['body']);
        $m['time_ago']  = time_ago($m['created_at']);
        $m['image_url'] = chat_img_url($m['image_path'] ?? null);
    }
    unset($m);
    if ($msgs) {
        // Scrieri doar când au sosit mesaje noi (polling-ul la 4s nu mai face UPDATE-uri inutile)
        DB::query("UPDATE conversations SET ".($uid==$conv['buyer_id']?'buyer_unread':'seller_unread')."=0 WHERE id=?",[$conv_id]);
        DB::query("UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_id!=? AND is_read=0",[$conv_id,$uid]);
        invalidate_unread_cache($uid);
    }
    $ru = DB::fetch("SELECT COALESCE(MAX(id),0) AS x FROM messages WHERE conversation_id=? AND sender_id=? AND is_read=1",[$conv_id,$uid]);
    json_response(['messages'=>$msgs, 'my_read_upto'=>(int)($ru['x'] ?? 0)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_or_die('chat_send', 30, 60);
    $is_image = !empty($_FILES['image']['name']);

    if ($is_image) {
        $conv_id = (int)($_POST['conv_id'] ?? 0);
        $caption = trim($_POST['message'] ?? '');
    } else {
        $data    = request_json();
        $conv_id = (int)($data['conv_id'] ?? 0);
        $caption = trim((string)($data['message'] ?? ''));
    }

    $conv = DB::fetch("SELECT * FROM conversations WHERE id=? AND (buyer_id=? OR seller_id=?)",[$conv_id,$uid,$uid]);
    if (!$conv) json_response(['error'=>'Not found'],404);

    $image_name = null;
    if ($is_image) {
        if (($_FILES['image']['size'] ?? 0) > 6 * 1024 * 1024) {
            json_response(['error'=>'Imaginea e prea mare (max 6MB).'],400);
        }
        $dir = __DIR__ . '/../uploads/chat/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $image_name = upload_photo($_FILES['image'], $dir);
        if (!$image_name) json_response(['error'=>'Fișier imagine invalid.'],400);
        if (mb_strlen($caption) > 2000) $caption = mb_substr($caption, 0, 2000);
    } else {
        if ($caption === '' || mb_strlen($caption) > 2000) json_response(['error'=>'Mesaj gol sau prea lung (max 2000 caractere).'],400);
    }

    // Text brut (fără tag-uri); escaparea se face la afișare
    $body = $caption !== '' ? sanitize($caption) : '';
    $row  = ['conversation_id'=>$conv_id, 'sender_id'=>$uid, 'body'=>$body];
    if ($image_name) $row['image_path'] = $image_name;
    $mid = DB::insert('messages', $row);

    $preview    = mb_substr($image_name ? ($body !== '' ? $body : '📷 Imagine') : $body, 0, 255);
    $other_id   = $uid == $conv['buyer_id'] ? $conv['seller_id'] : $conv['buyer_id'];
    $unread_col = $uid == $conv['buyer_id'] ? 'seller_unread' : 'buyer_unread';
    DB::query("UPDATE conversations SET last_message=?, last_message_at=NOW(), {$unread_col}={$unread_col}+1 WHERE id=?",[$preview,$conv_id]);

    // Notificare (max 1 / 30 min / conversatie)
    try {
        $recent = DB::fetch("SELECT id FROM notifications WHERE user_id=? AND type='message' AND link=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)",[$other_id,'/mesaje/'.$conv_id]);
        if (!$recent) {
            $me_name = DB::fetch("SELECT name,email FROM users WHERE id=?",[$uid]);
            $other   = DB::fetch("SELECT name,email FROM users WHERE id=?",[$other_id]);
            if ($me_name && $other) {
                $notif_body = $preview;
                DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
                    [$other_id,'message','Mesaj nou de la '.$me_name['name'],mb_substr($notif_body,0,100),'/mesaje/'.$conv_id]);
                try { if (function_exists('push_notify')) { push_notify($other_id, $me_name['name'].' ți-a trimis un mesaj', mb_substr($notif_body,0,80), '/mesaje/'.$conv_id); } } catch(Exception $pe) {}
                notify_new_message($me_name,$other,$notif_body,$conv_id);
            }
        }
    } catch (Exception $ex) {}

    invalidate_unread_cache($uid);
    $msg = DB::fetch("SELECT * FROM messages WHERE id=?",[$mid]);
    $msg['body']      = chat_plain($msg['body']);
    $msg['time_ago']  = time_ago($msg['created_at']);
    $msg['image_url'] = chat_img_url($msg['image_path'] ?? null);
    json_response(['message'=>$msg]);
}
