// The jobs the claude.ai trigger used to run:
//   manual  — Refresh button (and auto-refresh in market hours): fetch live prices, write quotes/live only, no email.
//   daily   — weekdays 3:20 PM CT: record the close in quotes/latest + history,
//             update alerts/state, send the summary email.
const store = require("./store");
const { quote, closeOn, mapLimit } = require("./prices");
const { sendDailyEmail } = require("./mailer");
const settings = require("./settings");

const CONCURRENCY = Number(process.env.FETCH_CONCURRENCY || 6);

const state = { running: false, kind: null, startedAt: null, finishedAt: null, lastError: null, failed: [] };

const nyDate = (ms) => new Intl.DateTimeFormat("en-CA", { timeZone: "America/New_York" }).format(new Date(ms));
// Yahoo symbols to try, in order: explicit `yahoo`, then every clean ticker found in the
// symbol or key ("CCXI (AGLT)" → CCXI, AGLT; "BRK.B" → BRK-B).
function symsFor(s, k) {
  if (s.yahoo) return [s.yahoo];
  const toks = [s.symbol, k].flatMap((v) => String(v || "").split(/[\s()/,]+/));
  const out = [];
  for (let t of toks) {
    if (!/^[A-Z0-9.\-^=]+$/i.test(t)) continue;
    if (/^[A-Z]+\.[A-Z]$/i.test(t)) t = t.replace(".", "-");
    if (!out.includes(t.toUpperCase())) out.push(t.toUpperCase());
  }
  return out.length ? out : [s.symbol || k];
}
async function firstOk(syms, fn) {
  let err;
  for (const sym of syms) {
    try { return await fn(sym); } catch (e) { err = e; }
  }
  throw err;
}

function trackedKeys(uni) {
  return Object.entries((uni && uni.stocks) || {}).filter(([, s]) => (s.status || "ok") === "ok").map(([k]) => k);
}

// Fetches the benchmark close for any tracked stock that doesn't have one yet.
// A new benchmark date in Settings starts the benchmark over, so every close is refetched.
async function ensureBenchmark() {
  const BENCH_DATE = settings.get().benchDate;
  const uni = store.read("config/universe");
  let bench = store.read("quotes/benchmark") || { date: BENCH_DATE, prices: {} };
  if (bench.date && bench.date !== BENCH_DATE) bench = { date: BENCH_DATE, prices: {} };
  bench.prices = bench.prices || {};
  const missing = trackedKeys(uni).filter((k) => bench.prices[k] == null);
  if (!missing.length) return { added: 0, failed: [] };
  const failed = [];
  await mapLimit(missing, CONCURRENCY, async (k) => {
    try {
      bench.prices[k] = await firstOk(symsFor(uni.stocks[k], k), (sym) => closeOn(sym, BENCH_DATE));
    } catch (e) {
      failed.push(`${k} (${e.message})`);
    }
  });
  bench.date = BENCH_DATE;
  bench.updatedAt = new Date().toISOString();
  store.write("quotes/benchmark", bench);
  if (failed.length) console.warn("[bench] no benchmark close for:", failed.join(", "));
  return { added: missing.length - failed.length, failed };
}

async function fetchAll() {
  const uni = store.read("config/universe");
  const keys = trackedKeys(uni);
  if (!keys.length) throw new Error("the watchlist is empty");
  const prices = {}, times = {}, failed = [];
  await mapLimit(keys, CONCURRENCY, async (k) => {
    try {
      const q = await firstOk(symsFor(uni.stocks[k], k), quote);
      prices[k] = q.price;
      times[k] = q.time;
    } catch (e) {
      failed.push(`${k} (${e.message})`);
    }
  });
  if (!Object.keys(prices).length) throw new Error("no prices came back from Yahoo Finance");
  if (failed.length) console.warn("[prices] failed:", failed.join(", "));
  return { uni, prices, times, failed };
}

async function run(kind, fn) {
  if (state.running) {
    const e = new Error(`a ${state.kind} refresh is already running`);
    e.busy = true;
    throw e;
  }
  Object.assign(state, { running: true, kind, startedAt: new Date().toISOString(), lastError: null, failed: [] });
  try {
    await ensureBenchmark().catch((e) => console.warn("[bench]", e.message));
    const out = await fn();
    return out;
  } catch (e) {
    state.lastError = e.message;
    console.error(`[${kind}]`, e);
    throw e;
  } finally {
    state.running = false;
    state.finishedAt = new Date().toISOString();
  }
}

