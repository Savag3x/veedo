<?php
require_once __DIR__ . '/includes/bootstrap.php';

$q          = sanitize($_GET['q'] ?? '');
$cat_id     = (int)($_GET['cat'] ?? 0);
$city_f     = sanitize($_GET['city'] ?? '');
$price_min  = (int)($_GET['pmin'] ?? 0);
$price_max  = (int)($_GET['pmax'] ?? 0);
$currency   = in_array($_GET['cur'] ?? '', ['RON','EUR','USD']) ? $_GET['cur'] : '';
$sort       = in_array($_GET['sort'] ?? '', ['recent','views','price_asc','price_desc','likes']) ? $_GET['sort'] : 'recent';
$has_video  = !empty($_GET['video']);
$negotiable = !empty($_GET['neg']);
$verified   = !empty($_GET['verified']);

$categories = DB::fetchAll("SELECT * FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order");
$top_cities = DB::fetchAll("SELECT city, COUNT(*) as c FROM ads WHERE status='active' AND (expires_at IS NULL OR expires_at >= CURDATE()) AND city!='' GROUP BY city ORDER BY c DESC LIMIT 20");

// ── Build query ───────────────────────────────────────────
$where  = ["a.status='active'", "(a.expires_at IS NULL OR a.expires_at >= CURDATE())"];
$params = [];

if ($q) {
    // Extrage cuvintele din query (split pe spații/punctuație)
    $words = array_filter(array_map('trim', preg_split('/[\s,;]+/u', $q)));
    // FULLTEXT Boolean Mode: funcționează doar pentru cuvinte cu ≥3 caractere
    // Fallback la LIKE pentru query-uri cu cuvinte scurte (ex: "VW", "TV")
    $has_short = !empty(array_filter($words, fn($w) => mb_strlen($w) < 3));

    if (!empty($words) && !$has_short) {
        // +cuvant1 +cuvant2 = ambele cuvinte trebuie prezente (AND logic)
        $ft_query = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
        $where[]  = "MATCH(a.title, a.description) AGAINST(? IN BOOLEAN MODE)";
        $params[] = $ft_query;
    } else {
        // Fallback LIKE pentru cuvinte scurte sau caractere speciale
        $like     = '%' . str_replace(['%','_'], ['\%','\_'], $q) . '%';
        $where[]  = "(a.title LIKE ? OR a.description LIKE ?)";
        $params[] = $like;
        $params[] = $like;
    }
}
if ($cat_id)    { $where[] = "a.category_id=?";         $params[] = $cat_id; }
if ($city_f)    { $where[] = "a.city LIKE ?";            $params[] = '%'.$city_f.'%'; }
if ($price_min) { $where[] = "a.price >= ?";             $params[] = $price_min; }
if ($price_max) { $where[] = "a.price <= ?";             $params[] = $price_max; }
if ($currency)  { $where[] = "a.currency=?";             $params[] = $currency; }
if ($has_video) { $where[] = "a.video_path IS NOT NULL AND a.video_path!=''"; }
if ($negotiable){ $where[] = "a.price_negotiable=1"; }
if ($verified)  { $where[] = "u.is_verified_business=1"; }

$where_sql = implode(' AND ', $where);
$order_sql = match($sort) {
    'price_asc'  => 'a.price ASC',
    'price_desc' => 'a.price DESC',
    'views'      => 'a.views_count DESC',
    'likes'      => 'a.likes_count DESC',
    default      => 'a.is_boosted DESC, COALESCE(a.bumped_at, a.created_at) DESC',
};

$pagination = paginate(
    "SELECT a.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
            u.name AS user_name, u.avatar AS user_avatar, u.is_verified_business,
            ap.path AS main_photo
     FROM ads a
     JOIN categories c ON c.id=a.category_id
     JOIN users u ON u.id=a.user_id
     LEFT JOIN ad_photos ap ON ap.ad_id = a.id AND ap.is_primary = 1
     WHERE $where_sql ORDER BY $order_sql",
    "SELECT COUNT(*) as total FROM ads a JOIN users u ON u.id=a.user_id WHERE $where_sql",
    $params, 24
);

// Active filters pentru pills
$active_filters = [];
if ($city_f)    $active_filters[] = ['label'=>'📍 '.$city_f,      'key'=>'city'];
if ($price_min) $active_filters[] = ['label'=>'Min: '.$price_min,  'key'=>'pmin'];
if ($price_max) $active_filters[] = ['label'=>'Max: '.$price_max,  'key'=>'pmax'];
if ($currency)  $active_filters[] = ['label'=>$currency,            'key'=>'cur'];
if ($has_video) $active_filters[] = ['label'=>'🎬 Cu video',       'key'=>'video'];
if ($negotiable)$active_filters[] = ['label'=>'💬 Negociabil',     'key'=>'neg'];
if ($verified)  $active_filters[] = ['label'=>'✅ Verificați',     'key'=>'verified'];

