<?php
// JSON-file store, same documents as the Node server: config/main, config/universe,
// config/settings, quotes/*, alerts/state, history/<YYYY-MM>, runtime/jobs.
declare(strict_types=1);

function ftw_cfg(): array {
  static $cfg = null;
  if ($cfg === null) {
    $base = require __DIR__ . '/../config.sample.php';
    $own = is_file(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];
    $cfg = array_merge($base, is_array($own) ? $own : []);
  }
  return $cfg;
}

function ftw_dir(): string {
  static $dir = null;
  if ($dir === null) {
    $dir = rtrim((string)ftw_cfg()['data_dir'], '/\\');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    // First run: copy the bundled data-initial/ in, so the imported watchlist carries over.
    $seed = dirname(__DIR__, 2) . '/data-initial';
    if (!is_file("$dir/config/universe.json") && is_file("$seed/config/universe.json")) ftw_copy_dir($seed, $dir);
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
  }
  return $dir;
}

function ftw_copy_dir(string $from, string $to): void {
  if (!is_dir($to)) mkdir($to, 0775, true);
  foreach (scandir($from) as $f) {
    if ($f === '.' || $f === '..') continue;
    is_dir("$from/$f") ? ftw_copy_dir("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
  }
}

function store_read(string $name): ?array {
  $f = ftw_dir() . "/$name.json";
  if (!is_file($f)) return null;
  $j = json_decode((string)file_get_contents($f), true);
  return is_array($j) ? $j : null;
}

// PHP decodes {} and [] to the same empty array; these keys are always objects in the page.
function ftw_norm($v, ?string $key = null) {
  static $maps = ['prices' => 1, 'stocks' => 1, 'below' => 1, 'days' => 1, 'pick' => 1];
  if (!is_array($v)) return $v;
  if ($v === []) return isset($maps[(string)$key]) ? new stdClass() : [];
  $out = [];
  foreach ($v as $k => $x) $out[$k] = ftw_norm($x, is_string($k) ? $k : $key);
  return $out;
}

function ftw_json($v): string {
  return json_encode(ftw_norm($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function store_write(string $name, array $obj): void {
  $f = ftw_dir() . "/$name.json";
  if (!is_dir(dirname($f))) mkdir(dirname($f), 0775, true);
  $tmp = $f . '.' . getmypid() . '.tmp';
  file_put_contents($tmp, ftw_json($obj));
  rename($tmp, $f);
}

function store_update(string $name, array $patch): array {
  $next = array_merge(store_read($name) ?? [], $patch);
  store_write($name, $next);
  return $next;
}

function store_history(): array {
  $out = [];
  foreach (glob(ftw_dir() . '/history/*.json') ?: [] as $f) $out[basename($f, '.json')] = store_read('history/' . basename($f, '.json'));
  ksort($out);
  return $out;
}

// Changes whenever any document is written, so the page can skip re-rendering.
function store_stamp(): string {
  $max = 0;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ftw_dir(), FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -5) === '.json' && strpos($p, 'runtime') === false) $max = max($max, $f->getMTime());
  }
  clearstatcache();
  return (string)$max;
}
