<?php
// api/live-viewers.php — Heartbeat vizualizatori live
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$stream_id = (int)($_POST['stream_id'] ?? $_GET['stream_id'] ?? 0);
if (!$stream_id) json_response(['error' => 'Invalid'], 400);

$stream = DB::fetch("SELECT id, status, viewers_count FROM live_streams WHERE id=? AND status='live'", [$stream_id]);
if (!$stream) json_response(['status' => 'ended', 'viewers' => 0]);

// Incrementam viewers (simplu - in productie s-ar folosi Redis)
// Folosim un sistem bazat pe IP hash cu TTL
$ip_hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d-H'));
$key     = "live_viewer_{$stream_id}_{$ip_hash}";

// Actualizam count la fiecare heartbeat (la 30s)
// Numaram viewers unici in ultimul minut
DB::query("
    INSERT INTO rate_limits (rate_key, hits, expires_at)
    VALUES (?, 1, DATE_ADD(NOW(), INTERVAL 90 SECOND))
    ON DUPLICATE KEY UPDATE hits=1, expires_at=DATE_ADD(NOW(), INTERVAL 90 SECOND)
", [$key]);

$viewers = (int)DB::fetch("
    SELECT COUNT(*) as c FROM rate_limits
    WHERE rate_key LIKE ? AND expires_at > NOW()
", ["live_viewer_{$stream_id}_%"])['c'];

// Update in streams table
DB::query("UPDATE live_streams SET viewers_count=? WHERE id=?", [$viewers, $stream_id]);

json_response(['status' => 'live', 'viewers' => $viewers]);