$cat_name   = $cat_id ? array_values(array_filter($categories, fn($c) => $c['id'] == $cat_id))[0]['name'] ?? '' : '';
$cat_icon   = $cat_id ? array_values(array_filter($categories, fn($c) => $c['id'] == $cat_id))[0]['icon'] ?? '' : '';
$seo_title  = $q ? "Rezultate pentru \"$q\" — Veedo" : ($cat_id ? "$cat_icon $cat_name — Veedo" : 'Caută anunțuri — Veedo');

include __DIR__ . '/includes/header.php';
?>
<style>
/* ── Layout ──────────────────────────────────────── */
.search-wrap {
    display: grid;
    grid-template-columns: 272px 1fr;
    gap: 24px;
    max-width: 1280px;
    margin: 0 auto;
    padding: 20px 20px 40px;
    align-items: flex-start;
}

/* ── Sidebar ─────────────────────────────────────── */
.filter-sidebar {
    position: sticky;
    top: 76px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
}
.filter-section {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-2);
}
.filter-section:last-child { border-bottom: none; }
.filter-label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.6px;
    color: var(--text-3); margin-bottom: 10px; display: block;
}
.filter-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.filter-title { font-weight: 700; font-size: 14px; }

/* Category list */
.cat-item {
    display: flex; align-items: center; gap: 7px;
    padding: 6px 8px; border-radius: 8px;
    font-size: 13px; text-decoration: none;
    color: var(--text-2); transition: all 0.15s;
    cursor: pointer;
}
.cat-item:hover { background: var(--bg-2); color: var(--text); }
.cat-item.active { background: var(--brand-light); color: var(--brand); font-weight: 600; }

/* Price inputs */
.price-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

