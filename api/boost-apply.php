<?php
// api/boost-apply.php — Aplică boost după confirmare Stripe client-side
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$pi   = $data['pi'] ?? ''; // PaymentIntent ID

if (!$pi) { echo json_encode(['ok'=>false]); exit; }

$sk = setting('stripe_secret_key','');
if (!$sk) { echo json_encode(['ok'=>false]); exit; }

// Verificam cu Stripe ca plata e confirmata
$ch = curl_init("https://api.stripe.com/v1/payment_intents/" . urlencode($pi));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>"$sk:"]);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

if (($res['status'] ?? '') !== 'succeeded') {
    echo json_encode(['ok'=>false,'msg'=>'Plata nu este confirmată']); exit;
}

// Gasim tranzactia pending
$txn = DB::fetch("SELECT * FROM orders WHERE transaction_id=? AND status='pending'", [$pi]);
if (!$txn) { echo json_encode(['ok'=>true,'msg'=>'Already applied']); exit; }

// Aplicam
DB::update('orders', ['status'=>'completed'], 'id=?', [$txn['id']]);

if (str_starts_with($txn['type'], 'boost_')) {
    $days = (int)str_replace('boost_', '', $txn['type']);
    DB::query("UPDATE ads SET is_boosted=1, boosted_until=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?", [$days, $txn['ad_id']]);
    DB::insert('notifications', [
        'user_id' => $txn['user_id'],
        'type'    => 'boost',
        'title'   => "Anunțul tău a fost promovat ⭐ ({$days} zile)",
        'link'    => '/anunt/' . ($txn['ad_id'] ?? ''),
    ]);
} elseif ($txn['type'] === 'business_verify') {
    DB::query("UPDATE users SET is_verified_business=1, role='business', business_verified_until=DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id=?", [$txn['user_id']]);
    DB::insert('notifications', [
        'user_id' => $txn['user_id'],
        'type'    => 'business',
        'title'   => 'Contul tău Business a fost activat! 🏢',
        'link'    => '/cont/setari',
    ]);
}

// Aplica comision afiliere daca utilizatorul a fost referit
$referral = DB::fetch("SELECT r.referrer_id, r.id FROM referrals r WHERE r.referred_id=? AND r.status='pending'", [$txn['user_id']]);
if ($referral && setting('affiliate_enabled','1')) {
    $commission_pct = (float)setting('affiliate_commission_pct','10') / 100;
    $commission = round($txn['amount'] * $commission_pct, 2);
    if ($commission > 0) {
        DB::update('referrals', [
            'status' => 'converted',
            'commission_amount' => $commission,
            'converted_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$referral['id']]);
        DB::query("UPDATE users SET affiliate_balance=affiliate_balance+?, affiliate_total_earned=affiliate_total_earned+? WHERE id=?",
            [$commission, $commission, $referral['referrer_id']]);
        DB::insert('notifications', [
            'user_id' => $referral['referrer_id'],
            'type'    => 'affiliate_commission',
            'title'   => 'Comision afiliere primit: +'.number_format($commission,2).' RON 💰',
            'link'    => '/cont/afiliere',
        ]);
    }
}

echo json_encode(['ok'=>true]);
