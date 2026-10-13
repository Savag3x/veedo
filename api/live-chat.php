<?php
// api/live-chat.php — Chat LIVE Shopping (stocare fisier local)
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$stream_id = (int)($_GET['stream_id'] ?? 0);
if (!$stream_id) json_response(['error' => 'Invalid stream'], 400);

$stream = DB::fetch("SELECT id, status, user_id FROM live_streams WHERE id=?", [$stream_id]);
if (!$stream) json_response(['error' => 'Stream not found'], 404);

// ── Fisier JSON pentru chat ───────────────────────────────
$chat_dir  = BASE_PATH . '/uploads/live_chats/';
$chat_file = $chat_dir . 'stream_' . $stream_id . '.json';

// Cream directorul daca nu exista
if (!is_dir($chat_dir)) {
    mkdir($chat_dir, 0755, true);
    // Protejam directorul
    file_put_contents($chat_dir . '.htaccess', "deny from all\n");
}

// Curatam chaturile vechi (fisiere mai vechi de 7 zile)
foreach (glob($chat_dir . 'stream_*.json') as $f) {
    if (filemtime($f) < time() - 7 * 86400) @unlink($f);
}

// Citim mesajele existente
function read_chat(string $file): array {
    if (!file_exists($file)) return [];
    $data = @json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// Scriem mesajele (cu lock pentru concurenta)
function write_chat(string $file, array $msgs): void {
    // Pastram doar ultimele 500 mesaje
    if (count($msgs) > 500) $msgs = array_slice($msgs, -500);
    $fh = fopen($file, 'c');
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($msgs, JSON_UNESCAPED_UNICODE));
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

$method = $_SERVER['REQUEST_METHOD'];
$u      = current_user();

// ── GET: fetch mesaje noi ─────────────────────────────────
if ($method === 'GET') {
    $since_id = (int)($_GET['since'] ?? 0);
    $msgs     = read_chat($chat_file);

    // Filtram doar mesaje dupa since_id si nedeletate
    $new = array_values(array_filter($msgs, fn($m) =>
        $m['id'] > $since_id && empty($m['deleted'])
    ));

    // Formatam pentru JS
    foreach ($new as &$m) {
        if (empty($m['avatar_url'])) {
            $m['avatar_url'] = 'https://ui-avatars.com/api/?name=' . urlencode($m['user_name']) . '&background=E8380D&color=fff&size=60';
        }
    }

    $viewers = (int)DB::fetch("SELECT viewers_count FROM live_streams WHERE id=?", [$stream_id])['viewers_count'];

    json_response([
        'messages' => $new,
        'viewers'  => $viewers,
        'status'   => $stream['status'],
    ]);
}

// ── POST: actiuni ─────────────────────────────────────────
if ($method === 'POST') {
    if (!$u) json_response(['error' => 'Trebuie să fii conectat.', 'redirect' => '/login'], 401);

    $data = json_decode(file_get_contents('php://input'), true);

    // Delete mesaj (owner sau admin)
    if (!empty($data['delete_id']) && ($u['id'] == $stream['user_id'] || $u['role'] === 'admin')) {
        $msgs = read_chat($chat_file);
        foreach ($msgs as &$m) {
            if ($m['id'] == (int)$data['delete_id']) $m['deleted'] = true;
        }
        write_chat($chat_file, $msgs);
        json_response(['ok' => true]);
    }

    // Pin mesaj (doar owner)
    if (!empty($data['pin_id']) && $u['id'] == $stream['user_id']) {
        $msgs = read_chat($chat_file);
        foreach ($msgs as &$m) {
            $m['is_pinned'] = ($m['id'] == (int)$data['pin_id']);
        }
        write_chat($chat_file, $msgs);
        json_response(['ok' => true]);
    }

    // Trimite mesaj
    $msg = trim(sanitize($data['message'] ?? ''));
    if (strlen($msg) < 1)   json_response(['error' => 'Mesajul e gol.'], 400);
    if (strlen($msg) > 300) json_response(['error' => 'Max 300 caractere.'], 400);

    // Rate limit simplu per user (fisier)
    $rl_file = $chat_dir . 'rl_' . $u['id'] . '.txt';
    $now     = time();
    $times   = file_exists($rl_file) ? array_filter(explode(',', file_get_contents($rl_file)), fn($t) => (int)$t > $now - 5) : [];
    if (count($times) >= 8) json_response(['error' => 'Prea rapid! Așteaptă puțin.'], 429);
    $times[] = $now;
    file_put_contents($rl_file, implode(',', $times));

    // Generam ID unic
    $msgs   = read_chat($chat_file);
    $max_id = empty($msgs) ? 0 : max(array_column($msgs, 'id'));
    $new_id = $max_id + 1;

    $avatar_url = $u['avatar']
        ? BASE_URL . '/uploads/avatars/' . $u['avatar']
        : 'https://ui-avatars.com/api/?name=' . urlencode($u['name']) . '&background=E8380D&color=fff&size=60';

    $new_msg = [
        'id'         => $new_id,
        'user_id'    => $u['id'],
        'user_name'  => $u['name'],
        'avatar_url' => $avatar_url,
        'message'    => $msg,
        'is_pinned'  => false,
        'time'       => date('H:i'),
    ];

    $msgs[] = $new_msg;
    write_chat($chat_file, $msgs);

    json_response(['ok' => true, 'message' => $new_msg]);
}

json_response(['error' => 'Method not allowed'], 405);