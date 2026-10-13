<?php
// api/netopia-ipn.php — Netopia IPN (Instant Payment Notification)
require_once __DIR__ . '/../includes/bootstrap.php';

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
    
    $xml = simplexml_load_string($xml_str);
    if (!$xml) throw new Exception('Invalid XML');
    
    $order_id  = (string)$xml['id'];
    $action    = (string)$xml->mobilpay->action ?? '';
    $error_code = (string)$xml->mobilpay->error['code'] ?? '0';
    
    $txn = DB::fetch("SELECT * FROM orders WHERE transaction_id=?", [$order_id]);
    
    if ($txn) {
        if ($error_code === '0' && in_array($action, ['confirmed', 'paid_pending'])) {
            DB::update('orders', ['status' => 'completed'], 'id=?', [$txn['id']]);
            apply_transaction($txn);
        } elseif ($action === 'canceled' || $error_code !== '0') {
            DB::update('orders', ['status' => 'failed'], 'id=?', [$txn['id']]);
        } elseif ($action === 'credit') {
            DB::update('orders', ['status' => 'refunded'], 'id=?', [$txn['id']]);
        }
    }
    
} catch (Exception $e) {
    error_log('Netopia IPN error: ' . $e->getMessage());
    http_response_code(200); // Netopia needs 200 even on error
}

// Aplica efectul tranzactiei (boost, business verify etc)
function apply_transaction(array $txn): void {
    if (str_starts_with($txn['type'], 'boost_')) {
        $days = (int)str_replace('boost_', '', $txn['type']);
        if ($txn['ad_id']) {
            DB::query("UPDATE ads SET is_boosted=1, boosted_until=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?", [$days, $txn['ad_id']]);
        }
    } elseif ($txn['type'] === 'business_verify') {
        DB::query("UPDATE users SET is_verified_business=1, business_verified_until=DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id=?", [$txn['user_id']]);
    }
}

// Netopia response XML
header('Content-Type: text/xml');
echo '<?xml version="1.0" encoding="utf-8"?><crc></crc>';
