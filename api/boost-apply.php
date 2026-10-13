<?php
// api/boost-apply.php — Aplică boost după confirmare Stripe client-side
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/payments.php';
if (!is_logged()) json_response(['ok' => false, 'error' => 'Neautorizat'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false], 405);
rate_limit_or_die('boost_apply', 20, 3600);

$data = request_json();
$pi   = (string)($data['pi'] ?? ''); // PaymentIntent ID
if (!preg_match('/^pi_[A-Za-z0-9]{8,64}$/', $pi)) json_response(['ok' => false]);

// Comanda trebuie să aparțină utilizatorului curent
$txn = DB::fetch("SELECT * FROM orders WHERE transaction_id=? AND user_id=?", [$pi, current_user()['id']]);
if (!$txn) json_response(['ok' => false, 'msg' => 'Comandă negăsită']);
if ($txn['status'] !== 'pending') json_response(['ok' => true, 'msg' => 'Already applied']);

// Verificăm direct la Stripe că plata e confirmată și suma corespunde
$res = stripe_get('payment_intents/' . urlencode($pi));
if (($res['status'] ?? '') !== 'succeeded'
    || (int)($res['amount_received'] ?? 0) < (int)round((float)$txn['amount'] * 100)) {
    json_response(['ok' => false, 'msg' => 'Plata nu este confirmată']);
}

order_mark_paid((int)$txn['id']); // idempotent — dacă webhook-ul a ajuns primul, nu se aplică de două ori
json_response(['ok' => true]);
