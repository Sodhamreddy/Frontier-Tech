// Paste into the browser DevTools console while the live claude.ai artifact is open.
// IMPORTANT: first switch the console's context dropdown (top-left, says "top") to the
// artifact's frame, otherwise window.claude is undefined.
// It reads every database document and hands you the JSON three ways (the artifact frame
// blocks DevTools' copy(), so it doesn't rely on it): a claude-export.json download,
// the clipboard, and a printed string with Chrome's "Copy" button. Save it as
// claude-export.json and run:  npm run import-claude -- claude-export.json
(async () => {
  const db = await window.claude.use("db");
  const doc = (p) => new Promise((ok, bad) => {
    const stop = db.doc(p).onSnapshot((s) => { ok(s.exists ? s.data() : null); stop && stop(); }, bad);
  });
  const col = (p) => new Promise((ok, bad) => {
    const stop = db.collection(p).onSnapshot((qs) => {
      const o = {}; qs.docs.forEach((d) => (o[d.id] = d.data())); ok(o); stop && stop();
    }, bad);
  });
  const out = { exportedAt: new Date().toISOString(), docs: {} };
  for (const p of ["config/main", "config/universe", "quotes/benchmark", "quotes/latest", "quotes/live", "alerts/state"]) {
    out.docs[p] = await doc(p);
  }
  out.history = await col("history");
  const n = Object.keys(out.docs["config/universe"]?.stocks || {}).length;
  const json = JSON.stringify(out);
  window.ftwExport = json;

  try {
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([json], { type: "application/json" }));
    a.download = "claude-export.json";
    document.body.appendChild(a); a.click(); a.remove();
  } catch (e) {}
  let clip = false;
  try { await navigator.clipboard.writeText(json); clip = true; } catch (e) {}

  console.log(`Read ${n} stocks, ${Object.keys(out.history).length} history month(s), ${json.length.toLocaleString()} characters.` +
    (clip ? " Copied to the clipboard." : " Clipboard blocked here.") +
    " If no claude-export.json download appeared, click Copy on the long string below and paste it into claude-export.json.");
  console.log(json);
})();
