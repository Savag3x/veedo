<?php
// api/stripe-webhook.php — Stripe webhooks
require_once __DIR__ . '/../includes/bootstrap.php';

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret  = setting('stripe_webhook_secret', '');

if ($secret) {
    // Verify signature
    $ts = 0; $sig_hash = '';
    foreach (explode(',', $sig) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        if ($k === 't') $ts = (int)$v;
        if ($k === 'v1') $sig_hash = $v;
    }
    $expected = hash_hmac('sha256', "$ts.$payload", $secret);
    if (!hash_equals($expected, $sig_hash)) {
        http_response_code(400); exit('Invalid signature');
    }
}

$event = json_decode($payload, true);
$type  = $event['type'] ?? '';

if ($type === 'payment_intent.succeeded') {
    $pi     = $event['data']['object'];
    $pi_id  = $pi['id'];
    $meta   = $pi['metadata'] ?? [];

    $txn = DB::fetch("SELECT * FROM orders WHERE transaction_id=? AND status='pending'", [$pi_id]);
    if ($txn) {
        DB::update('orders', ['status'=>'completed'], 'id=?', [$txn['id']]);

        if (str_starts_with($txn['type'], 'boost_')) {
            $days = (int)str_replace('boost_', '', $txn['type']);
            DB::query("UPDATE ads SET is_boosted=1, boosted_until=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?", [$days, $txn['ad_id']]);
        } elseif ($txn['type'] === 'business_verify') {
            DB::query("UPDATE users SET is_verified_business=1, role='business', business_verified_until=DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id=?", [$txn['user_id']]);
        }
    }
} elseif ($type === 'payment_intent.payment_failed') {
    $pi_id = $event['data']['object']['id'];
    DB::query("UPDATE orders SET status='failed' WHERE transaction_id=?", [$pi_id]);
} elseif ($type === 'charge.refunded') {
    $pi_id = $event['data']['object']['payment_intent'] ?? '';
    if ($pi_id) DB::query("UPDATE orders SET status='refunded' WHERE transaction_id=?", [$pi_id]);
}

http_response_code(200);
echo 'OK';
