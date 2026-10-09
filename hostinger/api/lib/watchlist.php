<?php
// Edits to config/universe from the page: add or remove stocks, create, rename or delete
// lists, and put a stock in or out of a list (the Favorites star uses this too).
declare(strict_types=1);

const FTW_FAMILIES = ['Sectors', 'Claude picks', 'GPT picks', 'My Favorites'];

function wl_uni(): array {
  $u = store_read('config/universe') ?? [];
  $u['stocks'] = $u['stocks'] ?? [];
  $u['categories'] = $u['categories'] ?? [];
  return $u;
}
function wl_save(array $u): void { $u['updatedAt'] = gmdate('c'); store_write('config/universe', $u); }
function wl_family($f): string { if (!in_array($f, FTW_FAMILIES, true)) ftw_fail('Unknown list group: ' . $f); return (string)$f; }
function wl_name($n): string {
  $s = trim(preg_replace('/\s+/', ' ', (string)$n) ?? '');
  if ($s === '') ftw_fail('Give the list a name');
  if ((function_exists('mb_strlen') ? mb_strlen($s) : strlen($s)) > 60) ftw_fail('List names can be 60 characters at most');
  return $s;
}
function wl_find(array $u, string $fam, string $name): ?int {
  foreach ($u['categories'] as $i => $c) if ($c['family'] === $fam && $c['name'] === $name) return $i;
  return null;
}
function wl_in_other(array $u, string $k, ?int $except): bool {
  foreach ($u['categories'] as $i => $c) if ($i !== $except && in_array($k, $c['items'], true)) return true;
  return false;
}

function wl_lookup($symbol): array {
  $sym = strtoupper(preg_replace('/\s+/', '', (string)$symbol) ?? '');
  if (!preg_match('/^[A-Z0-9.\-^=]{1,15}$/', $sym)) ftw_fail('Enter a ticker like AAPL or BRK-B');
  $q = quote_one($sym);
  if (!$q) ftw_fail("Couldn't find $sym on Yahoo Finance", 404);
  $existing = null;
  foreach (wl_uni()['stocks'] as $k => $s) if ($k === $sym || ($s['quote'] ?? '') === $sym || ($s['yahoo'] ?? '') === $sym) { $existing = (string)$k; break; }
  return ['symbol' => $sym, 'name' => $q['name'], 'price' => $q['price'], 'currency' => $q['currency'], 'exchange' => $q['exchange'], 'existing' => $existing];
}

function wl_add(array $b): array {
  $info = wl_lookup($b['symbol'] ?? '');
  $u = wl_uni();
  $key = $info['existing'] ?? $info['symbol'];
  if (!isset($u['stocks'][$key])) $u['stocks'][$key] = ['symbol' => $info['symbol'], 'name' => $info['name'] ?: $info['symbol'], 'quote' => $info['symbol'], 'currency' => $info['currency'] ?: 'USD', 'status' => 'ok', 'addedAt' => gmdate('c')];
  foreach (($b['lists'] ?? []) as $l) {
    $i = wl_find($u, wl_family($l['family'] ?? ''), (string)($l['name'] ?? ''));
    if ($i === null) ftw_fail('List "' . ($l['name'] ?? '') . "\" doesn't exist");
    if (!in_array($key, $u['categories'][$i]['items'], true)) $u['categories'][$i]['items'][] = $key;
  }
  if (!wl_in_other($u, $key, null)) ftw_fail('Pick at least one list for the stock');
  wl_save($u);
  $date = settings_get()['benchDate'];
  $bench = store_read('quotes/benchmark') ?? ['date' => $date, 'prices' => []];
  $note = null;
  if (!isset($bench['prices'][$key])) {
    [$ok] = fetch_many([$key => [$info['symbol']]], 'close', $date);
    if (isset($ok[$key])) { $bench['prices'][$key] = $ok[$key]; store_write('quotes/benchmark', $bench); }
    else $note = "No $date close on Yahoo (it may have listed later), so its change will show as —";
  }
  foreach (['quotes/live', 'quotes/latest'] as $doc) {
    $d = store_read($doc);
    if ($d && isset($d['prices']) && !isset($d['prices'][$key])) { $d['prices'][$key] = $info['price']; store_write($doc, $d); }
  }
  return ['key' => $key, 'stock' => $u['stocks'][$key], 'benchNote' => $note];
}

