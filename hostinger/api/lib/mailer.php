<?php
// Daily summary email over SMTP (STARTTLS on 587 or TLS on 465), with no libraries,
// so it works on any PHP host. Gmail needs an App Password.
declare(strict_types=1);

function smtp_configured(): bool {
  $c = ftw_cfg();
  return trim((string)$c['smtp_host']) !== '' && trim((string)$c['smtp_user']) !== '' && (string)$c['smtp_pass'] !== '';
}

function smtp_send(array $to, string $subject, string $html, string $text): void {
  $c = ftw_cfg();
  if (!smtp_configured()) throw new RuntimeException("SMTP isn't set up. Fill in smtp_host, smtp_user and smtp_pass in api/config.php.");
  $port = (int)$c['smtp_port'];
  $host = (string)$c['smtp_host'];
  $from = trim((string)$c['mail_from']) ?: (string)$c['smtp_user'];
  $fromAddr = preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : $from;
  $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
  $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) throw new RuntimeException("Couldn't connect to $host:$port ($errstr)");
  stream_set_timeout($fp, 20);
  $read = function () use ($fp) { $all = ''; while (($l = fgets($fp, 1024)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $all; };
  $cmd = function (string $line, array $ok) use ($fp, $read) {
    if ($line !== '') fwrite($fp, $line . "\r\n");
    $r = $read();
    if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim(preg_replace('/\s+/', ' ', $r) ?: 'no reply'));
    return $r;
  };
  try {
    $cmd('', [220]);
    $me = gethostname() ?: 'localhost';
    $cmd("EHLO $me", [250]);
    if ($port !== 465) {
      $cmd('STARTTLS', [220]);
      if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('SMTP: TLS failed');
      $cmd("EHLO $me", [250]);
    }
    $cmd('AUTH LOGIN', [334]);
    $cmd(base64_encode((string)$c['smtp_user']), [334]);
    try { $cmd(base64_encode((string)$c['smtp_pass']), [235]); }
    catch (RuntimeException $e) { throw new RuntimeException('SMTP login failed: check smtp_user and the App Password in api/config.php'); }
    $cmd("MAIL FROM:<$fromAddr>", [250]);
    foreach ($to as $a) $cmd('RCPT TO:<' . $a . '>', [250, 251]);
    $cmd('DATA', [354]);
    $b = 'b' . bin2hex(random_bytes(8));
    $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $headers = [
      'From: ' . (preg_match('/^(.*)<(.+)>$/', $from, $fm) ? $enc(trim($fm[1])) . " <{$fm[2]}>" : $from),
      'To: ' . implode(', ', $to),
      'Subject: ' . $enc($subject),
      'Date: ' . date('r'),
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($fromAddr, '@') ?: '@watchlist', 1) . '>',
      'MIME-Version: 1.0',
      "Content-Type: multipart/alternative; boundary=\"$b\"",
    ];
    $body = implode("\r\n", $headers) . "\r\n\r\n"
      . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
      . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
      . "--$b--\r\n";
    fwrite($fp, $body . "\r\n.\r\n");
    $cmd('', [250]);
    $cmd('QUIT', [221, 250]);
  } finally {
    fclose($fp);
  }
}

function mail_recipients(): array {
  return array_values(array_filter(array_map('trim', explode(',', (string)settings_get()['mailTo']))));
}

function send_test_email(): void {
  if (!smtp_configured()) throw new RuntimeException("SMTP isn't set up. Fill in smtp_host, smtp_user and smtp_pass in api/config.php.");
  $to = mail_recipients();
  if (!$to) throw new RuntimeException('Enter an email address first');
  smtp_send($to, 'Frontier Tech Watchlist: test email',
    '<div style="font:14px Arial,sans-serif"><h2 style="margin:0 0 8px">Test email works ✓</h2><p>The daily summary from your Frontier Tech Watchlist will arrive at this address after each weekday close.</p></div>',
    "This is a test from your Frontier Tech Watchlist. The daily summary will arrive at this address after each weekday close.");
}

