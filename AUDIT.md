# Veedo.ro — Audit de securitate, performanță și redesign

Data: 13.10.2026 · Branch: `claude/busy-fermat-6ympkh`

Codul a fost verificat fișier cu fișier (API-uri, pagini publice, cont, admin, JS, `.htaccess`) și testat
local pe o copie a bazei de date (MariaDB + PHP 8.3): toate paginile publice, cele din cont și secțiunile
din admin răspund fără erori PHP sau JavaScript.

---

## 1. Ce trebuie făcut la deploy (obligatoriu)

1. **Backup** la fișiere și la baza de date.
2. **Rulează migrarea SQL** (o singură dată, din phpMyAdmin → SQL):
   `database/migrations/2026-10-13_securitate_performanta.sql`
   — repară două bug-uri de schemă (mesajele din profil și pozele în chat nu funcționau) și adaugă indexuri.
3. **Stripe**: completează în Admin → Setări → Plăți câmpul **`stripe_webhook_secret`** (din Stripe Dashboard →
   Webhooks → endpoint `https://veedo.ro/api/stripe-webhook.php`). Fără el, webhook-ul refuză acum orice eveniment
   (înainte accepta evenimente nesemnate — oricine putea marca o plată ca reușită).
4. **SMTP**: configurează SMTP în Admin. Resetarea parolei trimite acum email (înainte doar se loga, deci nu funcționa).
   Dacă serverul SMTP are certificat self-signed, setează `smtp_insecure = 1` (nerecomandat).
5. **Cron**: `cron/send-alerts.php` rulează acum **doar din linia de comandă** (era accesibil public din browser):
   `0 8 * * * php /home/UTILIZATOR/public_html/cron/send-alerts.php`
6. Migrarea setează și tema implicită pe **dark** și culorile noii palete (secțiunea 5 din fișierul SQL).
7. Verifică fișierul `.env` (model: `.env.example`) — `BASE_URL=https://veedo.ro`.
8. După deploy, deschide site-ul și verifică: login, postare anunț, mesaje, plată de test.

> **Atenție — anunțurile „dispărute” de pe homepage:** toate cele 22 de anunțuri din baza de date au
> `expires_at` în iunie 2026, iar homepage-ul afișează doar anunțurile neexpirate, deci prima pagină apare goală
> („Niciun anunț aici încă”). Nu am modificat datele. Dacă vrei să le reactivezi:
> `UPDATE ads SET expires_at = DATE_ADD(CURDATE(), INTERVAL 60 DAY) WHERE status='active';`

---

## 2. Vulnerabilități găsite și reparate

| # | Severitate | Problemă | Reparat prin |
|---|---|---|---|
| 1 | **Critică** | Webhook Stripe accepta evenimente **nesemnate** când secretul nu era setat → boost/Business gratuit | Semnătură obligatorie, toleranță 5 min, verificare sumă (`api/stripe-webhook.php`) |
| 2 | **Critică** | Upload stories: extensia lua din numele fișierului (`x.html`, `x.svg`) → **XSS stocat** pe domeniul veedo.ro | MIME real + extensie derivată din MIME (`upload_media_secure`) |
| 3 | **Critică** | `cron/send-alerts.php` accesibil public → oricine declanșa emailuri în masă și ștergeri în DB | Doar CLI + blocat în `.htaccess` |
| 4 | **Ridicată** | Lipsă CSRF pe chat, follow, notificări, story-uri, ștergere comentarii, temă etc. | **CSRF global** în `bootstrap.php` pentru orice POST/PUT/DELETE + shim JS automat |
| 5 | **Ridicată** | Plăți aplicate de mai multe ori (webhook + confirmare client + IPN) → boost și **comision afiliere dublat** | `order_mark_paid()` atomic și idempotent (`includes/payments.php`) |
| 6 | **Ridicată** | Retragere afiliere: dublu-click = **două retrageri** pentru același sold | Tranzacție cu `SELECT … FOR UPDATE` |
| 7 | **Ridicată** | XSS prin link-uri `javascript:` (story, website business, link stream LIVE) | Doar `http(s)` (`safe_url`) + escapare în JS |
| 8 | **Ridicată** | XSS prin numărul de telefon (ghilimele în `href="tel:…"`) | Normalizare telefon + escapare |
| 9 | **Ridicată** | SSRF prin Web Push: serverul făcea POST către orice URL trimis de utilizator | Doar servicii push reale, doar HTTPS |
| 10 | Medie | Open redirect la login (`/login?next=/\evil.com`) | `safe_redirect_path()` |
| 11 | Medie | `php.ini`, loguri (`video-compress.log`) descărcabile din browser (căi interne, erori) | Blocate în `.htaccess`; logul video scrie doar în `error_log` |
| 12 | Medie | Formular contact fără limită → spam prin domeniul tău (confirmare trimisă oricărei adrese) | Rate limit + câmp capcană + escapare HTML |
| 13 | Medie | Reveal telefon fără limită → scraping numere | 30/oră per utilizator |
| 14 | Medie | Editarea unui anunț aprobat publica text neaprobat și ocolea limita de „reactualizare” la 24h | Re-moderare la schimbarea textului; editarea nu mai face bump |
| 15 | Medie | Rolul „business” ales liber la înregistrare dădea acces la LIVE | LIVE doar pentru Business **verificat** |
| 16 | Medie | SMTP fără verificare certificat TLS (parola SMTP interceptabilă) | Verificare activă |
| 17 | Medie | Token resetare parolă salvat în clar; resetarea nu trimitea email | Hash SHA-256 + email real |
| 18 | Medie | Uploads: SVG permis (poate conține JS); restul extensiilor nu erau blocate | Doar imagini/video + CSP `sandbox` pe `/uploads` |
| 19 | Scăzută | `sanitize_html` păstra atributele (`onclick`, `style`) | Atributele sunt eliminate |
| 20 | Scăzută | Erori interne (mesaje PDO) afișate în răspunsuri JSON | Mesaje generice, detaliile în log |
| 21 | Scăzută | Header injection posibil în emailuri (CR/LF în nume/subiect) | `no_crlf()` |
| 22 | Scăzută | JSON-LD construit cu `addslashes()` (JSON invalid, risc `</script>`) | `json_ld()` / `js_json()` cu `JSON_HEX_TAG` |

