<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$u         = current_user();
$errors    = [];
$full_user = DB::fetch("SELECT * FROM users WHERE id=?", [$u['id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    // ── Profil general ────────────────────────────────────────
    if (isset($_POST['save_profile'])) {
        $name   = sanitize($_POST['name'] ?? '');
        $phone  = sanitize($_POST['phone'] ?? '');
        $city   = sanitize($_POST['city'] ?? '');
        $county = sanitize($_POST['county'] ?? '');
        $bio    = sanitize($_POST['bio'] ?? '');
        $theme  = in_array($_POST['theme']??'', ['light','dark','auto']) ? $_POST['theme'] : 'auto';
        $notif_email = !empty($_POST['notification_email']) ? 1 : 0;

        if (strlen($name) < 3) $errors[] = 'Numele trebuie să aibă cel puțin 3 caractere.';
        if (empty($errors)) {
            DB::update('users', [
                'name'               => $name,
                'phone'              => $phone,
                'city'               => $city,
                'county'             => $county,
                'bio'                => $bio,
                'theme_preference'   => $theme,
                'notification_email' => $notif_email,
            ], 'id=?', [$u['id']]);
            flash('success', 'Profilul a fost actualizat!');
            redirect('/cont/setari');
        }
    }

    // ── Avatar ────────────────────────────────────────────────
    if (isset($_POST['save_avatar']) && !empty($_FILES['avatar']['name'])) {
        if (!is_dir(AVATAR_PATH)) mkdir(AVATAR_PATH, 0755, true);
        $fname = upload_photo($_FILES['avatar'], AVATAR_PATH);
        if ($fname) {
            if ($full_user['avatar']) @unlink(AVATAR_PATH . $full_user['avatar']);
            DB::update('users', ['avatar' => $fname], 'id=?', [$u['id']]);
            flash('success', 'Avatarul a fost actualizat!');
            redirect('/cont/setari');
        } else {
            $errors[] = 'Format invalid. Folosește JPEG, PNG sau WebP (max 5MB).';
        }
    }

    // ── Parolă ────────────────────────────────────────────────
    if (isset($_POST['save_password'])) {
        $old  = $_POST['old_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $con  = $_POST['confirm_password'] ?? '';
        $full = DB::fetch("SELECT password FROM users WHERE id=?", [$u['id']]);
        if (!password_verify($old, $full['password'])) $errors[] = 'Parola actuală este incorectă.';
        if (strlen($new) < PASSWORD_MIN_LENGTH)         $errors[] = 'Parola nouă min '.PASSWORD_MIN_LENGTH.' caractere.';
        if ($new !== $con)                              $errors[] = 'Parolele nu coincid.';
        if (empty($errors)) {
            DB::update('users', ['password' => password_hash($new, PASSWORD_BCRYPT, ['cost'=>12])], 'id=?', [$u['id']]);
            flash('success', 'Parola a fost schimbată!');
            redirect('/cont/setari?tab=securitate');
        }
    }

    // ── Business ──────────────────────────────────────────────
    if (isset($_POST['save_business'])) {
        if (!($full_user['is_verified_business'] || $full_user['role'] === 'business')) {
            flash('error', 'Nu ești Business Verificat.');
            redirect('/cont/setari');
        }
        $bdata = [
            'business_name'     => sanitize($_POST['business_name'] ?? ''),
            'business_desc'     => sanitize($_POST['business_desc'] ?? ''),
            'business_website'  => sanitize($_POST['business_website'] ?? ''),
            'business_phone'    => sanitize($_POST['business_phone'] ?? ''),
            'business_category' => sanitize($_POST['business_category'] ?? ''),
            'business_schedule' => sanitize($_POST['business_schedule'] ?? ''),
            'business_address'  => sanitize($_POST['business_address'] ?? ''),
        ];
        if (!empty($_FILES['business_banner_file']['name']) && !$_FILES['business_banner_file']['error']) {
            if (!is_dir(AVATAR_PATH)) mkdir(AVATAR_PATH, 0755, true);
            $fname = upload_photo($_FILES['business_banner_file'], AVATAR_PATH);
            if ($fname) {
                if ($full_user['business_banner'] && !str_starts_with($full_user['business_banner'], 'http'))
                    @unlink(AVATAR_PATH . $full_user['business_banner']);
                $bdata['business_banner'] = $fname;
            } else {
                $errors[] = 'Format banner invalid (JPEG/PNG/WebP, max 5MB).';
            }
        } elseif (!empty($_POST['business_banner_url'])) {
            $bdata['business_banner'] = sanitize($_POST['business_banner_url']);
        }
        if (empty($errors)) {
            DB::update('users', $bdata, 'id=?', [$u['id']]);
            flash('success', 'Pagina de business a fost actualizată!');
            redirect('/cont/setari?tab=business');
        }
    }

    // ── Notificari ────────────────────────────────────────────
    if (isset($_POST['save_notif'])) {
        DB::update('users', [
            'notification_email'    => !empty($_POST['notification_email']) ? 1 : 0,
            'notif_email_messages'  => !empty($_POST['notif_email_messages']) ? 1 : 0,
            'notif_email_ad_status' => !empty($_POST['notif_email_ad_status']) ? 1 : 0,
            'notif_email_reviews'   => !empty($_POST['notif_email_reviews']) ? 1 : 0,
            'notif_email_follows'   => !empty($_POST['notif_email_follows']) ? 1 : 0,
        ], 'id=?', [$u['id']]);
        flash('success', 'Preferințele au fost salvate!');
        redirect('/cont/setari?tab=notificari');
    }
}

$full_user  = DB::fetch("SELECT * FROM users WHERE id=?", [$u['id']]);
$active_tab = in_array($_GET['tab'] ?? '', ['profil','securitate','notificari','business']) ? $_GET['tab'] : 'profil';
$is_biz     = $full_user['is_verified_business'] || $full_user['role'] === 'business';

$seo_title = 'Setări cont';
include __DIR__ . '/../includes/header.php';
?>

<style>
.settings-wrap { max-width: 720px; margin: 0 auto; padding: 24px 16px 40px; }

/* Tabs */
.settings-tabs {
    display: flex; gap: 4px;
    background: var(--bg-2); border-radius: 12px;
    padding: 4px; margin-bottom: 24px;
    overflow-x: auto; scrollbar-width: none;
    flex-wrap: nowrap;
}
.settings-tabs::-webkit-scrollbar { display: none; }
.settings-tab {
    padding: 8px 16px; border-radius: 8px;
    font-size: 13px; font-weight: 600;
    color: var(--text-3); text-decoration: none;
    white-space: nowrap; transition: all 0.15s;
    flex-shrink: 0;
}
.settings-tab.active {
    background: var(--surface);
    color: var(--text);
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.settings-tab:hover:not(.active) { color: var(--text); }

/* Section card */
.s-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 14px;
}
.s-card-title {
    font-size: 0.95rem; font-weight: 700;
    margin-bottom: 18px;
    display: flex; align-items: center; gap: 8px;
}

/* Avatar upload */
.avatar-upload-wrap {
    display: flex; align-items: center; gap: 20px;
    flex-wrap: wrap;
}
.avatar-circle {
    width: 88px; height: 88px;
    border-radius: 50%; object-fit: cover;
    border: 3px solid var(--brand);
    cursor: pointer; flex-shrink: 0;
    transition: opacity 0.2s;
}
.avatar-circle:hover { opacity: 0.85; }

/* Toggle switch */
.notif-toggle {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px; border: 1px solid var(--border);
    border-radius: 12px; background: var(--bg-2);
    cursor: pointer; margin-bottom: 10px;
    transition: border-color 0.15s;
}
.notif-toggle:hover { border-color: var(--brand); }
.notif-toggle input { display: none; }
.toggle-sw {
    width: 44px; height: 24px; border-radius: 12px;
    background: var(--bg-3); position: relative;
    transition: background 0.2s; flex-shrink: 0;
    border: 1.5px solid var(--border); margin-top: 1px;
}
.toggle-sw::after {
    content: ''; position: absolute;
    width: 18px; height: 18px; border-radius: 50%;
    background: #fff; top: 1px; left: 1px;
    transition: transform 0.2s;
    box-shadow: 0 1px 4px rgba(0,0,0,0.2);
}
.notif-toggle input:checked ~ .toggle-sw { background: var(--brand); border-color: var(--brand); }
.notif-toggle input:checked ~ .toggle-sw::after { transform: translateX(20px); }

/* Password strength */
.pass-strength { height: 4px; border-radius: 2px; margin-top: 6px; transition: all 0.3s; background: var(--bg-3); }

/* Banner preview */
.banner-preview {
    height: 140px; border-radius: 10px;
    background: linear-gradient(135deg, var(--brand) 0%, #FF6B35 100%);
    position: relative; overflow: hidden; cursor: pointer;
    margin-bottom: 14px;
}
.banner-preview img { width:100%;height:100%;object-fit:cover;display:block; }
.banner-preview-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,0.4);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity 0.2s;
}
.banner-preview:hover .banner-preview-overlay { opacity: 1; }

@media (max-width: 600px) {
    .settings-wrap { padding: 16px; }
    .s-card { padding: 16px; }
}
</style>

<div class="settings-wrap">

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <h1 style="font-size:1.2rem;font-weight:800;margin:0;">⚙️ Setări cont</h1>
        <a href="/utilizator/<?= $u['id'] ?>" class="btn btn-secondary btn-sm" style="margin-left:auto;">
            👤 Profil public
        </a>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-error" style="margin-bottom:16px;">
        <?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="settings-tabs">
        <a href="?tab=profil"     class="settings-tab <?= $active_tab==='profil'?'active':'' ?>">👤 Profil</a>
        <a href="?tab=securitate" class="settings-tab <?= $active_tab==='securitate'?'active':'' ?>">🔒 Parolă</a>
        <a href="?tab=notificari" class="settings-tab <?= $active_tab==='notificari'?'active':'' ?>">🔔 Notificări</a>
        <?php if ($is_biz): ?>
        <a href="?tab=business"   class="settings-tab <?= $active_tab==='business'?'active':'' ?>">🏢 Business</a>
        <?php endif; ?>
    </div>

    <?php if ($active_tab === 'profil'): ?>

    <!-- Avatar -->
    <div class="s-card">
        <div class="s-card-title">📸 Fotografie profil</div>
        <div class="avatar-upload-wrap">
            <div style="position:relative;flex-shrink:0;">
                <img src="<?= h(avatar_url($full_user['avatar'], $full_user['name'])) ?>"
                     id="avatar-preview" class="avatar-circle" alt=""
                     onclick="document.getElementById('avatar-input').click()" title="Schimbă avatarul">
                <div style="position:absolute;bottom:2px;right:2px;background:var(--brand);color:#fff;
                            border-radius:50%;width:24px;height:24px;display:flex;align-items:center;
                            justify-content:center;font-size:12px;border:2px solid var(--surface);pointer-events:none;">✏️</div>
            </div>
            <form method="POST" enctype="multipart/form-data" style="flex:1;">
                <?= csrf_field() ?>
                <input type="file" name="avatar" id="avatar-input"
                       accept="image/jpeg,image/png,image/webp" style="display:none;"
                       onchange="previewAvatar(this)">
                <div style="font-size:13px;color:var(--text-3);margin-bottom:10px;line-height:1.6;">
                    Apasă pe fotografie pentru a o schimba<br>
                    <span style="font-size:12px;">JPEG, PNG, WebP · Max 5MB</span>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" onclick="document.getElementById('avatar-input').click()"
                            class="btn btn-secondary btn-sm">📷 Alege poza</button>
                    <button type="submit" name="save_avatar" class="btn btn-primary btn-sm"
                            id="save-avatar-btn" style="display:none;">💾 Salvează</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Info profil -->
    <div class="s-card">
        <div class="s-card-title">👤 Informații profil</div>
        <form method="POST">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nume / Pseudonim *</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($full_user['name']) ?>" required maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Email <span style="color:var(--text-3);font-weight:400;">(nu se poate modifica)</span></label>
                    <input type="email" class="form-control" value="<?= h($full_user['email']) ?>"
                           disabled style="opacity:.6;">
                </div>
                <div class="form-group">
                    <label class="form-label">Telefon</label>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= h($full_user['phone'] ?? '') ?>" placeholder="07xx xxx xxx">
                </div>
                <div class="form-group">
                    <label class="form-label">Oraș</label>
                    <input type="text" name="city" class="form-control"
                           value="<?= h($full_user['city'] ?? '') ?>" placeholder="București">
                </div>
                <div class="form-group">
                    <label class="form-label">Județ</label>
                    <input type="text" name="county" class="form-control"
                           value="<?= h($full_user['county'] ?? '') ?>" placeholder="Ilfov">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Bio <span style="color:var(--text-3);font-weight:400;">(opțional)</span></label>
                    <textarea name="bio" class="form-control" rows="3" maxlength="500"
                              placeholder="Câteva cuvinte despre tine..."><?= h($full_user['bio'] ?? '') ?></textarea>
                    <div style="text-align:right;font-size:11px;color:var(--text-3);margin-top:3px;" id="bio-count">
                        <?= strlen($full_user['bio'] ?? '') ?>/500
                    </div>
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Temă</label>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;">
                        <?php foreach (['light'=>'☀️ Luminoasă','dark'=>'🌙 Întunecată','auto'=>'🔄 Automată'] as $val=>$lbl): ?>
                        <label style="cursor:pointer;display:flex;align-items:center;gap:6px;font-size:14px;">
                            <input type="radio" name="theme" value="<?= $val ?>"
                                   <?= ($full_user['theme_preference']??'auto')===$val?'checked':'' ?>>
                            <?= $lbl ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;">
                <button type="submit" name="save_profile" class="btn btn-primary">💾 Salvează profilul</button>
                <?php if (!$is_biz): ?>
                <a href="/cont/abonamente" class="btn btn-secondary">🏢 Devino Business Verificat</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Danger zone -->
    <div class="s-card" style="border-color:#FCA5A5;">
        <div class="s-card-title" style="color:#EF4444;">⚠️ Zonă periculoasă</div>
        <p style="font-size:13px;color:var(--text-3);margin-bottom:14px;">
            Ștergerea contului este ireversibilă. Toate anunțurile, mesajele și datele vor fi șterse permanent.
        </p>
        <a href="/cont/sterge-cont" class="btn btn-danger btn-sm"
           onclick="return confirm('Ești 100% sigur că vrei să ștergi contul definitiv? Această acțiune NU poate fi anulată!')">
            🗑 Șterge contul definitiv
        </a>
    </div>

    <?php elseif ($active_tab === 'securitate'): ?>

    <!-- Schimba parola -->
    <div class="s-card">
        <div class="s-card-title">🔒 Schimbă parola</div>
        <form method="POST" style="max-width:440px;">
            <?= csrf_field() ?>
            <div class="form-group">
                <label class="form-label">Parola actuală</label>
                <input type="password" name="old_password" class="form-control"
                       required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label class="form-label">Parolă nouă</label>
                <input type="password" name="new_password" class="form-control"
                       required autocomplete="new-password"
                       minlength="<?= PASSWORD_MIN_LENGTH ?>" id="new-pass">
                <div class="pass-strength" id="pass-strength"></div>
                <div style="font-size:11px;color:var(--text-3);margin-top:4px;" id="pass-label"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirmă parola nouă</label>
                <input type="password" name="confirm_password" class="form-control"
                       required autocomplete="new-password" id="confirm-pass">
                <div style="font-size:11px;margin-top:4px;" id="confirm-label"></div>
            </div>
            <button type="submit" name="save_password" class="btn btn-primary">🔒 Schimbă parola</button>
        </form>
    </div>

    <!-- Info cont -->
    <div class="s-card">
        <div class="s-card-title">🔐 Informații cont</div>
        <table style="width:100%;font-size:14px;border-collapse:collapse;">
            <?php
            $rows = [
                ['Email',              $full_user['email']],
                ['Tip cont',           ucfirst($full_user['role'])],
                ['Înregistrat',        date('d.m.Y', strtotime($full_user['created_at']))],
                ['Ultima autentificare', $full_user['last_login'] ? date('d.m.Y H:i', strtotime($full_user['last_login'])) : '—'],
            ];
            foreach ($rows as $i => [$l,$v]):
                $last = $i === count($rows)-1;
            ?>
            <tr>
                <td style="padding:10px 0;color:var(--text-3);<?= !$last?'border-bottom:1px solid var(--border-2);':'' ?>"><?= $l ?></td>
                <td style="padding:10px 0;text-align:right;font-weight:500;<?= !$last?'border-bottom:1px solid var(--border-2);':'' ?>"><?= h($v) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php elseif ($active_tab === 'notificari'): ?>

    <div class="s-card">
        <div class="s-card-title">🔔 Preferințe notificări email</div>
        <form method="POST">
            <?= csrf_field() ?>

            <div style="font-size:12px;color:var(--text-3);margin-bottom:16px;">
                Email: <strong><?= h($full_user['email']) ?></strong>
            </div>

            <label class="notif-toggle">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;margin-bottom:3px;">💬 Mesaje noi</div>
                    <div style="font-size:12px;color:var(--text-3);">Email când primești un mesaj de la un cumpărător</div>
                </div>
                <input type="checkbox" name="notif_email_messages" value="1"
                       <?= !empty($full_user['notif_email_messages']) ? 'checked' : '' ?>>
                <div class="toggle-sw"></div>
            </label>

            <label class="notif-toggle">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;margin-bottom:3px;">📋 Status anunțuri</div>
                    <div style="font-size:12px;color:var(--text-3);">Email când anunțul tău e aprobat sau respins</div>
                </div>
                <input type="checkbox" name="notif_email_ad_status" value="1"
                       <?= !empty($full_user['notif_email_ad_status']) ? 'checked' : '' ?>>
                <div class="toggle-sw"></div>
            </label>

            <label class="notif-toggle">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;margin-bottom:3px;">⭐ Recenzii noi</div>
                    <div style="font-size:12px;color:var(--text-3);">Email când cineva îți lasă o recenzie</div>
                </div>
                <input type="checkbox" name="notif_email_reviews" value="1"
                       <?= !empty($full_user['notif_email_reviews']) ? 'checked' : '' ?>>
                <div class="toggle-sw"></div>
            </label>

            <label class="notif-toggle">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;margin-bottom:3px;">👥 Urmăritori noi</div>
                    <div style="font-size:12px;color:var(--text-3);">Email când cineva începe să te urmărească</div>
                </div>
                <input type="checkbox" name="notif_email_follows" value="1"
                       <?= !empty($full_user['notif_email_follows']) ? 'checked' : '' ?>>
                <div class="toggle-sw"></div>
            </label>

            <!-- Web Push -->
            <div class="notif-toggle" id="push-toggle-wrap" style="cursor:default;border-top:1px solid var(--border-2);margin-top:8px;padding-top:4px;">
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;margin-bottom:3px;">🔔 Notificări Push (browser)</div>
                    <div style="font-size:12px;color:var(--text-3);">Notificări instant chiar și când site-ul e închis</div>
                    <div style="font-size:11px;margin-top:6px;" id="push-status-label"></div>
                </div>
                <button type="button" id="push-toggle-btn" class="btn btn-secondary btn-sm" style="flex-shrink:0;align-self:center;">
                    Se verifică...
                </button>
            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border-2);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <a href="/alerte" class="btn btn-secondary">🔍 Alerte de căutare</a>
                <button type="submit" name="save_notif" class="btn btn-primary">💾 Salvează</button>
            </div>
        </form>
    </div>

    <?php elseif ($active_tab === 'business'): ?>

    <?php if (!$is_biz): ?>
    <div class="s-card" style="text-align:center;padding:40px;">
        <div style="font-size:3rem;margin-bottom:14px;">🏢</div>
        <h3 style="margin-bottom:8px;">Cont Business neactiv</h3>
        <p style="font-size:14px;color:var(--text-3);margin-bottom:20px;">
            Activează Business Verificat pentru a crea pagina firmei tale și a accesa funcționalități premium.
        </p>
        <a href="/cont/abonamente" class="btn btn-primary">Activează Business →</a>
    </div>
    <?php else: ?>

    <!-- Banner -->
    <div class="s-card">
        <div class="s-card-title">🏢 Pagina de business</div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="file" name="business_banner_file" id="banner-input"
                   accept="image/jpeg,image/png,image/webp" style="display:none;"
                   onchange="previewBanner(this)">

            <!-- Banner preview -->
            <div class="banner-preview" id="banner-preview" onclick="document.getElementById('banner-input').click()">
                <?php if ($full_user['business_banner']): ?>
                <img src="<?= h(str_starts_with($full_user['business_banner'],'http') ? $full_user['business_banner'] : UPLOAD_URL.'avatars/'.$full_user['business_banner']) ?>" alt="" id="banner-img">
                <?php else: ?>
                <img src="" alt="" id="banner-img" style="display:none;">
                <?php endif; ?>
                <div class="banner-preview-overlay">
                    <div style="background:rgba(0,0,0,0.7);color:#fff;padding:8px 18px;border-radius:20px;font-size:13px;font-weight:600;">
                        🖼 Schimbă banner (1200×400px)
                    </div>
                </div>
            </div>

            <?php if (!empty($full_user['business_verified_until'])): ?>
            <div style="background:#F0FDF4;border:1px solid #86EFAC;border-radius:10px;padding:10px 14px;font-size:13px;color:#166534;margin-bottom:14px;">
                ✅ Business Verificat · Valabil până <strong><?= date('d.m.Y', strtotime($full_user['business_verified_until'])) ?></strong>
                <a href="/cont/abonamente" style="margin-left:8px;font-size:12px;color:var(--brand);">Reînnoiește</a>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label class="form-label">Numele firmei *</label>
                    <input type="text" name="business_name" class="form-control"
                           value="<?= h($full_user['business_name'] ?? '') ?>"
                           required placeholder="SC Example SRL">
                </div>
                <div class="form-group">
                    <label class="form-label">Domeniu activitate</label>
                    <input type="text" name="business_category" class="form-control"
                           value="<?= h($full_user['business_category'] ?? '') ?>"
                           placeholder="ex: Imobiliare, Auto, IT">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Descriere firmă</label>
                    <textarea name="business_desc" class="form-control" rows="3"
                              maxlength="1000" placeholder="Prezentare scurtă a firmei tale..."><?= h($full_user['business_desc'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Website</label>
                    <input type="url" name="business_website" class="form-control"
                           value="<?= h($full_user['business_website'] ?? '') ?>"
                           placeholder="https://exemplu.ro">
                </div>
                <div class="form-group">
                    <label class="form-label">Telefon business</label>
                    <input type="tel" name="business_phone" class="form-control"
                           value="<?= h($full_user['business_phone'] ?? '') ?>"
                           placeholder="021 xxx xxxx">
                </div>
                <div class="form-group">
                    <label class="form-label">Adresă</label>
                    <input type="text" name="business_address" class="form-control"
                           value="<?= h($full_user['business_address'] ?? '') ?>"
                           placeholder="Str. Exemplu 1, București">
                </div>
                <div class="form-group">
                    <label class="form-label">Program lucru</label>
                    <input type="text" name="business_schedule" class="form-control"
                           value="<?= h($full_user['business_schedule'] ?? '') ?>"
                           placeholder="Lun-Vin 9:00-18:00">
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;">
                <button type="submit" name="save_business" class="btn btn-primary">💾 Salvează</button>
                <a href="/utilizator/<?= $u['id'] ?>" class="btn btn-secondary">👁 Previzualizează profil</a>
            </div>
        </form>
    </div>

    <?php endif; ?>
    <?php endif; ?>

</div>

<script>
// ── Avatar preview ────────────────────────────────
function previewAvatar(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('avatar-preview').src = e.target.result;
        document.getElementById('save-avatar-btn').style.display = 'inline-flex';
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Banner preview ────────────────────────────────
function previewBanner(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById('banner-img');
        if (img) { img.src = e.target.result; img.style.display = 'block'; }
        document.getElementById('banner-preview').style.background = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Bio counter ───────────────────────────────────
const bioEl  = document.querySelector('textarea[name=bio]');
const bioCnt = document.getElementById('bio-count');
if (bioEl && bioCnt) {
    bioEl.addEventListener('input', () => bioCnt.textContent = bioEl.value.length + '/500');
}

// ── Password strength ─────────────────────────────
const np = document.getElementById('new-pass');
const cp = document.getElementById('confirm-pass');
const ps = document.getElementById('pass-strength');
const pl = document.getElementById('pass-label');
const cl = document.getElementById('confirm-label');

if (np && ps) {
    np.addEventListener('input', () => {
        const v = np.value;
        let s = 0;
        if (v.length >= 8) s++;
        if (/[A-Z]/.test(v)) s++;
        if (/[0-9]/.test(v)) s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;
        const colors = ['','#EF4444','#F59E0B','#22C55E','#16A34A'];
        const labels = ['','Slabă','Acceptabilă','Bună','Excelentă'];
        ps.style.width = (s*25) + '%';
        ps.style.background = colors[s] || 'var(--bg-3)';
        if (pl) { pl.textContent = v.length ? (labels[s] || '') : ''; pl.style.color = colors[s] || ''; }
    });
}
if (cp && np) {
    cp.addEventListener('input', () => {
        if (!cl) return;
        const match = cp.value === np.value;
        cl.textContent = cp.value ? (match ? '✓ Parolele coincid' : '✗ Parolele nu coincid') : '';
        cl.style.color = match ? '#22C55E' : '#EF4444';
    });
}

// ── Web Push toggle ───────────────────────────────
(async function() {
    const btn = document.getElementById('push-toggle-btn');
    const lbl = document.getElementById('push-status-label');
    if (!btn) return;

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        btn.textContent = 'Indisponibil';
        btn.disabled = true;
        if (lbl) lbl.textContent = 'Browser-ul tău nu suportă notificări push.';
        return;
    }

    const perm = Notification.permission;
    const reg  = await navigator.serviceWorker.ready.catch(() => null);
    const sub  = reg ? await reg.pushManager.getSubscription().catch(() => null) : null;

    function updateUI(subscribed) {
        if (subscribed) {
            btn.textContent = 'Dezactivează';
            btn.className   = 'btn btn-secondary btn-sm';
            if (lbl) { lbl.textContent = '✓ Notificările push sunt active'; lbl.style.color = '#22C55E'; }
        } else {
            btn.textContent = 'Activează';
            btn.className   = 'btn btn-primary btn-sm';
            if (lbl) { lbl.textContent = perm === 'denied' ? '⚠️ Notificările sunt blocate în browser' : 'Notificările push sunt inactive'; lbl.style.color = perm === 'denied' ? '#EF4444' : ''; }
        }
    }

    updateUI(!!sub && perm === 'granted');

    btn.addEventListener('click', async () => {
        if (sub && perm === 'granted') {
            // Dezactivare
            await window.disablePush?.();
            updateUI(false);
        } else {
            // Activare
            const ok = await window.requestPushPermission?.();
            updateUI(!!ok);
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>