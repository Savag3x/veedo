<?php
// api/upload-progress.php — Upload progress via APC/uploadprogress
header('Content-Type: application/json');
session_start();

$key = $_GET['key'] ?? '';
if (!$key || !preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $key)) {
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

// Try APC upload progress
if (function_exists('apc_fetch')) {
    $data = apc_fetch('upload_' . $key);
    if ($data) {
        echo json_encode([
            'total'   => $data['total_bytes'],
            'current' => $data['current_bytes'],
            'percent' => $data['total_bytes'] > 0 ? round($data['current_bytes'] / $data['total_bytes'] * 100) : 0,
            'done'    => $data['done'] ?? false,
        ]);
        exit;
    }
}

// Try PHP session upload progress
if (isset($_SESSION['upload_' . $key])) {
    $s = $_SESSION['upload_' . $key];
    echo json_encode([
        'total'   => $s['content_length'] ?? 0,
        'current' => $s['bytes_processed'] ?? 0,
        'percent' => ($s['content_length'] ?? 0) > 0 ? round(($s['bytes_processed'] ?? 0) / $s['content_length'] * 100) : 0,
        'done'    => !empty($s['done']),
    ]);
    exit;
}

echo json_encode(['percent' => 0, 'total' => 0, 'current' => 0, 'done' => false]);
