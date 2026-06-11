<?php
/* ───────────────────────────────────────────────────────────────
   SAMPLE config. Copy to a location OUTSIDE the web root, e.g.:
     /home3/hevycom/monobank_config.php
   set the real token, and DO NOT commit it to git.
   The PHP endpoints load it via absolute path (see api/_mono.php).
   If you must keep it inside public_html, block it with .htaccess deny.
─────────────────────────────────────────────────────────────── */
define('MONOBANK_TOKEN', 'REPLACE_WITH_YOUR_MONOBANK_TOKEN');

// Optional: where order records are written (default: mono_orders/ next to
// this file, outside the web root). Must be writable by the PHP user.
// define('MONO_DATA_DIR', '/home3/hevycom/mono_orders');