function wl_remove(string $key): array {
  $u = wl_uni();
  if (!isset($u['stocks'][$key])) ftw_fail("That stock isn't in the watchlist", 404);
  unset($u['stocks'][$key]);
  foreach ($u['categories'] as &$c) $c['items'] = array_values(array_filter($c['items'], fn($k) => $k !== $key));
  unset($c);
  wl_save($u);
  return ['removed' => $key];
}

function wl_membership(array $b): array {
  $u = wl_uni();
  $key = (string)($b['key'] ?? ''); $fam = wl_family($b['family'] ?? ''); $name = (string)($b['name'] ?? ''); $on = !empty($b['on']);
  if (!isset($u['stocks'][$key])) ftw_fail("That stock isn't in the watchlist", 404);
  $i = wl_find($u, $fam, $name);
  if ($i === null && $fam === 'My Favorites' && $on) { $u['categories'][] = ['family' => $fam, 'name' => 'My Favorites', 'items' => []]; $i = count($u['categories']) - 1; }
  if ($i === null) ftw_fail("List \"$name\" doesn't exist", 404);
  $items = $u['categories'][$i]['items'];
  if ($on && !in_array($key, $items, true)) $items[] = $key;
  if (!$on) {
    if (in_array($key, $items, true) && !wl_in_other($u, $key, $i)) ftw_fail('This is its only list. Remove the stock instead, or add it to another list first.');
    $items = array_values(array_filter($items, fn($k) => $k !== $key));
  }
  $u['categories'][$i]['items'] = $items;
  wl_save($u);
  return ['key' => $key, 'family' => $fam, 'name' => $u['categories'][$i]['name'], 'on' => $on];
}

function wl_create(array $b): array {
  $u = wl_uni(); $fam = wl_family($b['family'] ?? ''); $n = wl_name($b['name'] ?? '');
  if ($fam === 'My Favorites') foreach ($u['categories'] as $c) if ($c['family'] === $fam) ftw_fail('My Favorites is a single list');
  if (wl_find($u, $fam, $n) !== null) ftw_fail("There's already a list called \"$n\" in $fam");
  $u['categories'][] = ['family' => $fam, 'name' => $n, 'items' => []];
  wl_save($u);
  return ['family' => $fam, 'name' => $n];
}

function wl_rename(array $b): array {
  $u = wl_uni(); $fam = wl_family($b['family'] ?? ''); $name = (string)($b['name'] ?? '');
  $i = wl_find($u, $fam, $name);
  if ($i === null) ftw_fail("List \"$name\" doesn't exist", 404);
  $n = wl_name($b['newName'] ?? '');
  if ($n !== $name && wl_find($u, $fam, $n) !== null) ftw_fail("There's already a list called \"$n\" in $fam");
  $u['categories'][$i]['name'] = $n;
  wl_save($u);
  return ['family' => $fam, 'name' => $n];
}

// Deleting a list never deletes stocks still in another list; stocks left in no list go with it.
function wl_delete(array $b): array {
  $u = wl_uni(); $fam = wl_family($b['family'] ?? ''); $name = (string)($b['name'] ?? '');
  $i = wl_find($u, $fam, $name);
  if ($i === null) ftw_fail("List \"$name\" doesn't exist", 404);
  $items = $u['categories'][$i]['items'];
  array_splice($u['categories'], $i, 1);
  $orphans = array_values(array_filter($items, fn($k) => !wl_in_other($u, $k, null)));
  foreach ($orphans as $k) unset($u['stocks'][$k]);
  wl_save($u);
  return ['family' => $fam, 'name' => $name, 'removedStocks' => $orphans];
}
