<?php
// Local testing only: `php -S localhost:8080 dev-router.php` inside dist/. Mirrors the
// .htaccess routing, which PHP's built-in server ignores. Not uploaded to the host.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(data|data-initial|api/lib)(/|$)#', $path) || preg_match('#/config(\.sample)?\.php$#', $path)) { http_response_code(404); exit; }
if (preg_match('#^/api/(.*)$#', $path, $m) && $m[1] !== 'cron.php') {
  $_GET['route'] = $m[1];
  require __DIR__ . '/api/index.php';
  return true;
}
return false;
