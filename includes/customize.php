<?php
// ============================================================
// VEEDO — Personalizare (Admin → 🎨 Personalizare)
// Toate opțiunile de aspect au o valoare implicită aici; în baza de date se salvează doar
// ce schimbă administratorul (tabela settings). ui('cheie') întoarce valoarea efectivă.
// ============================================================

/** Fonturi disponibile: [etichetă, URL Google Fonts, display, text, mono] */
function ui_fonts(): array {
    return [
        'futurist'  => ['Futurist — Unbounded + Geist', 'family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@500;600&family=Unbounded:wght@500;600;700;800', "'Unbounded'", "'Geist'", "'Geist Mono'"],
        'modern'    => ['Modern — Sora + Plus Jakarta Sans', 'family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@600;700;800&family=JetBrains+Mono:wght@500;600', "'Sora'", "'Plus Jakarta Sans'", "'JetBrains Mono'"],
        'clean'     => ['Curat — Inter Tight + Inter', 'family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&family=IBM+Plex+Mono:wght@500;600', "'Inter Tight'", "'Inter'", "'IBM Plex Mono'"],
        'tech'      => ['Tech — Space Grotesk + Space Mono', 'family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700', "'Space Grotesk'", "'Space Grotesk'", "'Space Mono'"],
        'editorial' => ['Editorial — Fraunces + Geist', 'family=Fraunces:opsz,wght@9..144,600;9..144,700;9..144,800&family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@500;600', "'Fraunces'", "'Geist'", "'Geist Mono'"],
    ];
}

/**
 * Schema opțiunilor: cheie => [tab, etichetă, tip, implicit, opțiuni|ajutor]
 * Tipuri: text, textarea, color, bool, select, number, image, lines
 */
