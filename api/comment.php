<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') rate_limit_or_die('comment', 10, 60);
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// GET - load comments
if ($method === 'GET') {
    $ad_id = (int)($_GET['ad_id'] ?? 0);
    if (!$ad_id) json_response(['error'=>'Invalid'],400);

    $comments = DB::fetchAll("
        SELECT c.*, u.name as user_name, u.avatar as user_avatar, u.is_verified_business
        FROM ad_comments c
        JOIN users u ON u.id=c.user_id
        WHERE c.ad_id=? AND c.is_approved=1 AND c.parent_id IS NULL
        ORDER BY c.created_at DESC
        LIMIT 50
    ", [$ad_id]);

    // Load replies
    foreach ($comments as &$cm) {
        $cm['time_ago'] = time_ago($cm['created_at']);
        $cm['replies'] = DB::fetchAll("
            SELECT c.*, u.name as user_name, u.avatar as user_avatar
            FROM ad_comments c JOIN users u ON u.id=c.user_id
            WHERE c.parent_id=? AND c.is_approved=1
            ORDER BY c.created_at ASC LIMIT 10
        ", [$cm['id']]);
        foreach ($cm['replies'] as &$r) {
            $r['time_ago'] = time_ago($r['created_at']);
        }
    }

    json_response(['comments' => $comments, 'total' => count($comments)]);
}

// POST - add comment
if ($method === 'POST') {
    if (!is_logged()) json_response(['error'=>'Trebuie să fii conectat'],401);
    if (!csrf_verify()) json_response(['error'=>'CSRF invalid'],403);

    $uid       = current_user()['id'];
    $ad_id     = (int)($_POST['ad_id'] ?? 0);
    $body      = trim(sanitize($_POST['body'] ?? ''));
    $parent_id = (int)($_POST['parent_id'] ?? 0) ?: null;

    if (!$ad_id || strlen($body) < 2) json_response(['error'=>'Comentariu prea scurt'],400);
    if (strlen($body) > 1000) json_response(['error'=>'Comentariu prea lung (max 1000 caractere)'],400);

    $ad = DB::fetch("SELECT id, user_id, title, slug FROM ads WHERE id=? AND status='active'", [$ad_id]);
    if (!$ad) json_response(['error'=>'Anunț negăsit'],404);

    // Rate limit: max 5 comentarii pe minut
    $recent = DB::fetch("SELECT COUNT(*) as c FROM ad_comments WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)", [$uid]);
    if ($recent['c'] >= 5) json_response(['error'=>'Prea multe comentarii. Încearcă mai târziu.'],429);

    $comment_id = DB::insert('ad_comments', [
        'ad_id'     => $ad_id,
        'user_id'   => $uid,
        'parent_id' => $parent_id,
        'body'      => $body,
    ]);

    // Notify ad owner (if not own comment)
    if ($ad['user_id'] != $uid) {
        try {
            $me = current_user();
            DB::query("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)",
                [$ad['user_id'], 'comment',
                 $me['name'].' a comentat la anunțul tău',
                 mb_substr($body,0,100),
                 '/anunt/'.$ad['slug'].'#comments']);
        } catch (Exception $e) {}
    }

    $comment = DB::fetch("SELECT c.*, u.name as user_name, u.avatar as user_avatar FROM ad_comments c JOIN users u ON u.id=c.user_id WHERE c.id=?", [$comment_id]);
    $comment['time_ago'] = time_ago($comment['created_at']);
    $comment['replies'] = [];

    json_response(['comment' => $comment, 'success' => true]);
}

// DELETE
if ($method === 'DELETE') {
    if (!is_logged()) json_response(['error'=>'Neautorizat'],401);
    // CSRF pentru DELETE via header (trimis din JS cu fetch)
    $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_header)) {
        json_response(['error'=>'CSRF invalid'],403);
    }
    $uid = current_user()['id'];
    $comment_id = (int)($_GET['id'] ?? 0);
    $comment = DB::fetch("SELECT * FROM ad_comments WHERE id=?", [$comment_id]);
    if (!$comment) json_response(['error'=>'Negăsit'],404);
    if ($comment['user_id'] != $uid && !is_moderator()) json_response(['error'=>'Neautorizat'],403);
    DB::query("DELETE FROM ad_comments WHERE id=?", [$comment_id]);
    json_response(['success'=>true]);
}
