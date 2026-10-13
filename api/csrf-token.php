<?php
// api/csrf-token.php — întoarce tokenul CSRF al sesiunii curente.
// Folosit de service worker (sw.js), care nu are acces la <meta name="csrf-token">.
// Sigur: răspunsul nu poate fi citit cross-origin (fără CORS).
require_once __DIR__ . '/../includes/bootstrap.php';
header('Cache-Control: no-store');
if (!is_logged()) json_response(['error' => 'Neautorizat'], 401);
json_response(['token' => csrf_token()]);
