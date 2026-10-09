// JSON-file store. Mirrors the documents the claude.ai artifact database held:
//   config/main, config/universe, quotes/benchmark, quotes/latest, quotes/live,
//   alerts/state, history/<YYYY-MM>
const fs = require("fs");
const path = require("path");

const DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, "..", "data"));
fs.mkdirSync(path.join(DIR, "history"), { recursive: true });

const file = (name) => path.join(DIR, name + ".json");

function read(name) {
  try {
    return JSON.parse(fs.readFileSync(file(name), "utf8"));
  } catch {
    return null;
  }
}

function write(name, obj) {
  const f = file(name);
  fs.mkdirSync(path.dirname(f), { recursive: true });
  fs.writeFileSync(f + ".tmp", JSON.stringify(obj, null, 1));
  fs.renameSync(f + ".tmp", f);
}

function update(name, patch) {
  const next = { ...(read(name) || {}), ...patch };
  write(name, next);
  return next;
}

function history() {
  const out = {};
  for (const f of fs.readdirSync(path.join(DIR, "history"))) {
    if (f.endsWith(".json")) out[f.slice(0, -5)] = read("history/" + f.slice(0, -5));
  }
  return out;
}

// Changes whenever any document is written, so the page can skip re-rendering.
function stamp() {
  let max = 0;
  const walk = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else if (e.name.endsWith(".json")) max = Math.max(max, fs.statSync(p).mtimeMs);
    }
  };
  walk(DIR);
  return String(max);
}

module.exports = { DIR, read, write, update, history, stamp };
