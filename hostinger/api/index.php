<?php
// One entry point for every /api/* request (the .htaccess next to index.html routes here).
// Same routes and JSON as the Node server, so the dashboard page is unchanged.
declare(strict_types=1);
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/prices.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/jobs.php';
require __DIR__ . '/lib/watchlist.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string)($_GET['route'] ?? ''), '/');
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

function reply($data, int $status = 200): void { http_response_code($status); echo ftw_json($data); exit; }

// Who may edit: with admin_key set, whoever sends it as x-admin-key; without one, only
// requests from this computer (local testing).
function can_edit(): bool {
  $key = (string)ftw_cfg()['admin_key'];
  if ($key !== '') return hash_equals($key, (string)($_SERVER['HTTP_X_ADMIN_KEY'] ?? ''));
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  return empty($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($ip, ['127.0.0.1', '::1'], true);
}
function editor(): void {
  if (!can_edit()) reply(['error' => ftw_cfg()['admin_key'] !== '' ? 'Enter the admin key to make changes' : 'Set admin_key in api/config.php to make changes online'], 401);
}

function public_settings(): array {
  return array_merge(settings_get(), [
    'intervals' => FTW_INTERVALS, 'families' => FTW_FAMILIES, 'smtpConfigured' => smtp_configured(),
    'canEdit' => can_edit(), 'needsKey' => ftw_cfg()['admin_key'] !== '',
    'dailyCron' => 'weekdays ' . ftw_cfg()['daily_time'] . ' America/Chicago (hPanel cron)',
  ]);
}

function auto_info(): array {
  $cfg = settings_get();
  $open = market_open();
  $next = null;
  if ($cfg['autoRefresh'] && $open) {
    $last = strtotime((string)(store_read('quotes/live')['asOf'] ?? '')) ?: 0;
    $due = max($last + $cfg['autoRefreshMin'] * 60, time());
    $next = gmdate('Y-m-d\TH:i:s\Z', (int)(ceil($due / 300) * 300)); // the cron runs every 5 minutes
  }
  return ['enabled' => (bool)$cfg['autoRefresh'], 'everyMin' => $cfg['autoRefreshMin'], 'marketOpen' => $open, 'nextAt' => $next, 'lastError' => store_read('runtime/cron')['lastError'] ?? null];
}

try {
  switch (true) {
    case $route === 'state' && $method === 'GET':
      $js = job_state();
      $main = store_read('config/main') ?? [];
      $main['emailTo'] = settings_get()['mailTo'] ?: null;
      reply([
        'stamp' => store_stamp(), 'main' => $main,
        'uni' => store_read('config/universe'), 'bench' => store_read('quotes/benchmark'),
        'latest' => store_read('quotes/latest'), 'live' => store_read('quotes/live'),
        'alerts' => store_read('alerts/state'), 'hist' => store_history(),
        'settings' => public_settings(),
        'refresh' => ['running' => (bool)($js['running'] ?? false), 'kind' => $js['kind'] ?? null, 'startedAt' => $js['startedAt'] ?? null, 'finishedAt' => $js['finishedAt'] ?? null, 'lastError' => $js['lastError'] ?? null],
        'auto' => auto_info(),
      ]);

    // Refresh button. Public, so it is rate-limited; it runs in this request (10–30 s).
    case $route === 'refresh' && $method === 'POST':
      $cool = store_read('runtime/cooldown')['at'] ?? 0;
      if (time() - $cool < 60) reply(['error' => 'refreshed moments ago'], 409);
      store_write('runtime/cooldown', ['at' => time()]);
      run_manual();
      reply(['started' => true, 'done' => true], 202);

    case $route === 'settings' && $method === 'GET': reply(public_settings());
    case $route === 'settings' && $method === 'PUT':
      editor();
      $before = settings_get()['benchDate'];
      settings_set($body);
      if (settings_get()['benchDate'] !== $before) { try { ensure_benchmark(); } catch (Throwable $e) {} }
      reply(public_settings());
    case $route === 'settings/test-email' && $method === 'POST': editor(); send_test_email(); reply(['sent' => true]);

    case $route === 'lookup' && $method === 'GET': editor(); reply(wl_lookup($_GET['symbol'] ?? ''));
    case $route === 'stocks' && $method === 'POST': editor(); reply(wl_add($body));
    case strpos($route, 'stocks/') === 0 && $method === 'DELETE': editor(); reply(wl_remove(rawurldecode(substr($route, 7))));
    case $route === 'lists' && $method === 'POST': editor(); reply(wl_create($body));
    case $route === 'lists' && $method === 'PATCH': editor(); reply(wl_rename($body));
    case $route === 'lists' && $method === 'DELETE': editor(); reply(wl_delete($body));
    case $route === 'lists/membership' && $method === 'POST': editor(); reply(wl_membership($body));

    // Record the daily close now. ?force=1 runs on a market holiday, ?email=0 skips the email.
    case $route === 'admin/run-daily' && $method === 'POST':
      editor();
      reply(run_daily(($_GET['force'] ?? '') === '1', ($_GET['email'] ?? '') !== '0'));

    case $route === 'healthz': reply(['ok' => true, 'php' => PHP_VERSION]);
    default: reply(['error' => 'not found'], 404);
  }
} catch (RuntimeException $e) {
  $c = $e->getCode();
  reply(['error' => $e->getMessage()], $c >= 400 && $c < 600 ? $c : 400);
} catch (Throwable $e) {
  reply(['error' => 'Server error: ' . $e->getMessage()], 500);
}
