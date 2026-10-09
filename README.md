# Frontier Tech Watchlist

A standalone, self-hosted version of the claude.ai "Frontier Tech Watchlist" artifact. The page (`public/index.html`) is the original dashboard. The parts that depended on claude.ai now run on a small Node server:

| Original (claude.ai) | Here |
|---|---|
| Artifact database (`config/*`, `quotes/*`, `alerts/state`, `history/*`) | JSON files in `DATA_DIR`, served at `GET /api/state` (the page polls it) |
| "Refresh prices now" → Claude Code Remote trigger (manual mode) | `POST /api/refresh` fetches live prices and writes `quotes/live` only, with no email |
| Weekday 3:20 PM CT scheduled run | `node-cron` in the server: records the close in `quotes/latest` and `history/`, updates `alerts/state` (NEW = newly crossed), and sends the email |
| Daily summary email | Sent over SMTP (nodemailer): big drops at or below −5% (new ones first), big spikes of +10% or more, and the day's biggest moves |
| stockanalysis.com prices | Yahoo Finance chart API (no key needed). The 29 Sep benchmark is also the Yahoo regular-session close, as before |

Every tab and control is unchanged: Sectors with sector chips, Consensus (tiers, heat grid, strip chart, "picked by" filter), Claude picks, GPT picks, My Favorites, search, the "Only at or below −5%" filter, column sorting, sparklines, the alert line with NEW chips, the summary tiles, dark mode, and the remembered UI state.

## 1. Your watchlist data

### Copy everything from the live claude.ai page (recommended)

This copies the lists, notes, 29 Sep closes, latest and live prices, NEW flags and price history.

1. Open the live artifact on claude.ai in Chrome or Edge and wait for the prices to load.
2. Press **F12** and open the **Console** tab. In the context dropdown at the top-left of the console (it says `top`), pick the **artifact's frame**, the entry under claude.ai that isn't `top`.
3. Paste the contents of `scripts/export-snippet.js` and press Enter. It reports `Copied 125 stocks…`, and the JSON is now on your clipboard.
4. Paste it into a new file called `claude-export.json` in this folder, then run:

```bash
npm run import-claude -- claude-export.json
```

The importer also rewrites `seed/watchlist.csv` with your real lists, so a fresh deploy seeds the same 125 stocks. On a server that already has data, copy `claude-export.json` over and run the same command there, or delete the data folder so the server reseeds from the CSV.

### Or build the lists from a CSV

Export your sheet as a CSV with these columns:

```
family,category,symbol,name,status,currency,note,key,yahoo
```

- `family` must be one of `Sectors`, `Claude picks`, `GPT picks` or `My Favorites`.
- `category` is the list name. For Claude and GPT picks the name **must contain** `Overall`, `Best Tech`, `Safest Bets` or `Extreme Profits`, because the Consensus tab matches on those words.
- `status` is blank or `ok` for tracked stocks. Use `private` or `noquote` for names you want listed but not priced (these show greyed out with the `note`).
- `key` is optional and defaults to the symbol. A stock that appears in several lists is tracked once.
- `yahoo` is optional. Set it only when Yahoo's symbol differs, e.g. `BRK-B`.

`seed/watchlist.csv` is a **sample**. Replace it with your real list before the first start, or import it later:

```bash
npm run import -- path/to/watchlist.csv
```

Benchmark closes for 29 Sep 2026 are fetched automatically for any stock that doesn't have one yet.

## 2. Run locally

```bash
npm install
cp .env.example .env      # fill in SMTP + MAIL_TO + ADMIN_KEY
npm start                 # http://localhost:3000
```

Useful commands:

```bash
npm run daily -- --no-email     # record today's close now, without emailing
npm run daily -- --force        # run even if the market was closed today
npm run bench                   # fetch any missing 29 Sep closes
```

## Editing from the page

