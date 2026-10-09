// Edits to config/universe made from the page: add or remove stocks, create, rename or
// delete lists, and put a stock in or out of a list (the Favorites star uses this too).
const store = require("./store");
const settings = require("./settings");
const { quote, closeOn } = require("./prices");

const FAMILIES = ["Sectors", "Claude picks", "GPT picks", "My Favorites"];

function err(msg, status = 400) { return Object.assign(new Error(msg), { status }); }
function uni() {
  const u = store.read("config/universe") || { stocks: {}, categories: [] };
  u.stocks = u.stocks || {}; u.categories = u.categories || [];
  return u;
}
function save(u) { u.updatedAt = new Date().toISOString(); store.write("config/universe", u); return u; }
function family(f) { if (!FAMILIES.includes(f)) throw err("Unknown list group: " + f); return f; }
function listName(n) {
  const s = String(n || "").trim().replace(/\s+/g, " ");
  if (!s) throw err("Give the list a name");
  if (s.length > 60) throw err("List names can be 60 characters at most");
  return s;
}
function findList(u, fam, name) { return u.categories.find((c) => c.family === fam && c.name === name); }
const cleanSymbol = (s) => String(s || "").trim().toUpperCase().replace(/\s+/g, "");

// Checks a ticker on Yahoo and returns what the Add dialog previews.
async function lookup(symbol) {
  const sym = cleanSymbol(symbol);
  if (!/^[A-Z0-9.\-^=]{1,15}$/.test(sym)) throw err("Enter a ticker like AAPL or BRK-B");
  let q;
  try { q = await quote(sym); } catch (e) { throw err(`Couldn't find ${sym} on Yahoo Finance`, 404); }
  const u = uni();
  const key = Object.keys(u.stocks).find((k) => k === sym || u.stocks[k].quote === sym || u.stocks[k].yahoo === sym) || null;
  return { symbol: sym, name: q.name, price: q.price, currency: q.currency, exchange: q.exchange, existing: key };
}

async function addStock({ symbol, lists = [] }) {
  const info = await lookup(symbol);
  const u = uni();
  const key = info.existing || info.symbol;
  if (!u.stocks[key]) {
    u.stocks[key] = { symbol: info.symbol, name: info.name || info.symbol, quote: info.symbol, currency: info.currency || "USD", status: "ok", addedAt: new Date().toISOString() };
  }
  for (const l of lists) {
    const c = findList(u, family(l.family), l.name);
    if (!c) throw err(`List "${l.name}" doesn't exist`);
    if (!c.items.includes(key)) c.items.push(key);
  }
  if (!u.categories.some((c) => c.items.includes(key))) throw err("Pick at least one list for the stock");
  save(u);

  // Benchmark close and a current price, so the row shows a change right away.
  const benchDate = settings.get().benchDate;
  const bench = store.read("quotes/benchmark") || { date: benchDate, prices: {} };
  bench.prices = bench.prices || {};
  let benchNote = null;
  if (bench.prices[key] == null) {
    try { bench.prices[key] = await closeOn(info.symbol, benchDate); store.write("quotes/benchmark", bench); }
    catch (e) { benchNote = `No ${benchDate} close on Yahoo (it may have listed later), so its change will show as —`; }
  }
  for (const doc of ["quotes/live", "quotes/latest"]) {
    const d = store.read(doc);
    if (d && d.prices && d.prices[key] == null) { d.prices[key] = info.price; store.write(doc, d); }
  }
  return { key, stock: u.stocks[key], benchNote };
}

function removeStock(key) {
  const u = uni();
  if (!u.stocks[key]) throw err("That stock isn't in the watchlist", 404);
  delete u.stocks[key];
  for (const c of u.categories) c.items = c.items.filter((k) => k !== key);
  save(u);
  return { removed: key };
}

function setMembership({ key, family: f, name, on }) {
  const u = uni();
  if (!u.stocks[key]) throw err("That stock isn't in the watchlist", 404);
  let c = findList(u, family(f), name);
  if (!c && f === "My Favorites" && on) { c = { family: f, name: "My Favorites", items: [] }; u.categories.push(c); }
  if (!c) throw err(`List "${name}" doesn't exist`, 404);
  if (on && !c.items.includes(key)) c.items.push(key);
  if (!on) {
    const others = u.categories.filter((x) => x !== c && x.items.includes(key));
    if (!others.length && c.items.includes(key)) throw err("This is its only list. Remove the stock instead, or add it to another list first.");
    c.items = c.items.filter((k) => k !== key);
  }
  save(u);
  return { key, family: f, name: c.name, on: !!on };
}

function createList({ family: f, name }) {
  const u = uni();
  const n = listName(name);
  family(f);
  if (f === "My Favorites" && u.categories.some((c) => c.family === f)) throw err("My Favorites is a single list");
  if (findList(u, f, n)) throw err(`There's already a list called "${n}" in ${f}`);
  u.categories.push({ family: f, name: n, items: [] });
  save(u);
  return { family: f, name: n };
}

function renameList({ family: f, name, newName }) {
  const u = uni();
  const c = findList(u, family(f), name);
  if (!c) throw err(`List "${name}" doesn't exist`, 404);
  const n = listName(newName);
  if (n !== name && findList(u, f, n)) throw err(`There's already a list called "${n}" in ${f}`);
  c.name = n;
  save(u);
  return { family: f, name: n };
}

// Deleting a list never deletes stocks that are still in another list; stocks left in
// no list at all are removed from the watchlist with it.
function deleteList({ family: f, name }) {
  const u = uni();
  const c = findList(u, family(f), name);
  if (!c) throw err(`List "${name}" doesn't exist`, 404);
  u.categories = u.categories.filter((x) => x !== c);
  const orphans = c.items.filter((k) => !u.categories.some((x) => x.items.includes(k)));
  for (const k of orphans) delete u.stocks[k];
  save(u);
  return { family: f, name, removedStocks: orphans };
}

module.exports = { FAMILIES, lookup, addStock, removeStock, setMembership, createList, renameList, deleteList };
