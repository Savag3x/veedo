<?php
// public/robots.php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /admin/\n";
echo "Disallow: /api/\n";
echo "Disallow: /cont/\n";
echo "Disallow: /mesaje/\n";
echo "Disallow: /notificari/\n";
echo "Disallow: /login\n";
echo "Disallow: /register\n";
echo "Disallow: /logout\n";
echo "\nSitemap: " . BASE_URL . "/sitemap.xml\n";