function ui_schema(): array {
    $fonts = array_map(fn($f) => $f[0], ui_fonts());
    return [
        // ── Brand ──
        'site_name'            => ['brand', 'Numele site-ului', 'text', 'Veedo.ro'],
        'site_tagline'         => ['brand', 'Slogan', 'text', 'Anunțuri video. Simplu. Rapid.'],
        'site_logo'            => ['brand', 'Logo (opțional — altfel se folosește logo-ul Veedo)', 'image', ''],
        'site_favicon'         => ['brand', 'Favicon (PNG pătrat, min. 64×64)', 'image', ''],
        'site_color_primary'   => ['brand', 'Culoare accent (butoane, prețuri)', 'color', '#2563EB'],
        'site_color_secondary' => ['brand', 'Culoare brand (linkuri, stări active)', 'color', '#1E40AF'],
        'ui_font'              => ['brand', 'Fonturi', 'select', 'clean', $fonts],
        'ui_radius'            => ['brand', 'Colțuri', 'select', 'soft', ['sharp' => 'Drepte (minimal)', 'soft' => 'Rotunjite (implicit)', 'round' => 'Foarte rotunde']],

        // ── Temă ──
        'site_theme_default'   => ['tema', 'Tema implicită', 'select', 'light', ['light' => '☀️ Luminoasă (profesională)', 'dark' => '🌙 Întunecată']],
        'ui_theme_toggle'      => ['tema', 'Butonul de schimbare a temei vizibil pentru vizitatori', 'bool', '1'],
        'ui_aurora'            => ['tema', 'Fundal „aurora” (lumini colorate în spatele paginii)', 'bool', '0'],
        'ui_aurora_level'      => ['tema', 'Intensitatea luminilor', 'select', 'medium', ['low' => 'Discretă', 'medium' => 'Medie', 'high' => 'Puternică']],
        'ui_grain'             => ['tema', 'Textură fină („film grain”)', 'bool', '0'],
        'ui_animations'        => ['tema', 'Animații la derulare și hover', 'bool', '1'],

        // ── Homepage ──
        'ui_hero_on'           => ['homepage', 'Afișează secțiunea principală (hero)', 'bool', '1'],
        'hero_eyebrow_text'    => ['homepage', 'Text mic deasupra titlului', 'text', 'Marketplace video'],
        'ui_hero_title'        => ['homepage', 'Titlu principal (pune între *stele* cuvântul evidențiat)', 'text', 'Vezi *înainte* să cumperi.'],
        'hero_subtitle'        => ['homepage', 'Subtitlu', 'textarea', 'Descoperă produse și servicii prin video — ca pe TikTok, dar pentru cumpărături reale.'],
        'hero_search_placeholder' => ['homepage', 'Text în câmpul de căutare', 'text', 'Caută mașini, apartamente, electronice...'],
        'hero_chips'           => ['homepage', 'Căutări populare (separate prin virgulă)', 'text', 'BMW,apartament,iPhone,bicicletă'],
        'ui_tile_video'        => ['homepage', 'Bloc „Acum pe Veedo” (video care rulează automat)', 'bool', '1'],
        'ui_hero_ad_id'        => ['homepage', 'ID-ul anunțului video afișat (gol = cel mai vizionat)', 'number', ''],
        'ui_tile_stats'        => ['homepage', 'Bloc statistici (anunțuri video)', 'bool', '1'],
        'ui_tile_cta'          => ['homepage', 'Bloc de postare (accent)', 'bool', '1'],
        'ui_cta_kicker'        => ['homepage', 'Bloc postare — text mic', 'text', 'Gratuit · 60 secunde'],
        'ui_cta_title'         => ['homepage', 'Bloc postare — titlu (fiecare rând pe linie nouă)', 'lines', "Filmează.\nPostează.\nVinde."],
        'ui_tile_live'         => ['homepage', 'Bloc LIVE shopping', 'bool', '1'],
        'ui_live_title'        => ['homepage', 'Bloc LIVE — titlu', 'text', 'Cumpără în direct de la vânzători'],
        'ui_live_text'         => ['homepage', 'Bloc LIVE — text', 'text', 'Întreabă, vezi produsul, negociază — în timp real.'],
        'ui_tile_trust'        => ['homepage', 'Bloc „De ce Veedo”', 'bool', '1'],
        'ui_trust_items'       => ['homepage', 'Bloc „De ce Veedo” — avantaje (câte unul pe linie)', 'lines', "Vezi produsul real, nu doar poze\nVânzători business verificați\nChat instant, fără intermediari"],
        'ui_show_categories'   => ['homepage', 'Rândul cu categorii', 'bool', '1'],
        'ui_show_stories'      => ['homepage', 'Stories pe mobil (bulele de sus)', 'bool', '1'],
        'ui_show_recent'       => ['homepage', '„Văzute recent”', 'bool', '1'],
        'ui_feed_kicker'       => ['homepage', 'Feed — text mic', 'text', 'Feed · actualizat live'],
        'ui_feed_title'        => ['homepage', 'Feed — titlu', 'text', 'Proaspăt postate'],
        'ui_per_page'          => ['homepage', 'Anunțuri pe pagină', 'number', '24'],
        'ui_default_sort'      => ['homepage', 'Sortare implicită', 'select', 'recent', ['recent' => 'Cele mai noi', 'views' => 'Cele mai populare', 'price_asc' => 'Preț crescător', 'price_desc' => 'Preț descrescător']],

        // ── Carduri ──
        'ui_card_style'        => ['carduri', 'Stilul cardurilor de anunț', 'select', 'reels', ['reels' => 'Vertical tip Reels (9:16)', 'square' => 'Pătrat, cu text peste imagine', 'classic' => 'Clasic — imagine sus, detalii jos']],
        'ui_card_size'         => ['carduri', 'Mărimea cardurilor pe desktop', 'select', 'normal', ['compact' => 'Compacte (mai multe pe rând)', 'normal' => 'Normale', 'large' => 'Mari']],
        'ui_card_seller'       => ['carduri', 'Afișează vânzătorul pe card', 'bool', '1'],
        'ui_card_views'        => ['carduri', 'Afișează numărul de vizualizări', 'bool', '1'],
        'ui_card_location'     => ['carduri', 'Afișează orașul și data', 'bool', '1'],
        'ui_video_preview'     => ['carduri', 'Pornește videoul la trecerea mouse-ului (desktop)', 'bool', '1'],

        // ── Bară de anunț ──
        'ui_bar_on'            => ['anunt', 'Afișează bara de anunț deasupra meniului', 'bool', '0'],
        'ui_bar_text'          => ['anunt', 'Text', 'text', '🎉 Postarea anunțurilor video este gratuită în această lună!'],
        'ui_bar_link'          => ['anunt', 'Link (opțional)', 'text', '/anunt/nou'],
        'ui_bar_link_text'     => ['anunt', 'Text buton', 'text', 'Postează acum →'],
        'ui_bar_style'         => ['anunt', 'Stil', 'select', 'accent', ['accent' => 'Accent (volt)', 'brand' => 'Brand (violet)', 'aurora' => 'Gradient aurora', 'dark' => 'Întunecat']],
        'ui_bar_dismiss'       => ['anunt', 'Vizitatorii o pot închide', 'bool', '1'],

        // ── Meniu ──
        'ui_nav_ads'           => ['meniu', 'Link „Anunțuri” în meniu', 'bool', '1'],
        'feature_explore'      => ['meniu', 'Explore (pagina + link)', 'bool', '1'],
        'feature_stories'      => ['meniu', 'Stories (pagina + link)', 'bool', '1'],
        'feature_live'         => ['meniu', 'LIVE (pagina + link)', 'bool', '1'],
        'ui_nav_links'         => ['meniu', 'Linkuri suplimentare în meniu (câte unul pe linie: Etichetă | /adresa)', 'lines', ''],
        'ui_post_label'        => ['meniu', 'Textul butonului de postare', 'text', 'Adaugă anunț'],

        // ── Footer ──
        'ui_footer_cta'        => ['footer', 'Banner de postare în footer', 'bool', '1'],
        'ui_footer_cta_title'  => ['footer', 'Banner — titlu', 'text', 'Filmează. Postează. Vinde. Totul în mai puțin de un minut.'],
        'ui_footer_cta_text'   => ['footer', 'Banner — text', 'text', 'Anunțurile cu video primesc mai multe vizualizări și mesaje. Postarea e gratuită.'],
        'ui_footer_cta_btn'    => ['footer', 'Banner — buton', 'text', 'Adaugă anunț gratuit →'],
        'ui_footer_wordmark'   => ['footer', 'Numele site-ului scris uriaș la final', 'bool', '1'],
        'footer_text'          => ['footer', 'Text copyright', 'text', '© 2026 Veedo.ro - Toate drepturile rezervate'],
        'social_facebook'      => ['footer', 'Facebook (URL)', 'text', ''],
        'social_instagram'     => ['footer', 'Instagram (URL)', 'text', ''],
        'social_tiktok'        => ['footer', 'TikTok (URL)', 'text', ''],

        // ── Avansat ──
        'custom_css'           => ['avansat', 'CSS personalizat (se aplică pe tot site-ul)', 'textarea', ''],
    ];
}

