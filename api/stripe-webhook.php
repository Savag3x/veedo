<?php
// api/stripe-webhook.php — Stripe webhooks
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/payments.php';

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret  = setting('stripe_webhook_secret', '');

// Semnătura este OBLIGATORIE. Fără secret configurat, webhook-ul nu acceptă nimic
// (altfel oricine ar putea marca o comandă ca plătită trimițând un JSON fals).
if (!$secret) {
    error_log('[veedo] stripe-webhook: stripe_webhook_secret neconfigurat — eveniment respins');
    http_response_code(503); exit('Webhook not configured');
}
if (!stripe_verify_signature($payload, $sig, $secret)) {
    http_response_code(400); exit('Invalid signature');
}

$event = json_decode($payload, true);
$type  = $event['type'] ?? '';
$obj   = $event['data']['object'] ?? [];

if ($type === 'payment_intent.succeeded') {
    $pi_id = (string)($obj['id'] ?? '');
    $txn = DB::fetch("SELECT * FROM orders WHERE transaction_id=? AND status='pending'", [$pi_id]);
    // Verificăm și suma încasată (în bani) față de comanda salvată
    if ($txn && (int)($obj['amount_received'] ?? $obj['amount'] ?? 0) >= (int)round((float)$txn['amount'] * 100)) {
        order_mark_paid((int)$txn['id']);
    } elseif ($txn) {
        error_log('[veedo] stripe-webhook: sumă nepotrivită pentru ' . $pi_id);
    }
} elseif ($type === 'payment_intent.payment_failed') {
    DB::query("UPDATE orders SET status='failed' WHERE transaction_id=? AND status='pending'", [(string)($obj['id'] ?? '')]);
} elseif ($type === 'charge.refunded') {
    $pi_id = (string)($obj['payment_intent'] ?? '');
    if ($pi_id) DB::query("UPDATE orders SET status='refunded' WHERE transaction_id=?", [$pi_id]);
}

http_response_code(200);
echo 'OK';
