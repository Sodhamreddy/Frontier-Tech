require("dotenv").config();
const path = require("path");
const fs = require("fs");
const express = require("express");
const cron = require("node-cron");
const store = require("./lib/store");
const jobs = require("./lib/jobs");
const settings = require("./lib/settings");
const watchlist = require("./lib/watchlist");
const { sendTestEmail, smtpConfigured } = require("./lib/mailer");
const { importFile } = require("./lib/universe");

const PORT = Number(process.env.PORT || 3000);
const ADMIN_KEY = process.env.ADMIN_KEY || "";
const REFRESH_COOLDOWN_MS = Number(process.env.REFRESH_COOLDOWN_SEC || 60) * 1000;
const DAILY_CRON = process.env.DAILY_CRON || "20 15 * * 1-5";

// First start with an empty DATA_DIR: copy in bundled data, so the watchlist, history and
// alerts carry over. ./data comes with a zip upload; seed/data is the snapshot kept in Git.
if (!store.read("config/universe")) {
  const bundled = [path.join(__dirname, "data"), path.join(__dirname, "seed", "data")]
    .find((d) => path.resolve(store.DIR) !== d && fs.existsSync(path.join(d, "config", "universe.json")));
  if (bundled) {
    fs.cpSync(bundled, store.DIR, { recursive: true });
    console.log("[seed] copied", path.relative(__dirname, bundled), "into", store.DIR);
  }
}
// Otherwise seed the watchlist from the CSV the first time the server starts.
if (!store.read("config/universe")) {
  const csv = path.resolve(process.env.WATCHLIST_CSV || path.join(__dirname, "seed", "watchlist.csv"));
  if (fs.existsSync(csv)) {
    store.write("config/universe", importFile(csv));
    console.log("[seed] watchlist imported from", csv);
  } else console.warn("[seed] no watchlist found at", csv);
}

const app = express();
app.disable("x-powered-by");
// Private dashboard: keep it out of search engines.
app.use((req, res, next) => { res.set("X-Robots-Tag", "noindex, nofollow, noarchive"); next(); });
app.use(express.json({ limit: "2mb" }));

// Who may edit: with ADMIN_KEY set, anyone sending it as x-admin-key; without one, only
// requests made on this computer (loopback, not forwarded by a proxy).
function canEdit(req) {
  if (ADMIN_KEY) return req.get("x-admin-key") === ADMIN_KEY;
  const ip = req.socket.remoteAddress || "";
  return !req.get("x-forwarded-for") && (ip === "127.0.0.1" || ip === "::1" || ip === "::ffff:127.0.0.1");
}
const editor = (req, res, next) => {
  if (!canEdit(req)) return res.status(401).json({ error: ADMIN_KEY ? "Enter the admin key to make changes" : "Changes can only be made on the computer running the server" });
  next();
};
// Wraps async handlers so thrown errors become JSON with the right status.
const h = (fn) => async (req, res) => {
  try { res.json(await fn(req, res)); }
  catch (e) { res.status(e.status || (e.busy ? 409 : 400)).json({ error: e.message }); }
};

// ---------- auto-refresh: live prices on a timer while the US market is open ----------
const auto = { lastAt: null, nextAt: null, lastError: null };
function nyParts(ms = Date.now()) {
  const p = Object.fromEntries(new Intl.DateTimeFormat("en-US", { timeZone: "America/New_York", weekday: "short", hour: "2-digit", minute: "2-digit", hour12: false })
    .formatToParts(new Date(ms)).map((x) => [x.type, x.value]));
  return { day: p.weekday, mins: (Number(p.hour) % 24) * 60 + Number(p.minute) };
}
function marketOpen(ms) {
  const { day, mins } = nyParts(ms);
  return !["Sat", "Sun"].includes(day) && mins >= 9 * 60 + 30 && mins < 16 * 60 + 5;
}
function autoTick() {
  const cfg = settings.get();
  if (!cfg.autoRefresh || !marketOpen()) { auto.nextAt = null; return; }
  const every = cfg.autoRefreshMin * 60000;
  const last = Date.parse(store.read("quotes/live")?.asOf || 0) || 0;
  const due = Math.max(last + every, Date.now());
  auto.nextAt = new Date(due).toISOString();
  if (Date.now() >= last + every && !jobs.state.running) {
    auto.lastAt = new Date().toISOString();
    jobs.runManual("auto").then(() => { auto.lastError = null; }).catch((e) => { auto.lastError = e.message; });
  }
}
setInterval(autoTick, 30000);

function publicSettings(req) {
  return { ...settings.get(), intervals: settings.INTERVALS, families: watchlist.FAMILIES, smtpConfigured: smtpConfigured(), canEdit: canEdit(req), needsKey: !!ADMIN_KEY, dailyCron: DAILY_CRON };
}

