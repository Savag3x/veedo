<?php
// api/netopia-ipn.php — Netopia IPN (Instant Payment Notification)
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/payments.php';

// ── IP Whitelist Netopia ──────────────────────────────────────────────
// Acceptăm doar request-uri din rețelele Netopia sau din CLI (teste locale)
// Lista actualizată: https://netopia-payments.com/ips
$netopia_ip_prefixes = [
    '93.113.243.',
    '93.113.244.',
    '195.20.18.',
    '195.20.19.',
    '79.111.119.',
];
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ip_allowed = (php_sapi_name() === 'cli'); // CLI e întotdeauna permis (teste)
if (!$ip_allowed) {
    foreach ($netopia_ip_prefixes as $prefix) {
        if (str_starts_with($remote_ip, $prefix)) { $ip_allowed = true; break; }
    }
}
if (!$ip_allowed) {
    error_log('Netopia IPN blocked IP: ' . $remote_ip);
    http_response_code(403);
    exit('Forbidden');
}

// Netopia trimite POST cu env_key + data
$env_key = $_POST['env_key'] ?? '';
$data    = $_POST['data'] ?? '';

if (!$env_key || !$data) {
    http_response_code(400);
    exit('Invalid request');
}

$private_key = setting('netopia_private_key', '');
if (!$private_key) {
    http_response_code(500);
    exit('Not configured');
}

// Decriptare
try {
    $priv = openssl_get_privatekey($private_key);
    if (!$priv) throw new Exception('Invalid private key');
    
    $env_key_dec = base64_decode($env_key);
    openssl_private_decrypt($env_key_dec, $decrypted_key, $priv);
    
    $aes_key = substr($decrypted_key, 0, 32);
    $aes_iv  = substr($decrypted_key, 32, 16);
    $xml_str = openssl_decrypt(base64_decode($data), 'aes-256-cbc', $aes_key, OPENSSL_RAW_DATA, $aes_iv);
    
    if (!$xml_str) throw new Exception('Decryption failed');
    
    // LIBXML_NONET: fără acces la rețea la parsare (anti-XXE/SSRF)
    $xml = simplexml_load_string($xml_str, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) throw new Exception('Invalid XML');
    
    $order_id  = (string)$xml['id'];
    $action     = (string)($xml->mobilpay->action ?? '');
    $error_code = (string)($xml->mobilpay->error['code'] ?? '0');
    $paid_amount = (float)($xml->mobilpay->original_amount ?? $xml->mobilpay->processed_amount ?? 0);
    
    $txn = DB::fetch("SELECT * FROM orders WHERE transaction_id=?", [$order_id]);
    
    if ($txn) {
        // Doar 'confirmed' înseamnă bani încasați ('paid_pending' = încă în verificare)
        if ($error_code === '0' && $action === 'confirmed') {
            if ($paid_amount > 0 && $paid_amount + 0.01 < (float)$txn['amount']) {
                error_log('[veedo] netopia-ipn: sumă nepotrivită pentru ' . $order_id);
            } else {
                order_mark_paid((int)$txn['id']); // atomic + idempotent
            }
        } elseif ($action === 'canceled' || $error_code !== '0') {
            DB::query("UPDATE orders SET status='failed' WHERE id=? AND status='pending'", [$txn['id']]);
        } elseif ($action === 'credit') {
            DB::update('orders', ['status' => 'refunded'], 'id=?', [$txn['id']]);
        }
    }
    
} catch (Exception $e) {
    error_log('Netopia IPN error: ' . $e->getMessage());
    http_response_code(200); // Netopia needs 200 even on error
}

// Netopia response XML
header('Content-Type: text/xml');
echo '<?xml version="1.0" encoding="utf-8"?><crc></crc>';
