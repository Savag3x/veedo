<?php
// ============================================
// VEEDO Mailer — SMTP + mail() fallback
// ============================================

function veedo_mail(string $to, string $name, string $subject, string $body_html): bool {
    $from_email = setting('smtp_from_email') ?: setting('smtp_from') ?: setting('site_email') ?: 'noreply@veedo.ro';
    $from_name  = setting('smtp_from_name') ?: setting('site_name', 'Veedo');
    $site_url   = BASE_URL;

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#F5F5F5;font-family:Arial,sans-serif;font-size:15px;color:#333;padding:20px;}
.wrap{max-width:580px;margin:0 auto;}
.logo{text-align:center;padding:24px 0 20px;font-size:1.6rem;font-weight:900;color:#111;}
.logo span{color:#E8380D;}
.card{background:#fff;border-radius:12px;padding:32px;margin-bottom:16px;}
h2{font-size:1.2rem;margin-bottom:14px;color:#111;}
p{line-height:1.7;color:#444;margin-bottom:12px;}
.btn{display:inline-block;background:#E8380D;color:#fff!important;padding:12px 28px;border-radius:100px;text-decoration:none;font-weight:700;font-size:15px;margin:8px 0;}
.footer{text-align:center;font-size:12px;color:#aaa;padding:16px 0;}
.footer a{color:#aaa;}
hr{border:none;border-top:1px solid #eee;margin:16px 0;}
</style></head><body>
<div class="wrap">
  <div class="logo"><span>▶</span> '.$from_name.'</div>
  <div class="card">'.$body_html.'</div>
  <div class="footer">© '.date('Y').' '.$from_name.' · <a href="'.$site_url.'">'.$site_url.'</a><br>Dacă nu ai solicitat acest email, îl poți ignora.</div>
</div></body></html>';

    // Incearca SMTP daca e configurat
    $smtp_host = setting('smtp_host','');
    if ($smtp_host) {
        $result = smtp_send($to, $name, $from_email, $from_name, $subject, $html);
        if ($result) return true;
        error_log("Veedo SMTP failed for $to, falling back to mail()");
    }

    // Fallback: PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_email}\r\n";
    $headers .= "X-Mailer: PHP/".phpversion()."\r\n";
    $enc_subject = '=?UTF-8?B?'.base64_encode($subject).'?=';
    return @mail($to, $enc_subject, $html, $headers);
}

// ─── SMTP direct (fara librarie externa) ──────────────────────────
function smtp_send(string $to, string $to_name, string $from, string $from_name, string $subject, string $html): bool {
    $host    = setting('smtp_host','');
    $port    = (int)setting('smtp_port','587');
    $user    = setting('smtp_user','');
    $pass    = setting('smtp_pass','');

    if (!$host || !$user || !$pass) return false;

    try {
        // Conectare socket
        $ctx = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false]]);
        $proto = ($port === 465) ? 'ssl' : 'tcp';
        $sock = stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) return false;

        $read = function() use ($sock) { return fgets($sock, 1024); };
        $send = function(string $cmd) use ($sock) { fwrite($sock, $cmd."\r\n"); };

        $read(); // 220 banner
        $send("EHLO veedo.local");
        while (($r = $read()) && substr($r,3,1) === '-') {}; // read multi-line

        if ($port === 587) {
            $send("STARTTLS");
            $read(); // 220
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send("EHLO veedo.local");
            while (($r = $read()) && substr($r,3,1) === '-') {};
        }

        $send("AUTH LOGIN");
        $read(); // 334
        $send(base64_encode($user));
        $read(); // 334
        $send(base64_encode($pass));
        $r = $read();
        if (substr($r,0,3) !== '235') { fclose($sock); return false; }

        $send("MAIL FROM:<{$from}>");
        $read();
        $send("RCPT TO:<{$to}>");
        $read();
        $send("DATA");
        $read();

        $enc_subj = '=?UTF-8?B?'.base64_encode($subject).'?=';
        $boundary = md5(uniqid());
        $msg  = "From: =?UTF-8?B?".base64_encode($from_name)."?= <{$from}>\r\n";
        $msg .= "To: =?UTF-8?B?".base64_encode($to_name)."?= <{$to}>\r\n";
        $msg .= "Subject: {$enc_subj}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($html))."\r\n";
        $msg .= ".";
        $send($msg);
        $r = $read();
        $send("QUIT");
        fclose($sock);
        return substr($r,0,3) === '250';
    } catch (Throwable $e) {
        error_log('SMTP error: '.$e->getMessage());
        return false;
    }
}


// ─── Verifica daca utilizatorul vrea email de tipul respectiv ─────
function user_wants_email(int $user_id, string $type): bool {
    $col_map = [
        'message'   => 'notif_email_messages',
        'ad_status' => 'notif_email_ad_status',
        'review'    => 'notif_email_reviews',
        'follow'    => 'notif_email_follows',
    ];
    $col = $col_map[$type] ?? null;
    if (!$col) return true; // tip necunoscut => trimite
    $u = DB::fetch("SELECT {$col} FROM users WHERE id=?", [$user_id]);
    return (bool)($u[$col] ?? 1);
}

// ─── Notificări specifice ─────────────────────────────────────────

function notify_welcome(array $user): void {
    veedo_mail($user['email'], $user['name'], 'Bun venit! 🎉',
        '<h2>Bun venit, '.h($user['name']).'! 🎉</h2>
        <p>Contul tău a fost creat cu succes. Ești gata să postezi primul anunț video!</p>
        <a href="'.BASE_URL.'/anunt/nou" class="btn">+ Postează primul anunț</a>
        <hr>
        <p style="font-size:13px;color:#888;">Descoperă anunțuri, urmărește vânzători și interacționează cu comunitatea.</p>'
    );
}

function notify_ad_approved(array $ad, array $user): void {
    if (!empty($user['id']) && !user_wants_email((int)$user['id'], 'ad_status')) return;
    veedo_mail($user['email'], $user['name'], 'Anunțul tău este live! ✅',
        '<h2>Anunțul tău a fost aprobat! ✅</h2>
        <p>Bună <strong>'.h($user['name']).'</strong>,</p>
        <p>Anunțul <strong>"'.h(mb_substr($ad['title'],0,80)).'"</strong> este acum activ pe platformă.</p>
        <a href="'.BASE_URL.'/anunt/'.h($ad['slug']).'" class="btn">Vizualizează anunțul →</a>
        <hr>
        <p style="font-size:13px;color:#888;">Vrei mai multă vizibilitate? <a href="'.BASE_URL.'/boost/'.(int)$ad['id'].'">Promovează anunțul</a> pentru a apărea primul.</p>'
    );
}

function notify_ad_rejected(array $ad, array $user, string $reason = ''): void {
    if (!empty($user['id']) && !user_wants_email((int)$user['id'], 'ad_status')) return;
    veedo_mail($user['email'], $user['name'], 'Anunțul tău a fost respins',
        '<h2>Anunțul tău nu a fost aprobat</h2>
        <p>Bună <strong>'.h($user['name']).'</strong>,</p>
        <p>Anunțul <strong>"'.h(mb_substr($ad['title'],0,80)).'"</strong> nu respectă regulile platformei.</p>
        '.($reason ? '<div style="background:#FEF2F2;border-left:3px solid #EF4444;padding:12px 16px;border-radius:0 8px 8px 0;margin:12px 0;"><strong>Motiv:</strong> '.h($reason).'</div>' : '').
        '<a href="'.BASE_URL.'/anunt/nou" class="btn">Postează din nou →</a>'
    );
}

function notify_new_message(array $from_user, array $to_user, string $msg_preview, int $conv_id): void {
    if (!empty($to_user['id']) && !user_wants_email((int)$to_user['id'], 'message')) return;
    veedo_mail($to_user['email'], $to_user['name'],
        'Mesaj nou de la ' . h($from_user['name']),
        '<h2>Ai un mesaj nou! 💬</h2>
        <p>Bună <strong>'.h($to_user['name']).'</strong>,</p>
        <p><strong>'.h($from_user['name']).'</strong> ți-a trimis un mesaj:</p>
        <div style="background:#F5F5F5;border-left:3px solid #E8380D;padding:12px 16px;border-radius:0 8px 8px 0;margin:12px 0;font-style:italic;">
          "'.h(mb_substr($msg_preview,0,200)).'"
        </div>
        <a href="'.BASE_URL.'/mesaje/'.$conv_id.'" class="btn">Răspunde →</a>'
    );
}

function notify_new_review(array $reviewer, array $reviewed, int $rating, string $comment): void {
    if (!empty($reviewed['id']) && !user_wants_email((int)$reviewed['id'], 'review')) return;
    $stars = str_repeat('★', $rating) . str_repeat('☆', 5-$rating);
    veedo_mail($reviewed['email'], $reviewed['name'],
        h($reviewer['name']) . ' ți-a lăsat o recenzie ⭐',
        '<h2>Recenzie nouă! ⭐</h2>
        <p>Bună <strong>'.h($reviewed['name']).'</strong>,</p>
        <p><strong>'.h($reviewer['name']).'</strong> ți-a lăsat o recenzie:</p>
        <div style="background:#FFFBEB;border-radius:8px;padding:16px;margin:12px 0;">
          <div style="font-size:1.4rem;color:#F59E0B;margin-bottom:6px;">'.$stars.'</div>
          '.($comment ? '<p style="margin:0;font-style:italic;">'.h($comment).'</p>' : '').
        '</div>
        <a href="'.BASE_URL.'/utilizator/'.(int)$reviewed['id'].'?tab=recenzii" class="btn">Vezi profilul →</a>'
    );
}

function notify_new_follow(array $follower, array $followed): void {
    if (!empty($followed['id']) && !user_wants_email((int)$followed['id'], 'follow')) return;
    veedo_mail($followed['email'], $followed['name'],
        h($follower['name']) . ' te urmărește acum 👥',
        '<h2>Ai un nou urmăritor! 👥</h2>
        <p>Bună <strong>'.h($followed['name']).'</strong>,</p>
        <p><strong>'.h($follower['name']).'</strong> a început să te urmărească pe Veedo.</p>
        <a href="'.BASE_URL.'/utilizator/'.(int)$follower['id'].'" class="btn">Vezi profilul lui →</a>'
    );
}

function notify_boost_activated(array $user, string $ad_title, int $days): void {
    veedo_mail($user['email'], $user['name'],
        'Boost activ! Anunțul tău este promovat ⭐',
        '<h2>Boost activat cu succes! ⭐</h2>
        <p>Bună <strong>'.h($user['name']).'</strong>,</p>
        <p>Anunțul <strong>"'.h(mb_substr($ad_title,0,60)).'"</strong> este acum promovat pentru <strong>'.$days.' zile</strong>.</p>
        <p>Anunțul tău va apărea <strong>primul în căutări</strong> și va primi de 5x mai multe vizualizări!</p>'
    );
}


// ─── Alertă căutare ───────────────────────────────────────────────
function notify_search_alert(array $user, array $alert, array $new_ads): void {
    if (empty($new_ads)) return;
    $count = count($new_ads);
    $ads_html = '';
    foreach (array_slice($new_ads, 0, 5) as $ad) {
        $price = $ad['price'] ? number_format((float)$ad['price'], 0, ',', '.') . ' ' . h($ad['currency']) : 'Preț la cerere';
        $ads_html .= '
        <div style="border:1px solid #eee;border-radius:8px;padding:14px;margin-bottom:10px;display:flex;gap:14px;align-items:flex-start;">
            ' . ($ad['main_photo'] ? '<img src="'.BASE_URL.'/'.h($ad['main_photo']).'" style="width:72px;height:54px;object-fit:cover;border-radius:6px;flex-shrink:0;" alt="">' : '') . '
            <div style="flex:1;min-width:0;">
                <a href="'.BASE_URL.'/anunt/'.h($ad['slug']).'" style="font-weight:700;color:#111;text-decoration:none;font-size:14px;">'.h(mb_substr($ad['title'],0,60)).'</a>
                <div style="color:#E8380D;font-weight:700;font-size:13px;margin-top:3px;">'.$price.'</div>
                <div style="font-size:12px;color:#888;margin-top:2px;">'.($ad['city'] ? '📍 '.h($ad['city']) : '').' · '.time_ago($ad['created_at']).'</div>
            </div>
        </div>';
    }
    $more = $count > 5 ? '<p style="text-align:center;font-size:13px;color:#888;">+ '.($count-5).' alte anunțuri noi</p>' : '';
    $search_url = BASE_URL . '/cauta?' . http_build_query(array_filter([
        'q'   => $alert['keywords'],
        'cat' => $alert['category_id'],
        'city'=> $alert['city'],
    ]));

    veedo_mail($user['email'], $user['name'],
        '🔔 ' . $count . ' anunț' . ($count>1?'uri':'') . ' noi pentru "'.h($alert['name'] ?: $alert['keywords']).'"',
        '<h2>Anunțuri noi pentru alerta ta 🔔</h2>
        <p>Bună <strong>'.h($user['name']).'</strong>,</p>
        <p>Am găsit <strong>'.$count.' anunț'.($count>1?'uri':'').' noi</strong> care se potrivesc alertei "<strong>'.h($alert['name'] ?: $alert['keywords']).'</strong>":</p>
        '.$ads_html.$more.'
        <a href="'.$search_url.'" class="btn">Vezi toate rezultatele →</a>
        <hr>
        <p style="font-size:12px;color:#aaa;">
            Gestionează alertele tale de căutare: <a href="'.BASE_URL.'/cont/alerte">here</a>
        </p>'
    );
}