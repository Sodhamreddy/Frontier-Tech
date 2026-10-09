// Builds config/universe from a CSV sheet.
// Columns (header row required, order free):
//   family, category, symbol, name, status, currency, note, key, yahoo
// family   : Sectors | Claude picks | GPT picks | My Favorites
// category : list name. For Claude/GPT picks it must contain one of
//            "Overall", "Best Tech", "Safest Bets", "Extreme Profits" (Consensus tab uses this).
// status   : ok (default) | private | noquote — only "ok" rows get prices.
// key      : optional id; defaults to symbol. Same key in several lists = tracked once.
// yahoo    : optional Yahoo Finance symbol when it differs (e.g. BRK-B for BRK.B).
const fs = require("fs");

function parseCsv(text) {
  const rows = [];
  let row = [], cell = "", q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
      else if (c === '"') q = false;
      else cell += c;
    } else if (c === '"') q = true;
    else if (c === ",") { row.push(cell); cell = ""; }
    else if (c === "\n" || c === "\r") {
      if (c === "\r" && text[i + 1] === "\n") i++;
      row.push(cell); cell = "";
      if (row.some((v) => v.trim() !== "")) rows.push(row);
      row = [];
    } else cell += c;
  }
  row.push(cell);
  if (row.some((v) => v.trim() !== "")) rows.push(row);
  return rows;
}

function csvToUniverse(text) {
  const [head, ...body] = parseCsv(text.replace(/^﻿/, ""));
  const idx = Object.fromEntries(head.map((h, i) => [h.trim().toLowerCase(), i]));
  for (const need of ["family", "category", "symbol"]) {
    if (!(need in idx)) throw new Error(`CSV is missing the "${need}" column`);
  }
  const get = (r, k) => (k in idx ? (r[idx[k]] || "").trim() : "");
  const stocks = {};
  const cats = [];
  const catByKey = {};
  for (const r of body) {
    const family = get(r, "family"), category = get(r, "category"), symbol = get(r, "symbol").toUpperCase();
    if (!family || !category || !symbol) continue;
    const key = get(r, "key") || symbol;
    const s = stocks[key] || (stocks[key] = { symbol, status: "ok" });
    const name = get(r, "name"); if (name && !s.name) s.name = name;
    const status = get(r, "status").toLowerCase(); if (status) s.status = status;
    const ccy = get(r, "currency").toUpperCase(); if (ccy) s.currency = ccy;
    const note = get(r, "note"); if (note) s.note = note;
    const y = get(r, "yahoo"); if (y) s.yahoo = y;
    const ck = family + "\u0000" + category;
    let c = catByKey[ck];
    if (!c) { c = catByKey[ck] = { family, name: category, items: [] }; cats.push(c); }
    if (!c.items.includes(key)) c.items.push(key);
  }
  return { stocks, categories: cats, importedAt: new Date().toISOString() };
}

const importFile = (p) => csvToUniverse(fs.readFileSync(p, "utf8"));

module.exports = { csvToUniverse, importFile, parseCsv };
