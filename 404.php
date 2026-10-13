<?php
// 404.php — Pagina nu a fost găsită
require_once __DIR__ . '/includes/bootstrap.php';
http_response_code(404);
$seo_title = 'Pagina nu a fost găsită';
include __DIR__ . '/includes/header.php';
?>
<div style="min-height:60vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;">
  <div style="text-align:center;max-width:480px;">
    <div style="font-size:6rem;margin-bottom:16px;line-height:1;">🔍</div>
    <h1 style="font-size:2rem;margin-bottom:10px;">404 — Pagina nu există</h1>
    <p style="color:var(--text-3);font-size:1.05rem;margin-bottom:28px;line-height:1.6;">
      Pagina pe care o cauți nu există sau a fost mutată.<br>
      Poate anunțul a fost șters sau ai urmat un link greșit.
    </p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a href="/" class="btn btn-primary">🏠 Acasă</a>
      <a href="/cauta" class="btn btn-secondary">🔍 Caută anunțuri</a>
    </div>
    <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--border-2);">
      <p style="font-size:13px;color:var(--text-3);margin-bottom:12px;">Sau caută direct:</p>
      <form action="/cauta" method="GET" style="display:flex;gap:8px;max-width:360px;margin:0 auto;">
        <input type="text" name="q" class="form-control" placeholder="Ce cauți?" autofocus>
        <button type="submit" class="btn btn-primary">→</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