// ---------- read ----------
app.get("/api/state", (req, res) => {
  res.set("Cache-Control", "no-store");
  const { running, kind, startedAt, finishedAt, lastError } = jobs.state;
  const cfg = settings.get();
  res.json({
    stamp: store.stamp(),
    main: { ...store.read("config/main"), emailTo: cfg.mailTo || null },
    uni: store.read("config/universe"),
    bench: store.read("quotes/benchmark"),
    latest: store.read("quotes/latest"),
    live: store.read("quotes/live"),
    alerts: store.read("alerts/state"),
    hist: store.history(),
    settings: publicSettings(req),
    refresh: { running, kind, startedAt, finishedAt, lastError },
    auto: { enabled: cfg.autoRefresh, everyMin: cfg.autoRefreshMin, marketOpen: marketOpen(), nextAt: auto.nextAt, lastError: auto.lastError },
  });
});

// Refresh button. Public, so it is rate-limited and only ever writes quotes/live.
let lastManual = 0;
app.post("/api/refresh", (req, res) => {
  if (jobs.state.running) return res.status(409).json({ error: "a refresh is already running" });
  if (Date.now() - lastManual < REFRESH_COOLDOWN_MS) return res.status(409).json({ error: "refreshed moments ago" });
  lastManual = Date.now();
  jobs.runManual().catch(() => {});
  res.status(202).json({ started: true });
});

// ---------- settings ----------
app.get("/api/settings", (req, res) => res.json(publicSettings(req)));
app.put("/api/settings", editor, h(async (req) => {
  const before = settings.get();
  settings.set(req.body || {});
  // A new benchmark date refetches every benchmark close in the background.
  if (settings.get().benchDate !== before.benchDate) jobs.ensureBenchmark().catch((e) => console.warn("[bench]", e.message));
  return publicSettings(req);
}));
app.post("/api/settings/test-email", editor, h(async (req) => {
  await sendTestEmail(String(req.body?.to || settings.get().mailTo || "").trim());
  return { sent: true };
}));

// ---------- watchlist editing ----------
app.get("/api/lookup", editor, h((req) => watchlist.lookup(req.query.symbol)));
app.post("/api/stocks", editor, h((req) => watchlist.addStock(req.body || {})));
app.delete("/api/stocks/:key", editor, h((req) => watchlist.removeStock(req.params.key)));
app.post("/api/lists", editor, h((req) => watchlist.createList(req.body || {})));
app.patch("/api/lists", editor, h((req) => watchlist.renameList(req.body || {})));
app.delete("/api/lists", editor, h((req) => watchlist.deleteList(req.body || {})));
app.post("/api/lists/membership", editor, h((req) => watchlist.setMembership(req.body || {})));

// Run the daily close job now. ?force=1 runs even on a market holiday, ?email=0 skips the email.
app.post("/api/admin/run-daily", editor, h((req) => jobs.runDaily({ force: req.query.force === "1", email: req.query.email !== "0" })));
// Replace the watchlist: POST the CSV text as the body with Content-Type: text/csv.
app.post("/api/admin/watchlist", editor, express.text({ type: ["text/csv", "text/plain"], limit: "2mb" }), h(async (req) => {
  const { csvToUniverse } = require("./lib/universe");
  const uni = csvToUniverse(req.body || "");
  store.write("config/universe", uni);
  const bench = await jobs.ensureBenchmark();
  return { stocks: Object.keys(uni.stocks).length, lists: uni.categories.length, benchmark: bench };
}));

app.get("/favicon.ico", (req, res) => res.type("image/png").sendFile(path.join(__dirname, "public", "favicon-32.png")));
app.get("/healthz", (req, res) => res.json({ ok: true }));
// HTML is revalidated on every load so a redesigned page shows up without a hard refresh.
app.use(express.static(path.join(__dirname, "public"), {
  extensions: ["html"],
  setHeaders: (res, file) => { if (file.endsWith(".html")) res.set("Cache-Control", "no-cache"); },
}));

// Daily close: weekdays 3:20 PM Central (20 minutes after the 4 PM ET close).
cron.schedule(DAILY_CRON, () => jobs.runDaily().catch(() => {}), { timezone: "America/Chicago" });

app.listen(PORT, () => {
  console.log(`Frontier Tech Watchlist on http://localhost:${PORT}  (data: ${store.DIR}, daily job "${DAILY_CRON}" America/Chicago)`);
  jobs.ensureBenchmark()
    .then((r) => r.added && console.log(`[bench] fetched ${r.added} benchmark closes for ${settings.get().benchDate}`))
    .catch((e) => console.warn("[bench]", e.message));
  autoTick();
});
