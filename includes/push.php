<?php
// includes/push.php — Web Push VAPID pure PHP (fara composer)
// Bazat pe RFC 8292 (VAPID) si RFC 7516 (JWE)

/**
 * Trimite Web Push notification la un subscriber
 */
function push_send_to_subscription(array $sub, array $payload, array $vapid): bool {
    $endpoint = $sub['endpoint'];
    $p256dh   = $sub['p256dh'];
    $auth     = $sub['auth'];

    // Encrypt payload cu ECDH
    $encrypted = push_encrypt($payload, $p256dh, $auth);
    if (!$encrypted) return false;

    // Generam JWT VAPID
    $jwt = push_vapid_jwt($endpoint, $vapid['private_key'], $vapid['public_key'], $vapid['subject']);
    if (!$jwt) return false;

    // HTTP request catre push service
    $headers = [
        'Authorization: vapid t=' . $jwt . ',k=' . $vapid['public_key'],
        'Content-Type: application/octet-stream',
        'Content-Encoding: aesgcm',
        'Encryption: salt=' . $encrypted['salt'],
        'Crypto-Key: dh=' . $encrypted['server_public_key'] . ';p256ecdsa=' . $vapid['public_key'],
        'TTL: 86400',
        'Urgency: normal',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $encrypted['ciphertext'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

/**
 * Trimite notificare la toti subscriberii unui user
 */
function push_notify(int $user_id, string $title, string $body, string $url = '/', ?int $notif_id = null, ?string $icon = null): int {
    if (!defined('VAPID_PUBLIC_KEY') || !VAPID_PUBLIC_KEY) return 0;

    $subs = DB::fetchAll(
        "SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id=?",
        [$user_id]
    );
    if (empty($subs)) return 0;

    $payload = json_encode([
        'title'    => $title,
        'body'     => $body,
        'url'      => $url,
        'notif_id' => $notif_id,
        'icon'     => $icon ?? (defined('BASE_URL') ? BASE_URL . '/assets/icon-192.png' : '/assets/icon-192.png'),
        'badge'    => defined('BASE_URL') ? BASE_URL . '/assets/badge-72.png' : '/assets/badge-72.png',
        'tag'      => 'veedo-' . ($notif_id ?? time()),
    ], JSON_UNESCAPED_UNICODE);

    $vapid = [
        'public_key'  => VAPID_PUBLIC_KEY,
        'private_key' => VAPID_PRIVATE_KEY,
        'subject'     => 'mailto:admin@veedo.ro',
    ];

    $sent = 0;
    $expired = [];

    foreach ($subs as $sub) {
        try {
            $ok = push_send_to_subscription($sub, $payload, $vapid);
            if ($ok) {
                $sent++;
            } else {
                $expired[] = $sub['endpoint'];
            }
        } catch (Exception $e) {
            $expired[] = $sub['endpoint'];
        }
    }

    // Stergem subscriptiile expirate
    foreach ($expired as $ep) {
        DB::query("DELETE FROM push_subscriptions WHERE endpoint=?", [$ep]);
    }

    return $sent;
}

/**
 * Encrypt payload pentru Web Push (aesgcm)
 */
function push_encrypt(string $payload, string $client_public_key_b64, string $auth_b64): ?array {
    if (!function_exists('openssl_pkey_new')) return null;

    try {
        $client_public_key = push_base64url_decode($client_public_key_b64);
        $auth              = push_base64url_decode($auth_b64);

        // Generam server ECDH key pair
        $server_key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$server_key) return null;

        $server_key_details = openssl_pkey_get_details($server_key);
        $server_public_key  = $server_key_details['ec']['x'] . $server_key_details['ec']['y'];
        $server_public_key  = "\x04" . $server_public_key;

        // ECDH shared secret
        $client_key_pem = push_ec_public_key_to_pem($client_public_key);
        $client_key_obj = openssl_pkey_get_public($client_key_pem);
        openssl_dh_compute_key($shared_secret, $client_key_obj, $server_key);

        // Salt aleator 16 bytes
        $salt = random_bytes(16);

        // HKDF pentru cheile de criptare
        $prk = push_hkdf($auth, $shared_secret,
            "Content-Encoding: auth\0", 32);
        $content_encryption_key = push_hkdf($salt, $prk,
            push_create_info('aesgcm', $client_public_key, $server_public_key), 16);
        $nonce = push_hkdf($salt, $prk,
            push_create_info('nonce', $client_public_key, $server_public_key), 12);

        // Padding + encrypt
        $padded = "\x00\x00" . $payload; // 2 bytes padding length = 0
        $tag    = '';
        $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $content_encryption_key,
            OPENSSL_RAW_DATA, $nonce, $tag);

        return [
            'ciphertext'       => $ciphertext . $tag,
            'salt'             => push_base64url_encode($salt),
            'server_public_key'=> push_base64url_encode($server_public_key),
        ];
    } catch (Exception $e) {
        return null;
    }
}

/**
 * VAPID JWT
 */
function push_vapid_jwt(string $endpoint, string $private_key_b64, string $public_key_b64, string $subject): ?string {
    try {
        $parsed = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];

        $header  = push_base64url_encode(json_encode(['typ'=>'JWT','alg'=>'ES256']));
        $claims  = push_base64url_encode(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => $subject,
        ]));

        $unsigned = $header . '.' . $claims;

        // Load private key
        $private_key_raw = push_base64url_decode($private_key_b64);
        $pem = push_ec_private_key_to_pem($private_key_raw, push_base64url_decode($public_key_b64));
        $key = openssl_pkey_get_private($pem);
        if (!$key) return null;

        openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);

        // Convert DER signature to raw r||s
        $signature = push_der_to_raw_sig($signature);

        return $unsigned . '.' . push_base64url_encode($signature);
    } catch (Exception $e) {
        return null;
    }
}

// ── Helpers ───────────────────────────────────────────────

function push_hkdf(string $salt, string $ikm, string $info, int $length): string {
    $prk  = hash_hmac('sha256', $ikm, $salt, true);
    $t    = '';
    $okm  = '';
    for ($i = 1; strlen($okm) < $length; $i++) {
        $t   = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $okm .= $t;
    }
    return substr($okm, 0, $length);
}

function push_create_info(string $type, string $client_pub, string $server_pub): string {
    return "Content-Encoding: $type\0P-256\0"
        . chr(0) . chr(strlen($client_pub)) . $client_pub
        . chr(0) . chr(strlen($server_pub)) . $server_pub;
}

function push_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function push_base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function push_ec_public_key_to_pem(string $raw): string {
    $header = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($header . $raw), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function push_ec_private_key_to_pem(string $private_raw, string $public_raw): string {
    // SEC1 ECPrivateKey format
    $key = "\x30\x77\x02\x01\x01\x04\x20" . $private_raw
         . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
         . "\xa1\x44\x03\x42\x00" . $public_raw;
    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($key), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

function push_der_to_raw_sig(string $der): string {
    // Parse DER SEQUENCE { INTEGER r, INTEGER s }
    $offset = 2; // skip SEQUENCE tag + length
    $r_len  = ord($der[$offset + 1]);
    $r      = substr($der, $offset + 2, $r_len);
    $offset += 2 + $r_len + 1; // skip INTEGER tag
    $s_len  = ord($der[$offset]);
    $s      = substr($der, $offset + 1, $s_len);
    // Pad to 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}