function runManual(kind = "manual") {
  return run(kind, async () => {
    const { prices, failed } = await fetchAll();
    const asOf = new Date().toISOString();
    store.write("quotes/live", { asOf, kind, prices });
    store.update("config/main", { lastRefresh: asOf, lastFailed: failed });
    state.failed = failed;
    console.log(`[${kind}] ${Object.keys(prices).length} live prices, ${failed.length} failed`);
    return { count: Object.keys(prices).length, failed };
  });
}

function runDaily({ force = false, email = true } = {}) {
  return run("daily", async () => {
    const { uni, prices, times, failed } = await fetchAll();
    const now = Date.now();
    const marketDate = nyDate(now);
    // Holiday check: if no quote is stamped today, the market was closed.
    const tradedToday = Object.values(times).some((t) => nyDate(t) === marketDate);
    if (!tradedToday && !force) {
      console.log(`[daily] ${marketDate}: no session today (holiday?), skipped`);
      return { skipped: true, marketDate };
    }
    const asOf = new Date(now).toISOString();
    const prevLatest = store.read("quotes/latest");
    store.write("quotes/latest", { asOf, marketDate, prices });

    const month = marketDate.slice(0, 7);
    const h = store.read("history/" + month) || { days: {} };
    h.days = h.days || {};
    h.days[marketDate] = prices;
    store.write("history/" + month, h);

    const THR = settings.get().alertPct;
    const bench = (store.read("quotes/benchmark") || {}).prices || {};
    const pct = (k) => (bench[k] && prices[k] != null ? (prices[k] / bench[k] - 1) * 100 : null);
    const below = Object.keys(prices).filter((k) => pct(k) != null && pct(k) <= THR);
    // `below` is the claude.ai shape, { KEY: { pct, since } }; older local runs wrote an array.
    const prev = store.read("alerts/state") || {};
    const prevBelow = Array.isArray(prev.below)
      ? Object.fromEntries(prev.below.map((k) => [k, {}]))
      : prev.below || {};
    const lastNew = below.filter((k) => !prevBelow[k]);
    const belowMap = Object.fromEntries(below.map((k) => [k, {
      pct: Math.round(pct(k) * 100) / 100,
      since: (prevBelow[k] && prevBelow[k].since) || marketDate,
    }]));
    const log = (prev.log || []).filter((e) => e.date !== marketDate);
    if (lastNew.length) log.push({ date: marketDate, new: lastNew });
    store.write("alerts/state", { below: belowMap, lastNew, log, thresholdPct: THR, marketDate, updatedAt: asOf });
    store.update("config/main", { lastRefresh: asOf, lastFailed: failed });
    state.failed = failed;

    const prevPrices =
      prevLatest && prevLatest.marketDate !== marketDate ? prevLatest.prices || {} : previousDayPrices(marketDate);
    if (email && settings.get().dailyEmail) {
      try {
        await sendDailyEmail({ uni, prices, bench, prevPrices, below, lastNew, marketDate, failed });
      } catch (e) {
        console.error("[mail]", e.message);
        store.update("config/main", { lastEmailError: e.message });
      }
    }
    console.log(`[daily] ${marketDate}: ${Object.keys(prices).length} closes, ${below.length} below line (${lastNew.length} new)`);
    return { marketDate, count: Object.keys(prices).length, below: below.length, lastNew, failed };
  });
}

function previousDayPrices(marketDate) {
  const hist = store.history();
  const days = [];
  for (const m of Object.keys(hist).sort()) for (const d of Object.keys((hist[m] && hist[m].days) || {})) days.push([d, hist[m].days[d]]);
  const before = days.filter(([d]) => d < marketDate).sort((a, b) => a[0].localeCompare(b[0]));
  return before.length ? before[before.length - 1][1] : {};
}

module.exports = { state, runManual, runDaily, ensureBenchmark, symsFor, firstOk, nyDate };
