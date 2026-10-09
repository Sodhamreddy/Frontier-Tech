<?php
// Run by an hPanel cron job every 5 minutes. Does whatever is due:
//   - auto-refresh: live prices every N minutes while the US market is open (Settings)
//   - daily close: once per weekday after daily_time America/Chicago (config.php)
// CLI:   /usr/bin/php /home/USER/domains/DOMAIN/public_html/api/cron.php
// HTTPS: https://DOMAIN/api/cron.php?key=CRON_KEY   (only if cron_key is set)
declare(strict_types=1);
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/prices.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/jobs.php';

if (PHP_SAPI !== 'cli') {
  $key = (string)ftw_cfg()['cron_key'];
  if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("forbidden\n"); }
  header('Content-Type: text/plain');
}

$log = function (string $msg) {
  $line = gmdate('Y-m-d H:i:s') . "Z  $msg\n";
  echo $line;
  $f = ftw_dir() . '/runtime/cron.log';
  if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
  if (is_file($f) && filesize($f) > 200000) file_put_contents($f, substr((string)file_get_contents($f), -100000));
  file_put_contents($f, $line, FILE_APPEND);
};

$cfg = settings_get();
$errors = [];
$ran = false;

// Daily close: weekdays, after daily_time Chicago, once per market date.
$chi = new DateTime('now', new DateTimeZone('America/Chicago'));
[$hh, $mm] = array_map('intval', explode(':', (string)ftw_cfg()['daily_time']));
$today = ny_date();
$doneFor = store_read('runtime/cron')['dailyFor'] ?? '';
if ((int)$chi->format('N') <= 5 && ((int)$chi->format('G') * 60 + (int)$chi->format('i')) >= $hh * 60 + $mm && $doneFor !== $today) {
  try {
    $r = run_daily();
    $log(!empty($r['skipped']) ? "[daily] $today: no session today, skipped" : "[daily] {$r['marketDate']}: {$r['count']} closes, {$r['below']} below line (" . count($r['lastNew']) . ' new)');
    store_update('runtime/cron', ['dailyFor' => $today]);
    $ran = true;
  } catch (Throwable $e) { $errors[] = 'daily: ' . $e->getMessage(); $log('[daily] failed: ' . $e->getMessage()); }
}

// Auto-refresh while the market is open.
if (!$ran && $cfg['autoRefresh'] && market_open()) {
  $last = strtotime((string)(store_read('quotes/live')['asOf'] ?? '')) ?: 0;
  if (time() - $last >= $cfg['autoRefreshMin'] * 60 - 30) {
    try { $r = run_manual('auto'); $log("[auto] {$r['count']} live prices, " . count($r['failed']) . ' failed'); }
    catch (Throwable $e) { $errors[] = 'auto: ' . $e->getMessage(); $log('[auto] failed: ' . $e->getMessage()); }
  }
}

store_update('runtime/cron', ['lastRun' => gmdate('c'), 'lastError' => $errors ? implode('; ', $errors) : null]);
