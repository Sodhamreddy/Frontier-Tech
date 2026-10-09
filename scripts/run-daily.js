// Runs the daily close job once (for cron hosts or testing).
// Flags: --force (run even if the market was closed today), --no-email
require("dotenv").config();
const { runDaily } = require("../lib/jobs");
runDaily({ force: process.argv.includes("--force"), email: !process.argv.includes("--no-email") })
  .then((r) => console.log(JSON.stringify(r, null, 1)))
  .catch((e) => { console.error(e.message); process.exit(1); });
