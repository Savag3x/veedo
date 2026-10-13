<?php
// ============================================
// VEEDO - Logică comună pentru comenzi plătite
// Folosită de: api/stripe-webhook.php, api/boost-apply.php, api/netopia-ipn.php, admin (manual)
// ============================================

/**
 * Marchează comanda ca plătită și aplică efectele ei (boost / business / comision afiliere).
 * Atomic: doar cererea care reușește să treacă statusul din 'pending' în 'completed' aplică efectele,
 * deci webhook + confirmarea client-side + IPN repetat NU pot dubla boost-ul sau comisionul.
 *
 * @return bool true dacă această apelare a aplicat comanda, false dacă era deja procesată/inexistentă.
 */
function order_mark_paid(int $order_id): bool {
    $pdo = DB::get();
    $pdo->beginTransaction();
    try {
        $changed = DB::query("UPDATE orders SET status='completed' WHERE id=? AND status='pending'", [$order_id])->rowCount();
        if (!$changed) { $pdo->rollBack(); return false; }

        $txn = DB::fetch("SELECT * FROM orders WHERE id=?", [$order_id]);
        $user_id = (int)$txn['user_id'];

        if (str_starts_with((string)$txn['type'], 'boost_')) {
            $days = max(1, min(365, (int)str_replace('boost_', '', $txn['type'])));
            if ($txn['ad_id']) {
                // Dacă anunțul e deja promovat, prelungim de la data curentă de expirare
                DB::query(
                    "UPDATE ads SET is_boosted=1,
                        boosted_until=DATE_ADD(GREATEST(COALESCE(boosted_until, NOW()), NOW()), INTERVAL ? DAY)
                     WHERE id=? AND user_id=?",
                    [$days, $txn['ad_id'], $user_id]
                );
                $ad = DB::fetch("SELECT slug FROM ads WHERE id=?", [$txn['ad_id']]);
                add_notification($user_id, 'boost', "Anunțul tău a fost promovat ⭐ ({$days} zile)", '', $ad ? '/anunt/' . $ad['slug'] : '/cont/anunturile-mele');
            }
        } elseif ($txn['type'] === 'business_verify') {
            DB::query(
                "UPDATE users SET is_verified_business=1, role=IF(role IN ('admin','moderator'), role, 'business'),
                    business_verified_until=DATE_ADD(GREATEST(COALESCE(business_verified_until, NOW()), NOW()), INTERVAL 1 YEAR)
                 WHERE id=?",
                [$user_id]
            );
            add_notification($user_id, 'business', 'Contul tău Business a fost activat! 🏢', '', '/cont/setari');
        }

        // Comision afiliere (o singură dată per utilizator referit)
        if (setting_bool('affiliate_enabled')) {
            $referral = DB::fetch("SELECT id, referrer_id FROM referrals WHERE referred_id=? AND status='pending' FOR UPDATE", [$user_id]);
            if ($referral) {
                $pct = (float)setting('affiliate_commission_pct', setting('affiliate_commission', '10'));
                $commission = round((float)$txn['amount'] * max(0, min(50, $pct)) / 100, 2);
                if ($commission > 0) {
                    DB::update('referrals', [
                        'status'            => 'converted',
                        'commission_amount' => $commission,
                        'converted_at'      => date('Y-m-d H:i:s'),
                    ], 'id=?', [$referral['id']]);
                    DB::query("UPDATE users SET affiliate_balance=affiliate_balance+?, affiliate_total_earned=affiliate_total_earned+? WHERE id=?",
                        [$commission, $commission, $referral['referrer_id']]);
                    add_notification((int)$referral['referrer_id'], 'affiliate_commission',
                        'Comision afiliere primit: +' . number_format($commission, 2) . ' RON 💰', '', '/cont/afiliere');
                }
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[veedo] order_mark_paid #' . $order_id . ': ' . $e->getMessage());
        return false;
    }
}

/** Interogare Stripe API (GET) cu timeout și verificare TLS. */
function stripe_get(string $path): ?array {
    $sk = setting('stripe_secret_key', '');
    if (!$sk) return null;
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$sk:",
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = $res ? json_decode($res, true) : null;
    return is_array($j) ? $j : null;
}

/** Verifică semnătura unui webhook Stripe (header Stripe-Signature), cu toleranță de 5 minute. */
function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    if ($secret === '' || $header === '') return false;
    $ts = 0; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $ts = (int)$v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if (!$ts || !$sigs || abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) return true;
    }
    return false;
}
