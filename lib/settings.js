// User-editable settings, kept in config/settings. .env values are only the defaults,
// so anything changed on the Settings page wins over .env.
const store = require("./store");

const INTERVALS = [5, 10, 15, 30, 60];

function defaults() {
  return {
    alertPct: Number(process.env.ALERT_PCT || -5),
    spikePct: Number(process.env.SPIKE_PCT || 10),
    benchDate: process.env.BENCH_DATE || "2026-09-29",
    mailTo: process.env.MAIL_TO || "",
    dailyEmail: true,
    autoRefresh: true,
    autoRefreshMin: 15,
  };
}

function get() {
  return { ...defaults(), ...(store.read("config/settings") || {}) };
}

const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s) && !isNaN(Date.parse(s + "T00:00:00Z"));
const isEmails = (s) => s.split(",").map((x) => x.trim()).filter(Boolean).every((x) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x));

// Validates a partial update; throws with a message the page can show next to the form.
function set(patch) {
  const out = {};
  if ("alertPct" in patch) {
    const v = Number(patch.alertPct);
    if (!isFinite(v) || v > -0.5 || v < -50) throw new Error("Alert line must be between −50% and −0.5%");
    out.alertPct = Math.round(v * 10) / 10;
  }
  if ("spikePct" in patch) {
    const v = Number(patch.spikePct);
    if (!isFinite(v) || v < 1 || v > 200) throw new Error("Spike threshold must be between 1% and 200%");
    out.spikePct = Math.round(v * 10) / 10;
  }
  if ("benchDate" in patch) {
    const d = String(patch.benchDate || "");
    if (!isDate(d)) throw new Error("Benchmark date must be a valid date");
    const day = new Date(d + "T12:00:00Z").getUTCDay();
    if (day === 0 || day === 6) throw new Error("Benchmark date must be a weekday (a trading day)");
    if (Date.parse(d + "T00:00:00Z") > Date.now()) throw new Error("Benchmark date can't be in the future");
    out.benchDate = d;
  }
  if ("mailTo" in patch) {
    const m = String(patch.mailTo || "").trim();
    if (m && !isEmails(m)) throw new Error("Enter a valid email address (separate several with commas)");
    out.mailTo = m;
  }
  if ("dailyEmail" in patch) out.dailyEmail = !!patch.dailyEmail;
  if ("autoRefresh" in patch) out.autoRefresh = !!patch.autoRefresh;
  if ("autoRefreshMin" in patch) {
    const v = Number(patch.autoRefreshMin);
    if (!INTERVALS.includes(v)) throw new Error("Auto-refresh interval must be one of " + INTERVALS.join(", ") + " minutes");
    out.autoRefreshMin = v;
  }
  store.write("config/settings", { ...(store.read("config/settings") || {}), ...out });
  return get();
}

module.exports = { get, set, INTERVALS };
