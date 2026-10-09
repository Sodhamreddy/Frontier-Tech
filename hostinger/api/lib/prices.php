<?php
// Price source: Yahoo Finance chart endpoint (no API key). Requests run through a rolling
// curl_multi window (a new one starts as soon as any finishes); a request that fails on
// query1 is retried once on query2.
declare(strict_types=1);

const FTW_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

// $reqs: id => [symbol, params]. Returns id => ['res' => chart result] or ['err' => message, 'fatal' => bool].
function yahoo_multi(array $reqs): array {
  $out = [];
  $todo = $reqs;
  $window = max(1, (int)ftw_cfg()['fetch_concurrency']);
  foreach (['query1.finance.yahoo.com', 'query2.finance.yahoo.com'] as $host) {
    if (!$todo) break;
    $queue = $todo;
    $mh = curl_multi_init();
    $live = []; // spl_object_id => [id, handle]
    $start = function () use (&$queue, &$live, $mh, $host) {
      $id = array_key_first($queue);
      [$sym, $params] = $queue[$id];
      unset($queue[$id]);
      $ch = curl_init("https://$host/v8/finance/chart/" . rawurlencode($sym) . '?' . http_build_query($params));
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . FTW_UA, 'Accept: application/json'], CURLOPT_ENCODING => '']);
      curl_multi_add_handle($mh, $ch);
      $live[spl_object_id($ch)] = [$id, $ch];
    };
    while ($queue && count($live) < $window) $start();
    while ($live) {
      curl_multi_exec($mh, $running);
      // Each finished transfer is read here; transfer errors (DNS, TLS, timeouts) only show up this way.
      while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        [$id] = $live[spl_object_id($ch)];
        unset($live[spl_object_id($ch)]);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $j = json_decode((string)curl_multi_getcontent($ch), true);
        curl_multi_remove_handle($mh, $ch);
        $res = $j['chart']['result'][0] ?? null;
        if ($code === 200 && $res) { $out[$id] = ['res' => $res]; unset($todo[$id]); }
        else {
          $desc = $j['chart']['error']['description'] ?? null;
          $fatal = $code === 404 || ($code === 200 && !$res);
          $cerr = $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : '';
          $out[$id] = ['err' => $code === 404 ? 'symbol not found' : ($desc ?: ($cerr ?: "HTTP $code")), 'fatal' => $fatal];
          if ($fatal) unset($todo[$id]);
        }
        if ($queue) $start();
      }
      if ($live) curl_multi_select($mh, 0.5);
    }
    curl_multi_close($mh);
  }
  return $out;
}

function quote_from(array $res): ?array {
  $m = $res['meta'] ?? [];
  if (!isset($m['regularMarketPrice'])) return null;
  return [
    'price' => (float)$m['regularMarketPrice'], 'time' => (int)($m['regularMarketTime'] ?? 0) * 1000,
    'currency' => $m['currency'] ?? null, 'symbol' => $m['symbol'] ?? null,
    'name' => $m['longName'] ?? ($m['shortName'] ?? null),
    'exchange' => $m['fullExchangeName'] ?? ($m['exchangeName'] ?? null),
  ];
}

// Regular-session close on an exchange-local date (YYYY-MM-DD), or null.
function close_from(array $res, string $date): ?float {
  $ts = $res['timestamp'] ?? [];
  $closes = $res['indicators']['quote'][0]['close'] ?? [];
  $off = (int)($res['meta']['gmtoffset'] ?? 0);
  foreach ($ts as $i => $t) {
    if (gmdate('Y-m-d', (int)$t + $off) === $date && isset($closes[$i])) return (float)$closes[$i];
  }
  return null;
}

const Q_PARAMS = ['range' => '5d', 'interval' => '1d'];
function close_params(string $date): array {
  $t = strtotime($date . ' 00:00:00 UTC');
  return ['period1' => $t - 3 * 86400, 'period2' => $t + 3 * 86400, 'interval' => '1d'];
}

function quote_one(string $sym): ?array {
  $r = yahoo_multi(['x' => [$sym, Q_PARAMS]])['x'] ?? null;
  return isset($r['res']) ? quote_from($r['res']) : null;
}

// Tries each key's candidate symbols in turn ($cands: key => [sym, ...]).
// $kind 'quote' returns key => quote array; 'close' returns key => float for $date.
function fetch_many(array $cands, string $kind, string $date = ''): array {
  $ok = []; $fail = []; $pos = array_fill_keys(array_keys($cands), 0);
  while ($pos) {
    $reqs = [];
    foreach ($pos as $k => $i) $reqs[$k] = [$cands[$k][$i], $kind === 'quote' ? Q_PARAMS : close_params($date)];
    $res = yahoo_multi($reqs);
    foreach ($pos as $k => $i) {
      $r = $res[$k] ?? ['err' => 'no response'];
      $v = isset($r['res']) ? ($kind === 'quote' ? quote_from($r['res']) : close_from($r['res'], $date)) : null;
      if ($v !== null) { $ok[$k] = $v; unset($pos[$k]); continue; }
      if ($i + 1 < count($cands[$k])) { $pos[$k] = $i + 1; continue; }
      $fail[] = "$k (" . ($r['err'] ?? ($kind === 'quote' ? 'no price' : "no close on $date")) . ')';
      unset($pos[$k]);
    }
  }
  return [$ok, $fail];
}
