<?php
// Defaults. Don't edit this file: copy it to config.php (same folder) and change the copy.
// config.php is never overwritten when you upload a new version.
return [
  // Required online: a long random password. The page asks for it once before any edit.
  // Leave empty only for local testing (edits are then allowed from this computer only).
  'admin_key' => '',

  // Lets the hPanel cron job call cron.php over https if CLI cron isn't used. Optional.
  'cron_key' => '',

  // Daily email over SMTP. Gmail: smtp.gmail.com, port 587, your address and a 16-character App Password.
  'smtp_host' => '',
  'smtp_port' => 587,
  'smtp_user' => '',
  'smtp_pass' => '',
  'mail_from' => '',            // e.g. 'Watchlist <you@gmail.com>'; defaults to smtp_user

  // Starting values for the Settings page (the page's saved settings win).
  'mail_to' => '',
  'alert_pct' => -5,
  'spike_pct' => 10,
  'bench_date' => '2026-09-29',

  // Link in the email back to the dashboard.
  'app_url' => '',

  // Where the JSON data lives. The default is the protected data/ folder next to index.html.
  'data_dir' => dirname(__DIR__) . '/data',

  // Daily close, America/Chicago time (20 minutes after the 4 PM ET close).
  'daily_time' => '15:20',

  // How many Yahoo Finance requests run at the same time.
  'fetch_concurrency' => 12,
];
