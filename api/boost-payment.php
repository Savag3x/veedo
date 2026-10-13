<?php
// api/boost-payment.php — Procesare plată boost (Stripe + Netopia)
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

$u    = current_user();
$data = request_json(); // CSRF verificat global în bootstrap

// Rate limiting: max 10 inițieri plată/oră per user (anti-spam)
rate_limit_or_die('boost_payment_' . $u['id'], 10, 3600);

$ad_id  = (int)($data['ad_id'] ?? 0);
$plan   = in_array($data['plan'] ?? '', ['7','30','business']) ? $data['plan'] : '7';
$method = in_array($data['method'] ?? '', ['stripe', 'netopia'], true) ? $data['method'] : 'stripe';

// Validare anunt (pentru boost) sau utilizator (pentru business)
if ($plan === 'business') {
    $price = (float)setting('verified_business_price', '199');
    $type  = 'business_verify';
    $days  = 365;
    $desc  = 'Verificare Business Veedo — 1 an';
} else {
    $ad = DB::fetch("SELECT * FROM ads WHERE id=? AND user_id=? AND status='active'", [$ad_id, $u['id']]);
    if (!$ad) { echo json_encode(['error' => 'Anunț invalid.']); exit; }
    
    $price = $plan === '30'
        ? (float)setting('boost_price_30days', '79')
        : (float)setting('boost_price_7days',  '29');
    $type  = "boost_{$plan}";
    $days  = (int)$plan;
    $desc  = "Boost anunț {$days} zile — " . mb_substr($ad['title'], 0, 50);
}

// ─── STRIPE ────────────────────────────────────────────────────────
if ($method === 'stripe') {
    $sk = setting('stripe_secret_key', '');
    if (!$sk) { echo json_encode(['error' => 'Stripe neconfigurat.']); exit; }

    $ch = curl_init('https://api.stripe.com/v1/payment_intents');
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => "$sk:",
        CURLOPT_POSTFIELDS     => http_build_query([
            'amount'   => (int)round($price * 100), // cents
            'currency' => 'ron',
            'description' => $desc,
            'metadata[user_id]' => $u['id'],
            'metadata[ad_id]'   => $ad_id,
            'metadata[type]'    => $type,
        ]),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    if (!is_array($res) || !empty($res['error']) || empty($res['id'])) {
        error_log('[veedo] stripe create PI: ' . ($res['error']['message'] ?? 'răspuns invalid'));
        echo json_encode(['error' => 'Plata nu a putut fi inițiată. Încearcă din nou.']);
        exit;
    }

    // Salvam tranzactia pending
    DB::insert('orders', [
        'user_id'        => $u['id'],
        'ad_id'          => $ad_id ?: null,
        'type'           => $type,
        'amount'         => $price,
        'payment_method' => 'stripe',
        'status'         => 'pending',
        'payment_id' => $res['id'], 'transaction_id' => $res['id'],
    ]);

    echo json_encode(['client_secret' => $res['client_secret']]);
    exit;
}

// ─── NETOPIA MobilPay ──────────────────────────────────────────────
if ($method === 'netopia') {
    $signature = setting('netopia_signature', '');
    $public_key = setting('netopia_public_key', '');
    
    if (!$signature || !$public_key) {
        echo json_encode(['error' => 'Netopia neconfigurat.']);
        exit;
    }

    $order_id = 'VD-' . $u['id'] . '-' . time() . '-' . bin2hex(random_bytes(3));
    
    // Salvam tranzactia pending
    $txn_id = DB::insert('orders', [
        'user_id'        => $u['id'],
        'ad_id'          => $ad_id ?: null,
        'type'           => $type,
        'amount'         => $price,
        'payment_method' => 'netopia',
        'status'         => 'pending',
        'payment_id' => $order_id, 'transaction_id' => $order_id,
    ]);

    // Build Netopia XML request
    $xml = '<?xml version="1.0" encoding="utf-8"?>' .
    '<order type="card" id="' . h($order_id) . '" timestamp="' . date('YmdHis') . '">
      <signature>' . h($signature) . '</signature>
      <url>
        <confirm>' . BASE_URL . '/api/netopia-ipn.php</confirm>
        <return>' . BASE_URL . '/cont/tranzactii?status=ok</return>
      </url>
      <invoice currency="RON" amount="' . number_format($price, 2, '.', '') . '" tokenId="" tokenExpirationDate=""
          panMasked="" description="' . h($desc) . '">
        <contact type="person">
          <firstName>' . h(explode(' ', $u['name'])[0]) . '</firstName>
          <lastName>' . h(explode(' ', $u['name'])[1] ?? '') . '</lastName>
          <email>' . h($u['email']) . '</email>
          <mobilePhone>0700000000</mobilePhone>
          <address>Romania</address>
        </contact>
      </invoice>
    </order>';

    // Encrypt with Netopia public key
    $encrypted = '';
    $env_key   = '';
    if (function_exists('openssl_public_encrypt') && $public_key) {
        $pub = openssl_get_publickey($public_key);
        if ($pub) {
            $aes_key = openssl_random_pseudo_bytes(32);
            $aes_iv  = openssl_random_pseudo_bytes(16);
            openssl_public_encrypt($aes_key . $aes_iv, $env_key, $pub);
            $env_key   = base64_encode($env_key);
            $encrypted = base64_encode(openssl_encrypt($xml, 'aes-256-cbc', $aes_key, OPENSSL_RAW_DATA, $aes_iv));
        }
    }

    if (!$encrypted) {
        // Fallback: redirect catre pagina de configurare
        echo json_encode(['error' => 'Netopia: eroare criptare. Verificați cheia publică în Admin.']);
        exit;
    }

    // Returnam form data pentru redirect POST catre Netopia
    $netopia_url = setting('netopia_live', '0') === '1'
        ? 'https://secure.mobilpay.ro'
        : 'https://sandboxsecure.mobilpay.ro';

    echo json_encode([
        'netopia_redirect' => true,
        'url'              => $netopia_url,
        'env_key'          => $env_key,
        'data'             => $encrypted,
    ]);
    exit;
}

echo json_encode(['error' => 'Metodă de plată invalidă.']);