- **Settings** (sidebar): alert line, spike threshold, benchmark date, auto-refresh on/off and interval, the daily email address, "Send test email" and "Record today's close now". Saved in `DATA_DIR/config/settings.json`; `.env` values are only the starting defaults.
- **Auto-refresh**: while the US market is open (9:30 AM–4:05 PM ET, weekdays) the server fetches live prices every N minutes and the page updates by itself.
- **Watchlist**: the star on any row adds it to My Favorites; **Add stock** checks the ticker on Yahoo and fetches its benchmark close; a stock's panel has list checkboxes and **Remove**; **Manage lists** creates, renames and deletes lists.
- **Who can edit**: with no `ADMIN_KEY`, only the computer running the server. When hosted, set `ADMIN_KEY`; the page asks for it once and remembers it in that browser.

## 3. Deploy

The server needs to **stay running**, because the daily job runs inside it, and it needs a **persistent disk** for `DATA_DIR`, because that is where history and alerts are kept.

**Docker / any VPS**
```bash
docker build -t watchlist .
docker run -d --restart=always -p 3000:3000 -v watchlist-data:/data --env-file .env watchlist
```

**Render**: push this folder to a Git repo and create a Blueprint from `render.yaml`. It sets up a Docker web service with a 1 GB disk at `/data`. Then fill in the SMTP and `MAIL_TO` values in the dashboard.

**Railway / Fly.io**: deploy the Dockerfile, attach a volume mounted at `/data`, and set the env vars from `.env.example`.

Serverless hosts with no disk (Vercel, Netlify) won't work as-is.

**Shared PHP hosting (Hostinger Business etc.)**: `hostinger/` is a PHP version of the server with the same API, so the same page works on plain PHP hosting with no Node app.

1. `npm run build:dist` builds `dist/` and `frontier-watchlist-dist.zip`: the page, `api/` (PHP), `.htaccess` routing, and your current data as `data-initial/` (copied into `data/` on first run only, so uploading a new version never overwrites live data).
2. Upload the zip's contents to `public_html` and extract.
3. Copy `api/config.sample.php` to `api/config.php` and fill in `admin_key`, the SMTP settings and `app_url`. `config.php` is never part of the build, so later uploads keep it.
4. In hPanel → Advanced → Cron Jobs, run every 5 minutes: `/usr/bin/php /home/USER/domains/DOMAIN/public_html/api/cron.php`. It does the auto-refresh in market hours and the daily close after 3:20 PM CT.

Data, settings and `config.php` are blocked from the web by `.htaccess`. To test locally, copy `hostinger/dev-router.php` into `dist/`, then run `php -S localhost:8080 dev-router.php` inside `dist/`.

## 4. Email setup

**Why this needs a mail account:** on claude.ai the daily email is sent by a Claude scheduled routine, a Claude agent running in the artifact owner's account with its own email access. That routine isn't part of this code and keeps running on its own; it reads and writes the claude.ai copy of the data, not this server. A self-hosted app has to send its own email, and that takes a mail account.

- **Keep receiving the claude.ai emails:** leave SMTP blank. This server then never sends email, so you won't get duplicates. Those emails stop if the owner turns the routine off.
- **Have this server send them** (it doesn't depend on claude.ai): fill in the SMTP settings below. Ask the routine's owner to pause it, or you'll get two emails a day.

Any SMTP provider works. With Gmail, turn on 2-Step Verification, create an **App password**, and use `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=587`, your address as `SMTP_USER` and the app password as `SMTP_PASS`. If SMTP isn't configured, the daily job still runs; it just skips the email.

## 5. Admin endpoints

Send the header `x-admin-key: <ADMIN_KEY>` with each request.

```bash
# Run the daily job now (add ?email=0 to skip the email, ?force=1 on a holiday)
curl -X POST -H "x-admin-key: $ADMIN_KEY" https://your-domain/api/admin/run-daily

# Replace the watchlist without redeploying
curl -X POST -H "x-admin-key: $ADMIN_KEY" -H "Content-Type: text/csv" \
     --data-binary @watchlist.csv https://your-domain/api/admin/watchlist
```

## Notes

- The Refresh button is public but rate-limited (one run at a time, a 60 s cooldown). It never sends email.
- If no quote is stamped with today's date (a market holiday), the daily job skips that day.
- The alert line, spike threshold, benchmark date, auto-refresh and email address are changed on the Settings page; `.env` only supplies the starting values. The page text follows the saved settings.