**Headere de securitate noi** (`includes/security.php`): Content-Security-Policy, HSTS, Permissions-Policy,
Cross-Origin-Opener-Policy, X-Content-Type-Options, Referrer-Policy. Redirect automat HTTP → HTTPS.

**Rate limiting** adăugat pe: înregistrare, contact, telefon, chat, follow, rapoarte, recenzii, postare anunțuri
(max. 10/oră + limita `free_ads_per_user` care nu era aplicată), schimbare parolă, story-uri.

---

## 3. Bug-uri funcționale găsite și reparate

- `api/follow.php` avea **eroare de sintaxă** → butonul „Urmărește” nu funcționa deloc.
- Mesajele flash („Anunțul a fost publicat”, erori de validare…) **nu erau afișate nicăieri** pe site → acum apar ca toast-uri.
- `/alerte` dădea **eroare fatală** (`flash_render()` inexistentă).
- „Trimite mesaj” din profil eșua (`conversations.ad_id NOT NULL`) și conversațiile fără anunț erau invizibile.
- Trimiterea de poze în chat eșua (lipsea coloana `messages.image_path`).
- Lista de mesaje afișa **numele tău** în locul interlocutorului.
- `/categorie/{slug}` (din sitemap) ignora categoria și afișa toate anunțurile.
- Fus orar diferit între PHP și MySQL → „acum 3 ore” la un comentariu tocmai postat.
- Recomandările generau SQL invalid (`IN ( OR 1=0)`).
- `api/push-subscribe.php` era în `includes/` (blocat) → abonarea la notificări push nu funcționa.
- Chat: mesajele erau salvate deja escapate și apoi escapate din nou (`&amp;quot;`); se afișau primele 200 de mesaje, nu ultimele.
- Boost-urile expirate rămâneau „promovate” în sortare → cron-ul resetează acum `is_boosted`.
- Culorile brandului din Admin → Aspect se salvau, dar nu se aplicau pe site.
- În producție `error_reporting(0)` ascundea erorile și din loguri → acum se loghează (fără a fi afișate).

---

## 4. Performanță

- **Indexuri noi** pentru listări, vizualizări unice, comentarii, notificări, stories, mesaje, conversații;
  eliminate 4 indexuri duplicate (încetineau scrierile).
- **Cache APCu** (dacă e disponibil pe server) pentru setări și footer; fallback automat fără APCu.
- Comentarii fără N+1 (un singur query pentru toate răspunsurile); stories fără N+1.
- Chat: polling-ul la 4 s nu mai face `UPDATE` dacă nu există mesaje noi; notificările se verifică doar cu tab-ul vizibil.
- Fonturi încărcate cu `preconnect` + `display=swap` (înainte `@import` bloca randarea CSS-ului).
- JavaScript cu `defer`; scripturile de upload (compresie imagini, progres video) doar pe paginile cu upload.
- Primele 4 carduri fără lazy-load (LCP mai bun), restul `loading="lazy"` + `decoding="async"` + dimensiuni fixe (fără layout shift).
- Avatare cu inițiale generate local (SVG) în loc de ui-avatars.com: zero cereri externe și **numele utilizatorilor
  nu mai sunt trimise unui terț** (GDPR).