function send_daily_email(array $uni, array $prices, array $bench, array $prevPrices, array $below, array $lastNew, string $marketDate, array $failed): bool {
  $cfg = settings_get();
  $to = mail_recipients();
  if (!smtp_configured() || !$to) return false;
  $spike = (float)$cfg['spikePct'];
  $thrTxt = str_replace('-', '−', (string)$cfg['alertPct']);
  $benchLbl = (new DateTime($cfg['benchDate']))->format('j M');
  $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
  $fmtPct = fn($v) => $v === null ? '—' : ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), 2) . '%';
  $fmtP = fn($v) => $v === null ? '—' : ($v >= 1000 ? number_format($v, 0) : ($v >= 1 ? number_format($v, 2) : number_format($v, 4)));
  $stocks = $uni['stocks'] ?? [];
  $row = function ($k) use ($stocks, $prices, $bench, $prevPrices) {
    $s = $stocks[$k] ?? ['symbol' => $k];
    $p = (!empty($bench[$k]) && isset($prices[$k])) ? ($prices[$k] / $bench[$k] - 1) * 100 : null;
    $d = (!empty($prevPrices[$k]) && isset($prices[$k])) ? ($prices[$k] / $prevPrices[$k] - 1) * 100 : null;
    return ['k' => $k, 'sym' => $s['symbol'] ?? $k, 'name' => $s['name'] ?? '', 'b' => $bench[$k] ?? null, 'c' => $prices[$k] ?? null, 'p' => $p, 'd' => $d];
  };
  $all = array_map($row, array_keys($prices));
  $new = array_flip($lastNew);
  $drops = array_map($row, $below);
  usort($drops, fn($a, $b) => (isset($new[$b['k']]) <=> isset($new[$a['k']])) ?: ($a['p'] <=> $b['p']));
  $spikes = array_values(array_filter($all, fn($r) => $r['p'] !== null && $r['p'] >= $spike));
  usort($spikes, fn($a, $b) => $b['p'] <=> $a['p']);
  $movers = array_values(array_filter($all, fn($r) => $r['d'] !== null));
  usort($movers, fn($a, $b) => abs($b['d']) <=> abs($a['d']));
  $movers = array_slice($movers, 0, 8);
  $vals = array_values(array_filter(array_column($all, 'p'), fn($v) => $v !== null)); sort($vals);
  $n = count($vals); $med = $n ? ($n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2) : null;
  $col = fn($v) => $v === null ? '' : ($v < 0 ? 'color:#b4372b;font-weight:600' : 'color:#1d7a4c;font-weight:600');
  $table = function (array $rows) use ($esc, $fmtP, $fmtPct, $col, $new, $benchLbl) {
    if (!$rows) return '<p style="color:#5b6876;margin:4px 0 0">None.</p>';
    $th = fn($h, $l = false) => '<th style="text-align:' . ($l ? 'left' : 'right') . ';font:600 11px monospace;color:#5b6876;padding:6px 8px;border-bottom:1px solid #d5dbe2">' . $h . '</th>';
    $td = fn($v, $l = false, $st = '') => '<td style="text-align:' . ($l ? 'left' : 'right') . ';padding:6px 8px;border-bottom:1px solid #e6eaef;' . $st . '">' . $v . '</td>';
    $h = '<table style="border-collapse:collapse;font:14px Arial,sans-serif;width:100%;max-width:640px"><thead><tr>' . $th('Ticker', true) . $th('Company', true) . $th($esc($benchLbl)) . $th('Close') . $th('Change') . $th('Today') . '</tr></thead><tbody>';
    foreach ($rows as $r) {
      $tag = isset($new[$r['k']]) ? ' <span style="background:#b4372b;color:#fff;font:600 10px monospace;padding:2px 4px;border-radius:3px">NEW</span>' : '';
      $h .= '<tr>' . $td('<b>' . $esc($r['sym']) . '</b>' . $tag, true) . $td($esc($r['name']), true, 'color:#5b6876') . $td($fmtP($r['b'])) . $td($fmtP($r['c'])) . $td($fmtPct($r['p']), false, $col($r['p'])) . $td($fmtPct($r['d']), false, $col($r['d'])) . '</tr>';
    }
    return $h . '</tbody></table>';
  };
  $dateLabel = (new DateTime($marketDate))->format('D, M j');
  $subject = "Watchlist close $dateLabel: " . count($below) . " at/below $thrTxt%" . ($lastNew ? ' (' . count($lastNew) . ' new)' : '') . ($spikes ? ', ' . count($spikes) . " up {$spike}%+" : '');
  $url = (string)ftw_cfg()['app_url'];
  $h2 = fn($s) => '<h2 style="font:700 17px Arial,sans-serif;margin:24px 0 6px">' . $s . '</h2>';
  $html = '<div style="font:14px Arial,sans-serif;color:#15212e"><h1 style="font:800 22px Arial,sans-serif;margin:0">Frontier Tech Watchlist — close ' . $esc($dateLabel) . '</h1>'
    . '<p style="color:#5b6876;margin:6px 0 0">' . count($all) . ' names priced · median ' . $fmtPct($med) . ' vs the ' . $esc($benchLbl) . ' close' . ($url ? ' · <a href="' . $esc($url) . '">open dashboard</a>' : '') . '</p>'
    . $h2('Big drops: ' . count($below) . " at or below $thrTxt%" . ($lastNew ? ' · ' . count($lastNew) . ' newly crossed' : '')) . $table($drops)
    . $h2('Big spikes: ' . count($spikes) . " up {$spike}% or more") . $table($spikes)
    . $h2('Biggest moves today') . $table($movers)
    . ($failed ? '<p style="color:#9a6a00;margin-top:20px">No price today for: ' . $esc(implode(', ', $failed)) . '</p>' : '') . '</div>';
  $lines = [$subject, '', 'Big drops (' . count($below) . '):'];
  foreach ($drops as $r) $lines[] = '  ' . $r['sym'] . (isset($new[$r['k']]) ? ' [NEW]' : '') . '  ' . $fmtPct($r['p']) . '  (' . $fmtP($r['c']) . ')';
  $lines[] = ''; $lines[] = 'Big spikes (' . count($spikes) . '):';
  foreach ($spikes as $r) $lines[] = '  ' . $r['sym'] . '  ' . $fmtPct($r['p']) . '  (' . $fmtP($r['c']) . ')';
  if ($url) { $lines[] = ''; $lines[] = "Dashboard: $url"; }
  smtp_send($to, $subject, $html, implode("\n", $lines));
  return true;
}