/* Checkbox toggle */
.check-toggle {
    display: flex; align-items: center; justify-content: space-between;
    padding: 6px 0; cursor: pointer; font-size: 13px; color: var(--text-2);
    user-select: none;
}
.check-toggle input { display: none; }
.toggle-switch {
    width: 36px; height: 20px; border-radius: 10px;
    background: var(--bg-3); position: relative;
    transition: background 0.2s; flex-shrink: 0;
    border: 1.5px solid var(--border);
}
.toggle-switch::after {
    content: ''; position: absolute;
    width: 14px; height: 14px; border-radius: 50%;
    background: #fff; top: 1px; left: 1px;
    transition: transform 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.check-toggle input:checked ~ .toggle-switch { background: var(--brand); border-color: var(--brand); }
.check-toggle input:checked ~ .toggle-switch::after { transform: translateX(16px); }

/* ── Sort pills ──────────────────────────────────── */
.sort-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.sort-pill {
    padding: 5px 12px; border-radius: 100px;
    font-size: 12px; font-weight: 600;
    border: 1.5px solid var(--border);
    background: var(--surface); color: var(--text-2);
    text-decoration: none; transition: all 0.15s;
    white-space: nowrap;
}
.sort-pill:hover { border-color: var(--brand); color: var(--brand); }
.sort-pill.active { background: var(--brand); border-color: var(--brand); color: #fff; }

/* ── Active filter pills ─────────────────────────── */
.filter-pill {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(232,56,13,0.08); color: var(--brand);
    border: 1px solid rgba(232,56,13,0.25);
    border-radius: 100px; padding: 4px 10px;
    font-size: 12px; font-weight: 600;
    text-decoration: none; transition: all 0.15s;
}
.filter-pill:hover { background: rgba(232,56,13,0.15); }

/* ── Results header ──────────────────────────────── */
.results-header {
    display: flex; align-items: center;
    justify-content: space-between;
    flex-wrap: wrap; gap: 12px;
    margin-bottom: 16px;
}
.results-title { font-size: 1.05rem; font-weight: 700; }
.results-count { font-size: 13px; color: var(--text-3); font-weight: 400; margin-left: 6px; }

/* ── Mobile ──────────────────────────────────────── */
.filter-toggle-btn { display: none; }

@media (max-width: 900px) {
    .search-wrap { grid-template-columns: 1fr; padding: 14px; }

    .filter-toggle-btn {
        display: flex; align-items: center; justify-content: space-between;
        width: 100%; margin-bottom: 12px;
    }
    .filter-sidebar {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        z-index: 9999; border-radius: 0;
        overflow-y: auto;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    .filter-sidebar.open { transform: translateX(0); }
    .filter-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.5); z-index: 9998;
    }
    .filter-overlay.open { display: block; }
    .filter-mobile-close {
        display: flex; align-items: center; justify-content: space-between;
        padding: 16px; border-bottom: 1px solid var(--border);
        font-weight: 700; font-size: 15px;
    }
}
@media (min-width: 901px) {
    .filter-mobile-close { display: none; }
    .filter-overlay { display: none !important; }
}
</style>

<!-- Mobile overlay -->
<div class="filter-overlay" id="filter-overlay" onclick="closeFilters()"></div>

<div class="search-wrap">

    <!-- ── SIDEBAR ──────────────────────────────────── -->
    <div>
        <!-- Mobile toggle -->
        <button class="filter-toggle-btn btn btn-secondary" onclick="openFilters()">
            <span>🎚️ Filtre <?= count($active_filters)>0 ? '<span style="background:var(--brand);color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;">'.count($active_filters).'</span>' : '' ?></span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </button>

        <aside class="filter-sidebar" id="filter-sidebar">
            <!-- Mobile close -->
            <div class="filter-mobile-close">
                <span>🎚️ Filtre</span>
                <button onclick="closeFilters()" style="background:none;border:none;cursor:pointer;font-size:1.4rem;color:var(--text-3);">✕</button>
            </div>

            <form method="GET" action="/cauta" id="filter-form">
                <?php if ($q): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
                <?php if ($sort !== 'recent'): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>

                <!-- Header -->
                <div class="filter-header">
                    <span class="filter-title">Filtre</span>
                    <a href="/cauta<?= $q ? '?q='.urlencode($q) : '' ?>" style="font-size:12px;color:var(--brand);text-decoration:none;">Resetează</a>
                </div>

                <!-- Categorii -->
                <div class="filter-section">
                    <span class="filter-label">Categorie</span>
                    <a href="/cauta<?= $q ? '?q='.urlencode($q) : '' ?>"
                       class="cat-item <?= !$cat_id ? 'active' : '' ?>">
                        🔍 Toate categoriile
                    </a>
                    <?php foreach ($categories as $cat): ?>
                    <a href="/cauta?<?= http_build_query(array_merge(array_filter((array)$_GET), ['cat'=>$cat['id'],'page'=>1])) ?>"
                       class="cat-item <?= $cat_id == $cat['id'] ? 'active' : '' ?>">
                        <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Locatie -->
                <div class="filter-section">
                    <span class="filter-label">Locație</span>
                    <input type="text" name="city" class="form-control"
                           value="<?= h($city_f) ?>" placeholder="Caută oraș..."
                           list="cities-list" style="font-size:13px;">
                    <datalist id="cities-list">
                        <?php foreach ($top_cities as $tc): ?>
                        <option value="<?= h($tc['city']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Pret -->
                <div class="filter-section">
                    <span class="filter-label">Preț</span>
                    <div class="price-row" style="margin-bottom:8px;">
                        <input type="number" name="pmin" class="form-control"
                               value="<?= h($price_min ?: '') ?>" placeholder="Min"
                               style="font-size:13px;">
                        <input type="number" name="pmax" class="form-control"
                               value="<?= h($price_max ?: '') ?>" placeholder="Max"
                               style="font-size:13px;">
                    </div>
                    <select name="cur" class="form-control" style="font-size:13px;">
                        <option value="" <?= !$currency ? 'selected' : '' ?>>Orice monedă</option>
                        <option value="RON" <?= $currency==='RON' ? 'selected' : '' ?>>RON</option>
                        <option value="EUR" <?= $currency==='EUR' ? 'selected' : '' ?>>EUR</option>
                        <option value="USD" <?= $currency==='USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>

                <!-- Optiuni toggle -->
                <div class="filter-section">
                    <span class="filter-label">Opțiuni</span>

                    <label class="check-toggle">
                        <span>🎬 Doar cu video</span>
                        <input type="checkbox" name="video" value="1" <?= $has_video ? 'checked' : '' ?> onchange="this.form.submit()">
                        <div class="toggle-switch"></div>
                    </label>

                    <label class="check-toggle">
                        <span>💬 Preț negociabil</span>
                        <input type="checkbox" name="neg" value="1" <?= $negotiable ? 'checked' : '' ?> onchange="this.form.submit()">
                        <div class="toggle-switch"></div>
                    </label>

                    <label class="check-toggle">
                        <span>✅ Vânzători verificați</span>
                        <input type="checkbox" name="verified" value="1" <?= $verified ? 'checked' : '' ?> onchange="this.form.submit()">
                        <div class="toggle-switch"></div>
                    </label>
                </div>

                <div class="filter-section">
                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                        Aplică filtrele
                    </button>
                </div>
            </form>
        </aside>
    </div>

    <!-- ── REZULTATE ─────────────────────────────────── -->
    <div>

        <!-- Header -->
        <div class="results-header">
            <div>
                <span class="results-title">
                    <?php if ($q): ?>
                        Rezultate pentru „<?= h($q) ?>"
                    <?php elseif ($cat_id): ?>
                        <?= h($cat_icon) ?> <?= h($cat_name) ?>
                    <?php else: ?>
                        Toate anunțurile
                    <?php endif; ?>
                    <span class="results-count"><?= number_format($pagination['total']) ?> anunțuri</span>
                </span>
            </div>
        </div>

        <!-- Sort pills -->
        <div class="sort-pills" style="margin-bottom:14px;">
            <?php
            $sorts = [
                'recent'     => ['🕐', 'Recente'],
                'views'      => ['🔥', 'Populare'],
                'price_asc'  => ['💰', 'Preț ↑'],
                'price_desc' => ['💰', 'Preț ↓'],
                'likes'      => ['❤️', 'Apreciate'],
            ];
            foreach ($sorts as $k => [$icon, $lbl]):
                $url = '?' . http_build_query(array_merge((array)$_GET, ['sort'=>$k, 'page'=>1]));
            ?>
            <a href="<?= h($url) ?>" class="sort-pill <?= $sort === $k ? 'active' : '' ?>">
                <?= $icon ?> <?= $lbl ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Active filter pills -->
        <?php if (!empty($active_filters)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
            <?php foreach ($active_filters as $af):
                $remove_params = array_merge((array)$_GET, [$af['key'] => '']);
                unset($remove_params[$af['key']]);
            ?>
            <a href="/cauta?<?= http_build_query(array_filter($remove_params)) ?>" class="filter-pill">
                <?= h($af['label']) ?> ✕
            </a>
            <?php endforeach; ?>
            <?php if (count($active_filters) > 1): ?>
            <a href="/cauta<?= $q ? '?q='.urlencode($q) : '' ?>" class="filter-pill" style="opacity:.7;">
                Șterge toate ✕
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Results -->
        <?php if (empty($pagination['items'])): ?>
        <div style="text-align:center;padding:80px 20px;color:var(--text-3);">
            <div style="font-size:4rem;margin-bottom:16px;">🔍</div>
            <h3 style="margin-bottom:8px;color:var(--text);">Niciun anunț găsit</h3>
            <p style="font-size:14px;">Încearcă să modifici filtrele sau
                <a href="/cauta<?= $q ? '?q='.urlencode($q) : '' ?>" style="color:var(--brand);">resetează căutarea</a>.
            </p>
            <a href="/anunt/nou" class="btn btn-primary" style="margin-top:20px;display:inline-flex;">
                + Postează primul anunț
            </a>
        </div>
        <?php else: ?>
        <div class="items-grid">
            <?php foreach ($pagination['items'] as $ad): include __DIR__ . '/includes/ad-card.php'; endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pagination['pages'] > 1): ?>
        <nav style="display:flex;justify-content:center;gap:4px;margin-top:28px;flex-wrap:wrap;">
            <?php if ($pagination['page'] > 1): ?>
            <a href="?<?= http_build_query(array_merge((array)$_GET, ['page'=>$pagination['page']-1])) ?>" class="page-btn">← Anterior</a>
            <?php endif; ?>

            <?php
            $start = max(1, $pagination['page'] - 2);
            $end   = min($pagination['pages'], $pagination['page'] + 2);
            if ($start > 1): ?><a href="?<?= http_build_query(array_merge((array)$_GET, ['page'=>1])) ?>" class="page-btn">1</a><?php if ($start > 2): ?><span class="page-btn" style="pointer-events:none;">…</span><?php endif; endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
            <a href="?<?= http_build_query(array_merge((array)$_GET, ['page'=>$p])) ?>"
               class="page-btn <?= $p == $pagination['page'] ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($end < $pagination['pages']): ?><?php if ($end < $pagination['pages'] - 1): ?><span class="page-btn" style="pointer-events:none;">…</span><?php endif; ?><a href="?<?= http_build_query(array_merge((array)$_GET, ['page'=>$pagination['pages']])) ?>" class="page-btn"><?= $pagination['pages'] ?></a><?php endif; ?>

            <?php if ($pagination['page'] < $pagination['pages']): ?>
            <a href="?<?= http_build_query(array_merge((array)$_GET, ['page'=>$pagination['page']+1])) ?>" class="page-btn">Următor →</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<script>
function openFilters() {
    document.getElementById('filter-sidebar').classList.add('open');
    document.getElementById('filter-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeFilters() {
    document.getElementById('filter-sidebar').classList.remove('open');
    document.getElementById('filter-overlay').classList.remove('open');
    document.body.style.overflow = '';
}
// Inchide cu Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFilters(); });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>