- Compresie gzip/brotli și cache lung pentru asset-uri versionate în `.htaccess`.
- `UPDATE stories …` care rula la fiecare afișare a paginii `/stories` a fost mutat în cron.

Măsurat local: paginile se generează în 5–15 ms, cu 4–20 interogări SQL.

---

## 5. Design nou — „Veedo 2027 / Aurora”

- **Dark-first**, cu lumină „aurora” (violet / roz / volt) animată în spatele întregului site, granulație fină și
  panouri de sticlă (glassmorphism). Tema luminoasă rămâne disponibilă (buton lună din header / footer, salvată în cont).
- **Paletă nouă**: verde „volt” `#D4FF3F` pentru acțiuni și prețuri, violet electric pentru brand, roz neon pentru like-uri.
  În Admin → Aspect: culoarea principală = accentul volt, secundara = violetul (migrarea le setează automat).
- **Tipografie**: Unbounded (titluri late), Geist (text), Geist Mono (prețuri, cifre, etichete).
- **Header „insulă”** plutitoare cu meniu (Anunțuri · Explore · Stories · LIVE) și **command palette**:
  `Ctrl/⌘+K` sau `/` deschide căutarea instant cu rezultate live, navigabilă din tastatură.
- **Homepage bento**: tile principal cu titlu mare și căutare, tile cu **video care rulează automat** (cel mai vizionat
  anunț video), contor de anunțuri video, CTA „Filmează. Postează. Vinde.”, LIVE shopping, avantaje.
- **Carduri verticale 9:16 tip Reels**: imagine full-bleed, preț în etichetă volt, iar pe desktop **videoul anunțului
  pornește la hover** (fără sunet). Categoriile sunt tile-uri mari pe un rail orizontal.
- Footer cu CTA în gradient și wordmark „veedo” uriaș; dock plutitor pe mobil cu buton central „+”.

## 6. Date demo

Platforma poate fi populată cu date demo realiste (16 utilizatori dintre care 7 business verificați, 52 de anunțuri
în toate categoriile, cu 3–4 poze fiecare, câmpuri specifice categoriei, like-uri, comentarii cu răspunsuri, recenzii,
urmăriri, conversații și stories):

- **Din Admin → 🧪 Date demo** (`/admin/demo-data.php`) — un buton, fără SSH.
- **Din linia de comandă:** `php database/seed-demo.php` (opțiuni: `--revive` prelungește anunțurile reale expirate,
  `--offline` generează pozele local, `--remove` șterge doar datele demo).

Pozele reale se descarcă în paralel de pe LoremFlickr (licențe Creative Commons) după cuvinte cheie potrivite anunțului;
dacă serverul nu are acces la internet, se generează automat imagini „copertă” în stilul noului design.
Toți utilizatorii demo au email `@demo.veedo.ro` și o parolă aleatorie (nimeni nu se poate autentifica în conturile lor),
iar la fiecare rulare datele demo anterioare (inclusiv fișierele) sunt șterse — conținutul real nu este atins.

## 7. Recomandări rămase (nu au fost implementate)

- **CSP strict fără `'unsafe-inline'`**: paginile au mult JavaScript inline și handler-e `onclick=`. Mutarea lor în
  fișiere `.js` ar permite un CSP cu nonce — cea mai bună protecție suplimentară împotriva XSS.
- **2FA pentru conturile de admin** și limitarea panoului `/admin` la IP-uri cunoscute.
- **Conținutul paginilor CMS** (`pages.content`) e HTML brut scris de admin; dacă vor edita și alte persoane, adaugă un sanitizer (ex. HTML Purifier).
- **Fișiere mari pe shared hosting**: compresia video cu ffmpeg rulează în timpul cererii (până la 5 min). O coadă
  procesată din cron ar face postarea instantă.
- Monitorizare: activează logurile PHP (`php.ini` → `error_log`) și verifică-le periodic.
- Vizualizările (`ad_views`) cresc continuu; cron-ul păstrează 90 de zile — ajustează după nevoie.

---

## 8. Fișiere noi

- `includes/security.php` — headere, CSRF global, validatori (URL, telefon, culoare, push endpoint), upload securizat
- `includes/payments.php` — aplicarea atomică a plăților, verificare semnătură Stripe
- `api/csrf-token.php` — token CSRF pentru service worker
- `database/schema.sql` — structura bazei de date (fără date personale)
- `database/migrations/2026-10-13_securitate_performanta.sql`
- `.env.example`
- `database/seed/` + `database/seed-demo.php` + `admin/demo-data.php` — date demo
