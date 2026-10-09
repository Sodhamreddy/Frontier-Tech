// Usage: npm run import -- path/to/watchlist.csv
require("dotenv").config();
const store = require("../lib/store");
const { importFile } = require("../lib/universe");
const { ensureBenchmark } = require("../lib/jobs");

const file = process.argv[2];
if (!file) { console.error("Usage: npm run import -- path/to/watchlist.csv"); process.exit(1); }
const uni = importFile(file);
store.write("config/universe", uni);
console.log(`Imported ${Object.keys(uni.stocks).length} stocks in ${uni.categories.length} lists.`);
ensureBenchmark().then((r) => {
  console.log(`Benchmark closes fetched: ${r.added}` + (r.failed.length ? `; missing: ${r.failed.join(", ")}` : ""));
});
