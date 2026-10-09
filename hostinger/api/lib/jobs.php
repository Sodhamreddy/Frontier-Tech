<?php
// The two jobs: manual/auto refresh (live prices into quotes/live, no email) and the
// daily close (quotes/latest + history + alerts/state + the summary email).
// A lock file stops two jobs (say the cron and a Refresh click) running at once.
declare(strict_types=1);

function ny_date(?int $ts = null): string {
  return (new DateTime('@' . ($ts ?? time())))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
}
function market_open(?int $ts = null): bool {
  $d = (new DateTime('@' . ($ts ?? time())))->setTimezone(new DateTimeZone('America/New_York'));
  $m = (int)$d->format('G') * 60 + (int)$d->format('i');
  return (int)$d->format('N') <= 5 && $m >= 9 * 60 + 30 && $m < 16 * 60 + 5;
}

// Yahoo symbols to try, in order: explicit `yahoo`, then every clean ticker found in the
// symbol or key ("CCXI (AGLT)" → CCXI, AGLT; "BRK.B" → BRK-B).
function syms_for(array $s, string $k): array {
  if (!empty($s['yahoo'])) return [$s['yahoo']];
  $out = [];
  foreach ([$s['symbol'] ?? '', $k] as $v) {
    foreach (preg_split('/[\s()\/,]+/', (string)$v) ?: [] as $t) {
      if ($t === '' || !preg_match('/^[A-Z0-9.\-^=]+$/i', $t)) continue;
      if (preg_match('/^[A-Z]+\.[A-Z]$/i', $t)) $t = str_replace('.', '-', $t);
      $t = strtoupper($t);
      if (!in_array($t, $out, true)) $out[] = $t;
    }
  }
  return $out ?: [$s['symbol'] ?? $k];
}

function tracked_keys(?array $uni): array {
  $out = [];
  foreach (($uni['stocks'] ?? []) as $k => $s) if (($s['status'] ?? 'ok') === 'ok') $out[] = (string)$k;
  return $out;
}

// Fetches the benchmark close for any tracked stock that doesn't have one yet.
// A new benchmark date in Settings starts the benchmark over.
function ensure_benchmark(): array {
  $date = settings_get()['benchDate'];
  $uni = store_read('config/universe');
  $bench = store_read('quotes/benchmark') ?? ['date' => $date, 'prices' => []];
  if (!empty($bench['date']) && $bench['date'] !== $date) $bench = ['date' => $date, 'prices' => []];
  $bench['prices'] = $bench['prices'] ?? [];
  $cands = [];
  foreach (tracked_keys($uni) as $k) if (!isset($bench['prices'][$k])) $cands[$k] = syms_for($uni['stocks'][$k], $k);
  if (!$cands) return ['added' => 0, 'failed' => []];
  [$ok, $fail] = fetch_many($cands, 'close', $date);
  foreach ($ok as $k => $v) $bench['prices'][$k] = $v;
  $bench['date'] = $date;
  $bench['updatedAt'] = gmdate('c');
  store_write('quotes/benchmark', $bench);
  return ['added' => count($ok), 'failed' => $fail];
}

function fetch_all(): array {
  $uni = store_read('config/universe');
  $keys = tracked_keys($uni);
  if (!$keys) throw new RuntimeException('the watchlist is empty');
  $cands = [];
  foreach ($keys as $k) $cands[$k] = syms_for($uni['stocks'][$k], $k);
  [$ok, $fail] = fetch_many($cands, 'quote');
  if (!$ok) throw new RuntimeException('no prices came back from Yahoo Finance' . ($fail ? ': ' . $fail[0] : ''));
  $prices = []; $times = [];
  foreach ($ok as $k => $q) { $prices[$k] = $q['price']; $times[$k] = $q['time']; }
  return [$uni, $prices, $times, $fail];
}

function job_state(): array { return store_read('runtime/jobs') ?? ['running' => false]; }

