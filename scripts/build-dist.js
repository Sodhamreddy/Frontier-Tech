// Builds dist/ for PHP hosting (Hostinger Business etc.): the dashboard page, the PHP API,
// the .htaccess routing, and your current data as data-initial/ (copied into data/ on first
// run only, so uploading a new version never overwrites live data). Also zips it.
//   npm run build:dist
const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const root = path.join(__dirname, "..");
const dist = path.join(root, "dist");
const src = path.join(root, "hostinger");
// Your live data/ when building locally; the seed/data snapshot when building from Git.
const data = [process.env.DATA_DIR, path.join(root, "data"), path.join(root, "seed", "data")]
  .filter(Boolean).map((d) => path.resolve(d)).find((d) => fs.existsSync(path.join(d, "config", "universe.json")));
if (!data) { console.error("No watchlist data found (data/ or seed/data/)."); process.exit(1); }

fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist);
for (const f of ["index.html", "classic.html"]) fs.copyFileSync(path.join(root, "public", f), path.join(dist, f));
fs.copyFileSync(path.join(src, ".htaccess"), path.join(dist, ".htaccess"));
// config.php holds your secrets on the server; never ship one, only the sample.
fs.cpSync(path.join(src, "api"), path.join(dist, "api"), { recursive: true, filter: (p) => path.basename(p) !== "config.php" });
fs.cpSync(data, path.join(dist, "data-initial"), {
  recursive: true,
  filter: (p) => !/[\\/](runtime|\.jobs\.lock)([\\/]|$)/.test(p) && !p.endsWith(".tmp"),
});
fs.writeFileSync(path.join(dist, "data-initial", ".htaccess"), "Require all denied\nDeny from all\n");

const zip = path.join(root, "frontier-watchlist-dist.zip");
fs.rmSync(zip, { force: true });
// Windows' bsdtar writes .zip with -a; Git's GNU tar on PATH would read "C:" as a remote host.
const tar = process.platform === "win32" ? path.join(process.env.SystemRoot || "C:\\Windows", "System32", "tar.exe") : "tar";
if (process.platform === "win32") execFileSync(tar, ["-a", "-c", "-f", zip, "-C", dist, "."]);
else execFileSync("zip", ["-qr", zip, "."], { cwd: dist });

const stocks = Object.keys(JSON.parse(fs.readFileSync(path.join(dist, "data-initial", "config", "universe.json"), "utf8")).stocks).length;
console.log(`dist/ built with ${stocks} stocks of data → ${path.relative(root, zip)}`);
