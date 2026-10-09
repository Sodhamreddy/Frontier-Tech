// Fetches the benchmark close for any tracked stock that doesn't have one yet.
require("dotenv").config();
const { ensureBenchmark } = require("../lib/jobs");
const settings = require("../lib/settings");
ensureBenchmark().then((r) => {
  console.log(`${settings.get().benchDate}: fetched ${r.added}` + (r.failed.length ? `; missing: ${r.failed.join(", ")}` : ""));
});