// Runs $fn under an exclusive lock; a second job while one runs gets a 409.
function run_job(string $kind, callable $fn): array {
  @set_time_limit(300);
  ignore_user_abort(true);
  $lock = fopen(ftw_dir() . '/.jobs.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('a refresh is already running', 409);
  store_write('runtime/jobs', ['running' => true, 'kind' => $kind, 'startedAt' => gmdate('c'), 'finishedAt' => null, 'lastError' => null]);
  try {
    try { ensure_benchmark(); } catch (Throwable $e) { /* missing closes are retried next run */ }
    $out = $fn();
    store_write('runtime/jobs', ['running' => false, 'kind' => $kind, 'startedAt' => job_state()['startedAt'] ?? null, 'finishedAt' => gmdate('c'), 'lastError' => null]);
    return $out;
  } catch (Throwable $e) {
    store_write('runtime/jobs', ['running' => false, 'kind' => $kind, 'startedAt' => job_state()['startedAt'] ?? null, 'finishedAt' => gmdate('c'), 'lastError' => $e->getMessage()]);
    throw $e;
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function run_manual(string $kind = 'manual'): array {
  return run_job($kind, function () use ($kind) {
    [, $prices, , $fail] = fetch_all();
    $asOf = gmdate('Y-m-d\TH:i:s\Z');
    store_write('quotes/live', ['asOf' => $asOf, 'kind' => $kind, 'prices' => $prices]);
    store_update('config/main', ['lastRefresh' => $asOf, 'lastFailed' => $fail]);
    return ['count' => count($prices), 'failed' => $fail];
  });
}

function run_daily(bool $force = false, bool $email = true): array {
  return run_job('daily', function () use ($force, $email) {
    [$uni, $prices, $times, $fail] = fetch_all();
    $now = time();
    $marketDate = ny_date($now);
    // Holiday check: if no quote is stamped today, the market was closed.
    $traded = false;
    foreach ($times as $t) if (ny_date(intdiv($t, 1000)) === $marketDate) { $traded = true; break; }
    if (!$traded && !$force) return ['skipped' => true, 'marketDate' => $marketDate];
    $asOf = gmdate('Y-m-d\TH:i:s\Z', $now);
    $prevLatest = store_read('quotes/latest');
    store_write('quotes/latest', ['asOf' => $asOf, 'marketDate' => $marketDate, 'prices' => $prices]);

    $month = substr($marketDate, 0, 7);
    $h = store_read("history/$month") ?? ['days' => []];
    $h['days'] = $h['days'] ?? [];
    $h['days'][$marketDate] = $prices;
    store_write("history/$month", $h);

    $thr = (float)settings_get()['alertPct'];
    $bench = store_read('quotes/benchmark')['prices'] ?? [];
    $pct = fn($k) => (!empty($bench[$k]) && isset($prices[$k])) ? ($prices[$k] / $bench[$k] - 1) * 100 : null;
    $below = array_values(array_filter(array_keys($prices), fn($k) => $pct($k) !== null && $pct($k) <= $thr));
    // `below` is the claude.ai shape, { KEY: { pct, since } }; older runs wrote a list.
    $prev = store_read('alerts/state') ?? [];
    $prevBelow = $prev['below'] ?? [];
    if (array_is_list($prevBelow)) $prevBelow = array_fill_keys($prevBelow, []);
    $lastNew = array_values(array_filter($below, fn($k) => !isset($prevBelow[$k])));
    $belowMap = [];
    foreach ($below as $k) $belowMap[$k] = ['pct' => round($pct($k), 2), 'since' => $prevBelow[$k]['since'] ?? $marketDate];
    $log = array_values(array_filter($prev['log'] ?? [], fn($e) => ($e['date'] ?? '') !== $marketDate));
    if ($lastNew) $log[] = ['date' => $marketDate, 'new' => $lastNew];
    store_write('alerts/state', ['below' => $belowMap, 'lastNew' => $lastNew, 'log' => $log, 'thresholdPct' => $thr, 'marketDate' => $marketDate, 'updatedAt' => $asOf]);
    store_update('config/main', ['lastRefresh' => $asOf, 'lastFailed' => $fail, 'lastDaily' => $marketDate]);

    $prevPrices = ($prevLatest && ($prevLatest['marketDate'] ?? '') !== $marketDate) ? ($prevLatest['prices'] ?? []) : previous_day_prices($marketDate);
    if ($email && settings_get()['dailyEmail']) {
      try { send_daily_email($uni, $prices, $bench, $prevPrices, $below, $lastNew, $marketDate, $fail); }
      catch (Throwable $e) { store_update('config/main', ['lastEmailError' => $e->getMessage()]); }
    }
    return ['marketDate' => $marketDate, 'count' => count($prices), 'below' => count($below), 'lastNew' => $lastNew, 'failed' => $fail];
  });
}

function previous_day_prices(string $marketDate): array {
  $best = null;
  foreach (store_history() as $m) foreach (($m['days'] ?? []) as $d => $p) if ($d < $marketDate && ($best === null || $d > $best[0])) $best = [$d, $p];
  return $best[1] ?? [];
}
