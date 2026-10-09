// Copies the current data/ into seed/data/ so a fresh deploy from Git (where data/ is
// ignored) starts with the full watchlist, benchmark closes, history and alerts.
// Email addresses and saved settings are left out, since the repo may be public.
//   npm run seed:snapshot
const fs = require("fs");
const path = require("path");

const root = path.join(__dirname, "..");
const src = path.resolve(process.env.DATA_DIR || path.join(root, "data"));
const dst = path.join(root, "seed", "data");
if (!fs.existsSync(path.join(src, "config", "universe.json"))) { console.error("No watchlist in " + src); process.exit(1); }

const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]));
fs.rmSync(dst, { recursive: true, force: true });
let n = 0;
for (const f of walk(src)) {
  const rel = path.relative(src, f).split(path.sep).join("/");
  if (!rel.endsWith(".json") || rel.startsWith("runtime/") || rel === "config/settings.json") continue;
  const doc = JSON.parse(fs.readFileSync(f, "utf8"));
  if (rel === "config/main.json") { delete doc.alertEmail; delete doc.emailTo; }
  const out = path.join(dst, rel);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, JSON.stringify(doc, null, 1) + "\n");
  n++;
}
const stocks = Object.keys(JSON.parse(fs.readFileSync(path.join(dst, "config", "universe.json"), "utf8")).stocks).length;
console.log(`seed/data/ written: ${n} files, ${stocks} stocks`);
