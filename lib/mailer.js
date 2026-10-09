// Daily summary email: big drops (at/below the alert line, new ones first) and big spikes
// against the benchmark close, plus the day's biggest movers.
const nodemailer = require("nodemailer");
const settings = require("./settings");

const esc = (s) => String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
const fmtPct = (v) => (v == null ? "—" : (v > 0 ? "+" : v < 0 ? "−" : "") + Math.abs(v).toFixed(2) + "%");
const fmtP = (v) => (v == null ? "—" : v >= 1000 ? v.toLocaleString("en-US", { maximumFractionDigits: 0 }) : v >= 1 ? v.toFixed(2) : v.toFixed(4));

// No SMTP_USER means email is off (.env ships with SMTP_HOST filled in but no credentials).
function transport() {
  if (!process.env.SMTP_HOST || !process.env.SMTP_USER) return null;
  const port = Number(process.env.SMTP_PORT || 587);
  return nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port,
    secure: process.env.SMTP_SECURE ? process.env.SMTP_SECURE === "true" : port === 465,
    auth: process.env.SMTP_USER ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS } : undefined,
  });
}

function table(rows, cols) {
  if (!rows.length) return '<p style="color:#5b6876;margin:4px 0 0">None.</p>';
  const th = cols.map((c) => `<th style="text-align:${c.l ? "left" : "right"};font:600 11px monospace;color:#5b6876;padding:6px 8px;border-bottom:1px solid #d5dbe2">${c.h}</th>`).join("");
  const tr = rows.map((r) => `<tr>${cols.map((c) => `<td style="text-align:${c.l ? "left" : "right"};padding:6px 8px;border-bottom:1px solid #e6eaef;${c.style ? c.style(r) : ""}">${c.v(r)}</td>`).join("")}</tr>`).join("");
  return `<table style="border-collapse:collapse;font:14px Arial,sans-serif;width:100%;max-width:640px"><thead><tr>${th}</tr></thead><tbody>${tr}</tbody></table>`;
}