function ui_tabs(): array {
    return ['brand' => '🎨 Brand', 'tema' => '🌗 Temă', 'homepage' => '🏠 Homepage', 'carduri' => '🃏 Carduri',
            'anunt' => '📣 Bară anunț', 'meniu' => '🧭 Meniu', 'footer' => '⬇️ Footer', 'avansat' => '⚙️ Avansat'];
}

/** Valoarea efectivă a unei opțiuni (setare salvată sau implicită). */
function ui(string $key): string {
    static $schema = null;
    $schema ??= ui_schema();
    $def = $schema[$key][3] ?? '';
    global $_settings_cache;
    if ($_settings_cache === null) setting('site_name'); // încarcă cache-ul
    return array_key_exists($key, $_settings_cache ?? []) ? (string)$_settings_cache[$key] : $def;
}
function ui_bool(string $key): bool { return ui($key) === '1'; }

/** Titlu cu *evidențiere* → HTML sigur. */
function ui_highlight(string $s): string {
    return preg_replace('/\*([^*]+)\*/u', '<span class="hl">$1</span>', h($s));
}

/** Linii nevide dintr-un câmp „lines”. */
function ui_lines(string $key): array {
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', ui($key)))));
}

/** Linkurile suplimentare din meniu: [[eticheta, url], ...] (doar URL-uri sigure). */
function ui_nav_links(): array {
    $out = [];
    foreach (ui_lines('ui_nav_links') as $ln) {
        [$label, $url] = array_map('trim', array_pad(explode('|', $ln, 2), 2, ''));
        $url = safe_url($url, true);
        if ($label !== '' && $url !== '') $out[] = [mb_substr($label, 0, 30), $url];
        if (count($out) >= 6) break;
    }
    return $out;
}

/** Clasele CSS de pe <body> care controlează stilurile alese. */
function ui_body_classes(): string {
    $c = ['cards-' . ui('ui_card_style'), 'cardsize-' . ui('ui_card_size'), 'radius-' . ui('ui_radius'), 'aurora-' . ui('ui_aurora_level')];
    if (!ui_bool('ui_aurora'))        $c[] = 'no-aurora';
    if (!ui_bool('ui_grain'))         $c[] = 'no-grain';
    if (!ui_bool('ui_animations'))    $c[] = 'no-anim';
    if (!ui_bool('ui_card_seller'))   $c[] = 'card-no-seller';
    if (!ui_bool('ui_card_views'))    $c[] = 'card-no-views';
    if (!ui_bool('ui_card_location')) $c[] = 'card-no-meta';
    return implode(' ', array_map(fn($x) => preg_replace('/[^a-z0-9-]/', '', $x), $c));
}

/** Validează o valoare trimisă din formularul de personalizare. */
function ui_clean(string $key, $value): ?string {
    $schema = ui_schema();
    if (!isset($schema[$key]) || !is_scalar($value)) return null;
    [, , $type, $def] = $schema[$key];
    $opts = $schema[$key][4] ?? [];
    $v = trim((string)$value);
    switch ($type) {
        case 'bool':   return $v === '1' ? '1' : '0';
        case 'color':  return safe_color($v, $def);
        case 'select': return array_key_exists($v, $opts) ? $v : $def;
        case 'number': return $v === '' ? '' : (string)max(0, min(1000000, (int)$v));
        case 'image':  return safe_url($v, true);
        case 'lines':
        case 'textarea':
            if ($key === 'custom_css') return admin_setting_value('custom_css', $v);
            return mb_substr(sanitize($v), 0, 2000);
        default:
            if (str_starts_with($key, 'social_')) return safe_url($v);
            if (in_array($key, ['ui_bar_link'], true)) return safe_url($v, true);
            return mb_substr(sanitize($v), 0, 300);
    }
}
