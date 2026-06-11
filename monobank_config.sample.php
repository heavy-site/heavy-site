<?php
/* ───────────────────────────────────────────────────────────────
   SAMPLE config. Copy to a location OUTSIDE the web root, e.g.:
     /home3/hevycom/monobank_config.php
   set the real token, and DO NOT commit it to git.
   The PHP endpoints load it via absolute path (see api/_mono.php).
   If you must keep it inside public_html, block it with .htaccess deny.
─────────────────────────────────────────────────────────────── */
define('MONOBANK_TOKEN', 'REPLACE_WITH_YOUR_MONOBANK_TOKEN');

// ── Ticket emails (Resend) ───────────────────────────────────
// API key from resend.com (SECRET, server-only).
define('RESEND_API_KEY', 'REPLACE_WITH_YOUR_RESEND_API_KEY');
// FROM address. onboarding@resend.dev works immediately but only delivers to
// YOUR OWN Resend account email. For real buyers, verify he4vy.com in Resend
// (DNS records) then use e.g. 'HEAVY <tickets@he4vy.com>'.
define('MAIL_FROM', 'HEAVY <onboarding@resend.dev>');

// ── Ticket signing (HMAC) ────────────────────────────────────
// If unset, a random key is auto-generated + persisted in MONO_DATA_DIR.
// Set explicitly to keep tickets verifiable across data-dir changes:
//   openssl rand -hex 32
// define('TICKET_SECRET', 'REPLACE_WITH_A_LONG_RANDOM_SECRET');

// Optional: where order/ticket records are written (default: mono_orders/ next
// to this file, outside the web root). Must be writable by the PHP user.
// define('MONO_DATA_DIR', '/home3/hevycom/mono_orders');