async function sendDailyEmail({ uni, prices, bench, prevPrices, below, lastNew, marketDate, failed }) {
  const cfg = settings.get();
  const to = cfg.mailTo, SPIKE = cfg.spikePct, THR_TXT = String(cfg.alertPct).replace("-", "−");
  const benchLbl = new Date(cfg.benchDate + "T12:00:00Z").toLocaleDateString("en-US", { day: "numeric", month: "short", timeZone: "UTC" });
  const t = transport();
  if (!t || !to) {
    console.log("[mail] SMTP_HOST, SMTP_USER or the email address not set — skipping the daily email");
    return false;
  }
  const stocks = uni.stocks || {};
  const row = (k) => {
    const s = stocks[k] || { symbol: k };
    const p = bench[k] && prices[k] != null ? (prices[k] / bench[k] - 1) * 100 : null;
    const d = prevPrices[k] && prices[k] != null ? (prices[k] / prevPrices[k] - 1) * 100 : null;
    return { k, sym: s.symbol, name: s.name || "", b: bench[k], c: prices[k], p, d };
  };
  const all = Object.keys(prices).map(row);
  const newSet = new Set(lastNew);
  const drops = below.map(row).sort((a, b) => (newSet.has(b.k) - newSet.has(a.k)) || a.p - b.p);
  const spikes = all.filter((r) => r.p != null && r.p >= SPIKE).sort((a, b) => b.p - a.p);
  const movers = all.filter((r) => r.d != null).sort((a, b) => Math.abs(b.d) - Math.abs(a.d)).slice(0, 8);
  const vals = all.map((r) => r.p).filter((v) => v != null).sort((a, b) => a - b);
  const med = vals.length ? (vals.length % 2 ? vals[(vals.length - 1) / 2] : (vals[vals.length / 2 - 1] + vals[vals.length / 2]) / 2) : null;

  const red = "color:#b4372b;font-weight:600", green = "color:#1d7a4c;font-weight:600";
  const col = (v) => (v == null ? "" : v < 0 ? red : green);
  const cols = [
    { h: "Ticker", l: true, v: (r) => `<b>${esc(r.sym)}</b>${newSet.has(r.k) ? ' <span style="background:#b4372b;color:#fff;font:600 10px monospace;padding:2px 4px;border-radius:3px">NEW</span>' : ""}` },
    { h: "Company", l: true, v: (r) => esc(r.name), style: () => "color:#5b6876" },
    { h: benchLbl, v: (r) => fmtP(r.b) },
    { h: "Close", v: (r) => fmtP(r.c) },
    { h: "Change", v: (r) => fmtPct(r.p), style: (r) => col(r.p) },
    { h: "Today", v: (r) => fmtPct(r.d), style: (r) => col(r.d) },
  ];
  const dateLabel = new Date(marketDate + "T12:00:00Z").toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric", timeZone: "UTC" });
  const subject = `Watchlist close ${dateLabel}: ${below.length} at/below ${THR_TXT}%` + (lastNew.length ? ` (${lastNew.length} new)` : "") + (spikes.length ? `, ${spikes.length} up ${SPIKE}%+` : "");
  const url = process.env.APP_URL;
  const h2 = (s) => `<h2 style="font:700 17px Arial,sans-serif;margin:24px 0 6px">${s}</h2>`;
  const html = `<div style="font:14px Arial,sans-serif;color:#15212e">
    <h1 style="font:800 22px Arial,sans-serif;margin:0">Frontier Tech Watchlist — close ${esc(dateLabel)}</h1>
    <p style="color:#5b6876;margin:6px 0 0">${all.length} names priced · median ${fmtPct(med)} vs the ${esc(benchLbl)} close${url ? ` · <a href="${esc(url)}">open dashboard</a>` : ""}</p>
    ${h2(`Big drops: ${below.length} at or below ${THR_TXT}%` + (lastNew.length ? ` · ${lastNew.length} newly crossed` : ""))}
    ${table(drops, cols)}
    ${h2(`Big spikes: ${spikes.length} up ${SPIKE}% or more`)}
    ${table(spikes, cols)}
    ${h2("Biggest moves today")}
    ${table(movers, cols)}
    ${failed.length ? `<p style="color:#9a6a00;margin-top:20px">No price today for: ${esc(failed.join(", "))}</p>` : ""}
  </div>`;
  const text = [
    subject, "",
    `Big drops (${below.length}):`, ...drops.map((r) => `  ${r.sym}${newSet.has(r.k) ? " [NEW]" : ""}  ${fmtPct(r.p)}  (${fmtP(r.c)})`), "",
    `Big spikes (${spikes.length}):`, ...spikes.map((r) => `  ${r.sym}  ${fmtPct(r.p)}  (${fmtP(r.c)})`), "",
    url ? `Dashboard: ${url}` : "",
  ].join("\n");
  await t.sendMail({ from: process.env.MAIL_FROM || process.env.SMTP_USER, to, subject, html, text });
  console.log(`[mail] sent to ${to}: ${subject}`);
  return true;
}

const smtpConfigured = () => !!transport();

// Sends a short message so the Settings page can confirm SMTP works before the next close.
async function sendTestEmail(to) {
  const t = transport();
  if (!t) throw new Error("SMTP isn't set up. Add SMTP_HOST, SMTP_USER and SMTP_PASS to .env and restart.");
  if (!to) throw new Error("Enter an email address first");
  const info = await t.sendMail({
    from: process.env.MAIL_FROM || process.env.SMTP_USER, to,
    subject: "Frontier Tech Watchlist: test email",
    text: "This is a test from your Frontier Tech Watchlist. The daily summary will arrive at this address after each weekday close.",
    html: '<div style="font:14px Arial,sans-serif"><h2 style="margin:0 0 8px">Test email works ✓</h2><p>The daily summary from your Frontier Tech Watchlist will arrive at this address after each weekday close.</p></div>',
  });
  return info.messageId;
}

module.exports = { sendDailyEmail, sendTestEmail, smtpConfigured };
