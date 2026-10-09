// Price source: Yahoo Finance chart endpoint (no API key needed).
const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36";
const HOSTS = ["query1.finance.yahoo.com", "query2.finance.yahoo.com"];

async function chart(symbol, params) {
  let lastErr;
  for (const host of HOSTS) {
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const url = `https://${host}/v8/finance/chart/${encodeURIComponent(symbol)}?${new URLSearchParams(params)}`;
        const r = await fetch(url, { headers: { "User-Agent": UA, Accept: "application/json" }, signal: AbortSignal.timeout(15000) });
        if (r.status === 404) throw Object.assign(new Error("symbol not found"), { fatal: true });
        if (!r.ok) throw new Error("HTTP " + r.status);
        const j = await r.json();
        const res = j.chart && j.chart.result && j.chart.result[0];
        if (!res) throw Object.assign(new Error((j.chart && j.chart.error && j.chart.error.description) || "no data"), { fatal: true });
        return res;
      } catch (e) {
        lastErr = e;
        if (e.fatal) throw e;
        await new Promise((ok) => setTimeout(ok, 600 * (attempt + 1)));
      }
    }
  }
  throw lastErr;
}

// Latest price (live during market hours, the close after it).
async function quote(symbol) {
  const res = await chart(symbol, { range: "5d", interval: "1d" });
  const m = res.meta;
  if (m.regularMarketPrice == null) throw new Error("no price");
  return {
    price: m.regularMarketPrice, time: m.regularMarketTime * 1000, currency: m.currency,
    symbol: m.symbol, name: m.longName || m.shortName || null, exchange: m.fullExchangeName || m.exchangeName || null,
  };
}

// Regular-session close on a given exchange-local date (YYYY-MM-DD).
async function closeOn(symbol, date) {
  const t = Date.parse(date + "T00:00:00Z") / 1000;
  const res = await chart(symbol, { period1: t - 3 * 86400, period2: t + 3 * 86400, interval: "1d" });
  const ts = res.timestamp || [];
  const closes = (res.indicators && res.indicators.quote && res.indicators.quote[0] && res.indicators.quote[0].close) || [];
  const off = res.meta.gmtoffset || 0;
  for (let i = 0; i < ts.length; i++) {
    const d = new Date((ts[i] + off) * 1000).toISOString().slice(0, 10);
    if (d === date && closes[i] != null) return closes[i];
  }
  throw new Error("no close on " + date);
}

async function mapLimit(items, n, fn) {
  const out = new Array(items.length);
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(n, items.length) }, async () => {
    while (next < items.length) {
      const i = next++;
      out[i] = await fn(items[i], i);
    }
  }));
  return out;
}

module.exports = { quote, closeOn, mapLimit };
