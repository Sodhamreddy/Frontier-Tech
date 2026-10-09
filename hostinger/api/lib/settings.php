<?php
// User-editable settings, kept in config/settings. config.php values are only the defaults.
declare(strict_types=1);

const FTW_INTERVALS = [5, 10, 15, 30, 60];

function settings_defaults(): array {
  $c = ftw_cfg();
  return [
    'alertPct' => (float)$c['alert_pct'],
    'spikePct' => (float)$c['spike_pct'],
    'benchDate' => (string)$c['bench_date'],
    'mailTo' => (string)$c['mail_to'],
    'dailyEmail' => true,
    'autoRefresh' => true,
    'autoRefreshMin' => 15,
  ];
}

function settings_get(): array {
  return array_merge(settings_defaults(), store_read('config/settings') ?? []);
}

function ftw_fail(string $msg, int $status = 400): void {
  throw new RuntimeException($msg, $status);
}

function settings_set(array $patch): array {
  $out = [];
  if (array_key_exists('alertPct', $patch)) {
    $v = $patch['alertPct'];
    if (!is_numeric($v) || $v > -0.5 || $v < -50) ftw_fail('Alert line must be between −50% and −0.5%');
    $out['alertPct'] = round((float)$v, 1);
  }
  if (array_key_exists('spikePct', $patch)) {
    $v = $patch['spikePct'];
    if (!is_numeric($v) || $v < 1 || $v > 200) ftw_fail('Spike threshold must be between 1% and 200%');
    $out['spikePct'] = round((float)$v, 1);
  }
  if (array_key_exists('benchDate', $patch)) {
    $d = (string)$patch['benchDate'];
    $dt = DateTime::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
    if (!$dt || $dt->format('Y-m-d') !== $d) ftw_fail('Benchmark date must be a valid date');
    if ((int)$dt->format('N') >= 6) ftw_fail('Benchmark date must be a weekday (a trading day)');
    if ($dt->getTimestamp() > time()) ftw_fail("Benchmark date can't be in the future");
    $out['benchDate'] = $d;
  }
  if (array_key_exists('mailTo', $patch)) {
    $m = trim((string)$patch['mailTo']);
    foreach (array_filter(array_map('trim', explode(',', $m))) as $a) {
      if (!filter_var($a, FILTER_VALIDATE_EMAIL)) ftw_fail('Enter a valid email address (separate several with commas)');
    }
    $out['mailTo'] = $m;
  }
  if (array_key_exists('dailyEmail', $patch)) $out['dailyEmail'] = (bool)$patch['dailyEmail'];
  if (array_key_exists('autoRefresh', $patch)) $out['autoRefresh'] = (bool)$patch['autoRefresh'];
  if (array_key_exists('autoRefreshMin', $patch)) {
    $v = (int)$patch['autoRefreshMin'];
    if (!in_array($v, FTW_INTERVALS, true)) ftw_fail('Auto-refresh interval must be one of ' . implode(', ', FTW_INTERVALS) . ' minutes');
    $out['autoRefreshMin'] = $v;
  }
  store_write('config/settings', array_merge(store_read('config/settings') ?? [], $out));
  return settings_get();
}
