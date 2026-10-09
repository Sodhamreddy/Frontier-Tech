// Imports the JSON copied by scripts/export-snippet.js from the live claude.ai artifact:
//   npm run import-claude -- claude-export.json
// Writes every document into DATA_DIR and regenerates seed/watchlist.csv from the lists,
// so a fresh deploy seeds the same watchlist.
require("dotenv").config();
const fs = require("fs");
const path = require("path");
const store = require("../lib/store");

const file = process.argv[2];
if (!file) { console.error("Usage: npm run import-claude -- claude-export.json"); process.exit(1); }
const exp = JSON.parse(fs.readFileSync(file, "utf8").replace(/^﻿/, ""));
const docs = exp.docs || {};
const uni = docs["config/universe"];
if (!uni || !uni.stocks || !uni.categories) { console.error("No config/universe in the export — was the console on the artifact frame?"); process.exit(1); }

for (const [p, d] of Object.entries(docs)) {
  if (d == null) continue;
  if (p === "config/main") store.update(p, d);
  else store.write(p, d);
}
for (const [month, d] of Object.entries(exp.history || {})) if (d) store.write("history/" + month, d);

// Regenerate the CSV the server seeds from.
const q = (v) => { const s = String(v ?? ""); return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
const lines = ["family,category,symbol,name,status,currency,note,key,yahoo"];
for (const c of uni.categories) {
  for (const k of c.items) {
    const s = uni.stocks[k] || { symbol: k };
    lines.push([c.family, c.name, s.symbol, s.name, s.status, s.currency, s.note, k !== s.symbol ? k : "", s.yahoo].map(q).join(","));
  }
}
const csv = path.join(__dirname, "..", "seed", "watchlist.csv");
fs.writeFileSync(csv, lines.join("\n") + "\n");

console.log(`Imported ${Object.keys(uni.stocks).length} stocks in ${uni.categories.length} lists, ` +
  `${Object.keys((docs["quotes/benchmark"] || {}).prices || {}).length} benchmark closes, ` +
  `${Object.keys(exp.history || {}).length} history month(s). Wrote ${csv}`);
